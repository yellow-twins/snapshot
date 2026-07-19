<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Transport;

use Symfony\Component\Process\Process;
use YellowTwins\Snapshot\Configuration\EnvironmentConfig;
use YellowTwins\Snapshot\Exception\TransportException;
use YellowTwins\Snapshot\Process\CommandResult;

/**
 * Runs remote commands over SSH using non-interactive key authentication.
 */
final class SshTransport implements TransportInterface
{
    public function supports(EnvironmentConfig $environment): bool
    {
        return $environment->transport === 'ssh';
    }

    public function run(EnvironmentConfig $environment, string $remoteCommand, ?string $outputFile = null, ?int $timeout = 3600): CommandResult
    {
        $command = $this->baseSshCommand($environment);
        $command[] = $remoteCommand;

        $process = new Process($command);
        $process->setTimeout($timeout === null ? null : (float)$timeout);

        if ($outputFile === null) {
            $process->run();

            return new CommandResult($process->getExitCode() ?? 1, $process->getOutput(), $process->getErrorOutput());
        }

        $handle = @fopen($outputFile, 'wb');
        if ($handle === false) {
            throw new TransportException(sprintf('Unable to open "%s" for writing.', $outputFile), 1_752_900_100);
        }

        $stderr = '';
        try {
            $process->run(static function (string $type, string $buffer) use ($handle, &$stderr): void {
                if ($type === Process::OUT) {
                    fwrite($handle, $buffer);
                } else {
                    $stderr .= $buffer;
                }
            });
        } finally {
            fclose($handle);
        }

        return new CommandResult($process->getExitCode() ?? 1, '', $stderr);
    }

    public function describe(EnvironmentConfig $environment): string
    {
        return sprintf('%s:%d', $this->target($environment), $environment->port);
    }

    /**
     * @return list<string>
     */
    private function baseSshCommand(EnvironmentConfig $environment): array
    {
        return [
            'ssh',
            '-p', (string)$environment->port,
            '-o', 'BatchMode=yes',
            '-o', 'ConnectTimeout=10',
            '-o', 'StrictHostKeyChecking=accept-new',
            $this->target($environment),
        ];
    }

    private function target(EnvironmentConfig $environment): string
    {
        return $environment->user === ''
            ? $environment->host
            : $environment->user . '@' . $environment->host;
    }
}
