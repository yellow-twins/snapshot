<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\ExecutableFinder;
use TYPO3\CMS\Core\Core\Environment;
use YellowTwins\Snapshot\Configuration\ConfigurationLoader;
use YellowTwins\Snapshot\Configuration\EnvironmentConfig;
use YellowTwins\Snapshot\Database\DatabaseConnectionResolver;
use YellowTwins\Snapshot\Exception\SnapshotException;
use YellowTwins\Snapshot\Service\DatabaseDumpService;
use YellowTwins\Snapshot\Transport\TransportInterface;

/**
 * Preflight checks: local tooling, SSH reachability, and remote prerequisites per environment.
 */
#[AsCommand(name: 'snapshot:doctor', description: 'Check local tools and remote reachability before a pull')]
final class DoctorCommand extends Command
{
    public function __construct(
        private readonly ConfigurationLoader $configurationLoader,
        private readonly TransportInterface $transport,
        private readonly DatabaseConnectionResolver $connectionResolver,
        private readonly DatabaseDumpService $databaseDumpService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('from', 'f', InputOption::VALUE_REQUIRED, 'Check only this environment (default: all)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $configuration = $this->configurationLoader->load(Environment::getProjectPath());

        $ok = true;

        $io->section('Local tools');
        $finder = new ExecutableFinder();
        foreach (['ssh', 'rsync', 'mysql'] as $tool) {
            $found = $finder->find($tool) !== null;
            $ok = $ok && $found;
            $io->writeln($this->line($found, $tool));
        }

        $from = $input->getOption('from');
        $environments = is_string($from) && $from !== ''
            ? [$configuration->getEnvironment($from)]
            : array_values($configuration->environments);

        foreach ($environments as $environment) {
            $io->section(sprintf('Environment "%s" (%s)', $environment->name, $this->transport->describe($environment)));
            $ok = $this->checkEnvironment($io, $environment) && $ok;
        }

        if ($ok) {
            $io->success('All checks passed.');

            return Command::SUCCESS;
        }

        $io->error('Some checks failed. Fix the items marked with a cross above before pulling.');

        return Command::FAILURE;
    }

    private function checkEnvironment(SymfonyStyle $io, EnvironmentConfig $environment): bool
    {
        $reachable = $this->transport->run($environment, 'echo snapshot-ok', null, 15);
        $sshOk = $reachable->isSuccessful() && str_contains($reachable->stdout, 'snapshot-ok');
        $io->writeln($this->line($sshOk, 'SSH connection'));
        if (!$sshOk) {
            $io->writeln('    ' . trim($reachable->stderr));

            return false;
        }

        $settingsOk = $this->remoteTest($environment, sprintf('test -f %s', escapeshellarg($environment->remoteSettingsFile())));
        $io->writeln($this->line($settingsOk, 'Remote settings.php (' . $environment->remoteSettingsFile() . ')'));

        $fileadminOk = $this->remoteTest($environment, sprintf('test -d %s', escapeshellarg($environment->remoteFileadminPath())));
        $io->writeln($this->line($fileadminOk, 'Remote fileadmin (' . $environment->remoteFileadminPath() . ')'));

        $mysqldumpOk = $this->remoteTest($environment, 'command -v mysqldump >/dev/null');
        $io->writeln($this->line($mysqldumpOk, 'Remote mysqldump'));

        $databaseOk = $this->checkDatabase($io, $environment);

        return $settingsOk && $fileadminOk && $mysqldumpOk && $databaseOk;
    }

    private function checkDatabase(SymfonyStyle $io, EnvironmentConfig $environment): bool
    {
        if ($this->databaseDumpService->remoteHasTypo3Console($environment)) {
            $io->writeln($this->line(true, 'Remote typo3_console database:export (TYPO3 resolves credentials itself)'));

            return true;
        }

        try {
            $connection = $this->connectionResolver->resolveRemote($environment, $this->transport);
        } catch (SnapshotException $e) {
            $io->writeln($this->line(false, 'Remote database credentials'));
            $io->writeln('    ' . $e->getMessage());
            $io->writeln('    Tip: add an explicit "db:" block to this environment in .snapshot.yaml.');

            return false;
        }

        $source = $environment->database !== null ? '.snapshot.yaml' : 'remote settings.php';
        $check = $this->databaseDumpService->remoteConnectionCheck($environment, $connection);
        $connectionOk = $check->isSuccessful();
        $io->writeln($this->line($connectionOk, sprintf('Remote database "%s" reachable (from %s)', $connection->dbname, $source)));
        if (!$connectionOk) {
            $io->writeln('    ' . trim($check->stderr));
            $io->writeln('    Tip: the DB host may be container-internal; add an explicit "db:" block to .snapshot.yaml.');
        }

        return $connectionOk;
    }

    private function remoteTest(EnvironmentConfig $environment, string $command): bool
    {
        $result = $this->transport->run($environment, $command . ' && echo yes', null, 20);

        return $result->isSuccessful() && str_contains($result->stdout, 'yes');
    }

    private function line(bool $ok, string $label): string
    {
        return $ok ? sprintf('  <info>[OK]</info> %s', $label) : sprintf('  <error>[FAIL]</error> %s', $label);
    }
}
