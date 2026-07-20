<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use YellowTwins\Snapshot\Configuration\ConfigurationLoader;
use YellowTwins\Snapshot\Configuration\EnvironmentConfig;
use YellowTwins\Snapshot\Database\DatabaseConnectionResolver;
use YellowTwins\Snapshot\Exception\SnapshotException;
use YellowTwins\Snapshot\FileSource\FileSourceInterface;
use YellowTwins\Snapshot\Scrubbing\ScrubbingService;
use YellowTwins\Snapshot\Service\DatabaseDumpService;
use YellowTwins\Snapshot\Service\PostPullHookRunner;
use YellowTwins\Snapshot\Transport\TransportInterface;
use YellowTwins\Snapshot\Util\ByteFormatter;

/**
 * Pulls the database and/or fileadmin from a configured environment onto the local machine.
 */
#[AsCommand(name: 'snapshot:pull', description: 'Pull database and fileadmin from an environment to your local machine')]
final class PullCommand extends Command
{
    public function __construct(
        private readonly ConfigurationLoader $configurationLoader,
        private readonly TransportInterface $transport,
        private readonly FileSourceInterface $fileSource,
        private readonly DatabaseConnectionResolver $connectionResolver,
        private readonly DatabaseDumpService $databaseDumpService,
        private readonly ScrubbingService $scrubbingService,
        private readonly PostPullHookRunner $postPullHookRunner,
        private readonly ByteFormatter $byteFormatter,
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('from', 'f', InputOption::VALUE_REQUIRED, 'Source environment name (as defined in .snapshot.yaml)')
            ->addOption('db', null, InputOption::VALUE_NONE, 'Pull the database only')
            ->addOption('files', null, InputOption::VALUE_NONE, 'Pull the fileadmin only')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would happen without changing anything')
            ->addOption('no-scrub', null, InputOption::VALUE_NONE, 'Skip GDPR anonymization of the imported database (not recommended)')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $from = $input->getOption('from');
        if (!is_string($from) || $from === '') {
            $io->error('Please provide a source environment with --from=<name>.');

            return Command::INVALID;
        }

        $projectRoot = Environment::getProjectPath();
        $configuration = $this->configurationLoader->load($projectRoot);
        $environment = $configuration->getEnvironment($from);

        if (!$this->transport->supports($environment) || !$this->fileSource->supports($environment)) {
            $io->error(sprintf('Environment "%s" uses a transport or file source that is not supported.', $from));

            return Command::FAILURE;
        }

        [$pullDatabase, $pullFiles] = $this->resolveScope($input);
        $dryRun = (bool)$input->getOption('dry-run');

        $io->title(sprintf('Snapshot pull from "%s" (%s)', $from, $this->transport->describe($environment)));
        if ($dryRun) {
            $io->note('Dry run — nothing will be written locally.');
        }

        $yes = (bool)$input->getOption('yes');
        if ($dryRun || !$yes) {
            $this->showPreview($io, $environment, $pullDatabase, $pullFiles, $configuration->defaults->rsyncExcludes);
        }

        if (!$dryRun && !$yes) {
            $io->warning($this->overwriteWarning($pullDatabase, $pullFiles));
            if (!$io->confirm('Continue?', false)) {
                $io->writeln('Aborted.');

                return Command::SUCCESS;
            }
        }

        $scrub = $configuration->defaults->scrub && !(bool)$input->getOption('no-scrub');

        if ($pullDatabase) {
            $this->pullDatabase($io, $environment, $configuration->defaults->dbExclude, $dryRun);
        }

        if ($pullFiles) {
            $this->pullFiles($io, $environment, $configuration->defaults->rsyncExcludes, $dryRun);
        }

        if (!$dryRun && $pullDatabase) {
            $this->scrubDatabase($io, $configuration->defaults->scrubRules, $scrub);
        }

        if (!$dryRun && $configuration->defaults->postPull !== []) {
            $this->runPostPullHooks($io, $configuration->defaults->postPull, $pullDatabase);
        }

        $io->success($dryRun ? 'Dry run complete.' : 'Pull complete.');

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $rsyncExcludes
     */
    private function showPreview(SymfonyStyle $io, EnvironmentConfig $environment, bool $pullDatabase, bool $pullFiles, array $rsyncExcludes): void
    {
        $io->section('Preview');
        if ($pullDatabase) {
            $bytes = $this->estimateDatabaseBytes($environment);
            $io->writeln('  Database:  ' . ($bytes !== null ? '~' . $this->byteFormatter->format($bytes) : 'size unknown (no database credentials for a size query)'));
        }
        if ($pullFiles) {
            $bytes = $this->fileSource->estimateBytes($environment, $rsyncExcludes);
            $io->writeln('  Fileadmin: ' . ($bytes !== null ? '~' . $this->byteFormatter->format($bytes) : 'size unknown'));
        }
    }

    private function estimateDatabaseBytes(EnvironmentConfig $environment): ?int
    {
        try {
            $connection = $this->connectionResolver->resolveRemote($environment, $this->transport);
        } catch (SnapshotException) {
            return null;
        }

        return $this->databaseDumpService->remoteDatabaseBytes($environment, $connection);
    }

    /**
     * @param array<string, \YellowTwins\Snapshot\Scrubbing\ScrubRule> $overrides
     */
    private function scrubDatabase(SymfonyStyle $io, array $overrides, bool $scrub): void
    {
        $io->section('Scrubbing');
        if (!$scrub) {
            $io->warning('Anonymization skipped (--no-scrub): the local database still contains personal data from the source.');

            return;
        }

        /** @var ProgressBar|null $progressBar */
        $progressBar = null;
        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $this->scrubbingService->scrub(
            $connection,
            $overrides,
            static function (string $message) use (&$progressBar): void {
                $progressBar?->setMessage($message);
            },
            function (int $done, int $total) use ($io, &$progressBar): void {
                if ($total === 0) {
                    return;
                }
                $progressBar ??= $this->createStepBar($io, $total, 'Anonymizing');
                $progressBar->setProgress($done);
            },
        );

        if ($progressBar !== null) {
            $progressBar->finish();
            $io->newLine(2);
        }
        $io->writeln('<info>Anonymization complete.</info>');
    }

    /**
     * @param list<string> $hooks
     */
    private function runPostPullHooks(SymfonyStyle $io, array $hooks, bool $databaseWasPulled): void
    {
        $io->section('Post-pull hooks');
        $this->postPullHookRunner->run($hooks, static function (string $message) use ($io): void {
            $io->writeln('  ' . $message);
        }, $databaseWasPulled);
    }

    /**
     * @return array{0: bool, 1: bool}
     */
    private function resolveScope(InputInterface $input): array
    {
        $db = (bool)$input->getOption('db');
        $files = (bool)$input->getOption('files');
        if (!$db && !$files) {
            return [true, true];
        }

        return [$db, $files];
    }

    private function overwriteWarning(bool $db, bool $files): string
    {
        $parts = [];
        if ($db) {
            $parts[] = 'replace your local database';
        }
        if ($files) {
            // rsync runs without --delete: remote files overwrite matching local ones and new
            // files are added, but local-only files are kept (not a destructive full mirror).
            $parts[] = 'sync files into your local fileadmin (remote overwrites matching files; local-only files are kept)';
        }

        return 'This will ' . implode(' and ', $parts) . '.';
    }

    /**
     * @param list<string> $excludePatterns
     */
    private function pullDatabase(SymfonyStyle $io, EnvironmentConfig $environment, array $excludePatterns, bool $dryRun): void
    {
        $io->section('Database');
        $useConsole = $this->databaseDumpService->remoteHasTypo3Console($environment);

        if ($dryRun) {
            if ($useConsole) {
                $io->writeln('Would dump the remote database via typo3_console (vendor/bin/typo3 database:export).');
            } else {
                $remote = $this->connectionResolver->resolveRemote($environment, $this->transport);
                $io->writeln(sprintf('Would dump remote database "%s" via mysqldump and import it locally.', $remote->dbname));
            }
            if ($excludePatterns !== []) {
                $io->writeln('Excluded tables: ' . implode(', ', $excludePatterns));
            }

            return;
        }

        $dumpFile = $this->createTempFile();
        try {
            if ($useConsole) {
                $dumpBar = $this->createByteBar($io, 'Dumping database (typo3_console)');
                $this->databaseDumpService->dumpRemoteViaConsole($environment, $excludePatterns, $dumpFile, $this->reportBytes($dumpBar));
            } else {
                $remote = $this->connectionResolver->resolveRemote($environment, $this->transport);
                $dumpBar = $this->createByteBar($io, 'Dumping database (mysqldump)');
                $this->databaseDumpService->dumpRemoteToFile($environment, $remote, $excludePatterns, $dumpFile, $this->reportBytes($dumpBar));
            }
            $this->finishBar($io, $dumpBar);

            $importBar = $this->createPercentBar($io, 'Importing database  ');
            $this->databaseDumpService->importLocalViaConsole($dumpFile, static function (int $percent) use ($importBar): void {
                $importBar->setProgress($percent);
            });
            $this->finishBar($io, $importBar);
        } finally {
            @unlink($dumpFile);
        }
        $io->writeln('<info>Database imported.</info>');
    }

    /**
     * @param list<string> $excludes
     */
    private function pullFiles(SymfonyStyle $io, EnvironmentConfig $environment, array $excludes, bool $dryRun): void
    {
        $io->section('Fileadmin');
        $localFileadmin = Environment::getPublicPath() . '/fileadmin';

        // A live percentage is only available for a real transfer, not a dry run.
        $progressBar = $dryRun ? null : $this->createFileProgressBar($io);

        $result = $this->fileSource->pullFileadmin(
            $environment,
            $localFileadmin,
            $excludes,
            $dryRun,
            $progressBar === null ? null : static function (int $percent) use ($progressBar): void {
                $progressBar->setProgress($percent);
            },
        );

        if ($progressBar !== null) {
            if ($result->isSuccessful()) {
                $progressBar->finish();
            } else {
                $progressBar->clear();
            }
            $io->newLine(2);
        }

        if (!$result->isSuccessful()) {
            $io->error('Fileadmin sync failed: ' . trim($result->stderr));

            return;
        }
        $io->writeln('<info>Fileadmin synced.</info>');
    }

    private function createFileProgressBar(SymfonyStyle $io): ProgressBar
    {
        return $this->createPercentBar($io, 'Transferring fileadmin');
    }

    /**
     * A determinate 0-100% bar (elapsed time, no throughput). Used for transfers whose total is known.
     */
    private function createPercentBar(SymfonyStyle $io, string $label): ProgressBar
    {
        $progressBar = $io->createProgressBar(100);
        $progressBar->setFormat(' ' . $label . '  %percent:3s%%  [%bar%]  %elapsed:6s%');
        $progressBar->start();

        return $progressBar;
    }

    /**
     * An indeterminate bar that shows a running, human-readable byte count. Used for the database
     * dump, whose final SQL size is not known up front (so a percentage would be misleading).
     */
    private function createByteBar(SymfonyStyle $io, string $label): ProgressBar
    {
        $progressBar = $io->createProgressBar();
        $progressBar->setFormat(' ' . $label . '  %message%  (%elapsed:6s%)');
        $progressBar->setMessage('0 B');
        $progressBar->start();

        return $progressBar;
    }

    /**
     * A determinate bar over a known number of steps, with the current item shown as the message.
     */
    private function createStepBar(SymfonyStyle $io, int $max, string $label): ProgressBar
    {
        $progressBar = $io->createProgressBar($max);
        $progressBar->setFormat(' ' . $label . '  %current%/%max%  [%bar%]  %message%');
        $progressBar->setMessage('');
        $progressBar->start();

        return $progressBar;
    }

    /**
     * @return callable(int): void Feeds a running byte count into a byte bar as the dump streams in.
     */
    private function reportBytes(ProgressBar $progressBar): callable
    {
        return function (int $bytes) use ($progressBar): void {
            $progressBar->setMessage($this->byteFormatter->format($bytes));
            // Advance the internal step so the (throttled) redraw picks up the new message.
            $progressBar->setProgress($bytes);
        };
    }

    private function finishBar(SymfonyStyle $io, ProgressBar $progressBar): void
    {
        $progressBar->finish();
        $io->newLine(2);
    }

    private function createTempFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'snapshot-db-');
        if ($file === false) {
            throw new \RuntimeException('Unable to create a temporary dump file.', 1_752_900_600);
        }

        return $file;
    }
}
