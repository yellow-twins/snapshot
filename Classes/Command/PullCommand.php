<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;
use YellowTwins\Snapshot\Configuration\ConfigurationLoader;
use YellowTwins\Snapshot\Configuration\EnvironmentConfig;
use YellowTwins\Snapshot\Database\DatabaseConnectionResolver;
use YellowTwins\Snapshot\FileSource\FileSourceInterface;
use YellowTwins\Snapshot\Service\DatabaseDumpService;
use YellowTwins\Snapshot\Transport\TransportInterface;

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

        if (!$dryRun && !(bool)$input->getOption('yes')) {
            $io->warning('This overwrites your local ' . $this->scopeLabel($pullDatabase, $pullFiles) . '.');
            if (!$io->confirm('Continue?', false)) {
                $io->writeln('Aborted.');

                return Command::SUCCESS;
            }
        }

        if ($pullDatabase) {
            $this->pullDatabase($io, $environment, $configuration->defaults->dbExclude, $dryRun);
        }

        if ($pullFiles) {
            $this->pullFiles($io, $environment, $configuration->defaults->rsyncExcludes, $dryRun);
        }

        $io->success($dryRun ? 'Dry run complete.' : 'Pull complete.');
        if (!$dryRun && $configuration->defaults->postPull !== []) {
            $io->note('Post-pull hooks are not implemented yet (planned for the next milestone): ' . implode(', ', $configuration->defaults->postPull));
        }

        return Command::SUCCESS;
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

    private function scopeLabel(bool $db, bool $files): string
    {
        return match (true) {
            $db && $files => 'database and fileadmin',
            $db => 'database',
            default => 'fileadmin',
        };
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
                $io->writeln('Dumping remote database via typo3_console…');
                $this->databaseDumpService->dumpRemoteViaConsole($environment, $excludePatterns, $dumpFile);
            } else {
                $remote = $this->connectionResolver->resolveRemote($environment, $this->transport);
                $io->writeln('Dumping remote database via mysqldump…');
                $this->databaseDumpService->dumpRemoteToFile($environment, $remote, $excludePatterns, $dumpFile);
            }
            $io->writeln('Importing into local database via typo3_console…');
            $this->databaseDumpService->importLocalViaConsole($dumpFile);
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
        $result = $this->fileSource->pullFileadmin(
            $environment,
            $localFileadmin,
            $excludes,
            $dryRun,
            static function (string $chunk) use ($io): void {
                $io->write($chunk);
            },
        );

        if (!$result->isSuccessful()) {
            $io->error('Fileadmin sync failed: ' . trim($result->stderr));

            return;
        }
        $io->writeln('<info>Fileadmin synced.</info>');
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
