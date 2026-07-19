<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;
use YellowTwins\Snapshot\Configuration\ConfigurationLoader;

/**
 * Lists the environments defined in the project's .snapshot.yaml.
 */
#[AsCommand(name: 'snapshot:list-envs', description: 'List the environments defined in .snapshot.yaml')]
final class ListEnvironmentsCommand extends Command
{
    public function __construct(
        private readonly ConfigurationLoader $configurationLoader,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $configuration = $this->configurationLoader->load(Environment::getProjectPath());

        if ($configuration->environments === []) {
            $io->warning('No environments defined.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($configuration->environments as $environment) {
            $rows[] = [
                $environment->name,
                $environment->transport,
                $environment->user === '' ? $environment->host : $environment->user . '@' . $environment->host,
                (string)$environment->port,
                $environment->path,
                $environment->fileSource,
            ];
        }

        $io->table(['Name', 'Transport', 'Host', 'Port', 'Path', 'Files'], $rows);

        return Command::SUCCESS;
    }
}
