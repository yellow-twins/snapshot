<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\FileSource;

use Symfony\Component\Process\Process;
use YellowTwins\Snapshot\Configuration\EnvironmentConfig;
use YellowTwins\Snapshot\Exception\TransportException;
use YellowTwins\Snapshot\Process\CommandResult;

/**
 * Pulls the remote fileadmin down with rsync over SSH (incremental, resumable).
 */
final class RsyncFileSource implements FileSourceInterface
{
    public function supports(EnvironmentConfig $environment): bool
    {
        return $environment->fileSource === 'rsync';
    }

    public function pullFileadmin(
        EnvironmentConfig $environment,
        string $localFileadminPath,
        array $excludes,
        bool $dryRun,
        ?callable $onProgress = null,
    ): CommandResult {
        $localPath = rtrim($localFileadminPath, '/') . '/';
        if (!$dryRun && !is_dir($localPath) && !@mkdir($localPath, 0o775, true) && !is_dir($localPath)) {
            throw new TransportException(sprintf('Unable to create local fileadmin directory "%s".', $localPath), 1_752_900_200);
        }

        $command = [
            'rsync',
            '-az',
            '--human-readable',
            '--stats',
            '-e', $this->sshCommand($environment),
        ];
        if ($dryRun) {
            $command[] = '--dry-run';
        } else {
            // Emit an overall transfer percentage we can turn into a progress bar.
            $command[] = '--info=progress2';
        }
        foreach ($excludes as $exclude) {
            $command[] = '--exclude=' . $exclude;
        }
        $command[] = $this->target($environment) . ':' . $environment->remoteFileadminPath() . '/';
        $command[] = $localPath;

        $process = new Process($command);
        $process->setTimeout(null);
        $process->run(function (string $type, string $buffer) use ($onProgress): void {
            if ($onProgress === null || $type !== Process::OUT) {
                return;
            }
            $percent = $this->parseProgressPercent($buffer);
            if ($percent !== null) {
                $onProgress($percent);
            }
        });

        return new CommandResult($process->getExitCode() ?? 1, $process->getOutput(), $process->getErrorOutput());
    }

    public function estimateBytes(EnvironmentConfig $environment, array $excludes): ?int
    {
        $command = ['rsync', '-an', '--stats', '-e', $this->sshCommand($environment)];
        foreach ($excludes as $exclude) {
            $command[] = '--exclude=' . $exclude;
        }
        $command[] = $this->target($environment) . ':' . $environment->remoteFileadminPath() . '/';
        $command[] = rtrim(sys_get_temp_dir(), '/') . '/';

        $process = new Process($command);
        $process->setTimeout(null);
        $process->run();
        if (!$process->isSuccessful()) {
            return null;
        }

        return $this->parseTotalBytes($process->getOutput());
    }

    /**
     * Extracts the "Total file size" value (in bytes) from rsync --stats output.
     */
    public function parseTotalBytes(string $statsOutput): ?int
    {
        if (preg_match('/Total file size:\s*([\d,]+)/', $statsOutput, $matches) !== 1) {
            return null;
        }

        return (int)str_replace(',', '', $matches[1]);
    }

    /**
     * Extracts the latest overall percentage from rsync --info=progress2 output.
     *
     * rsync overwrites its progress line with a carriage return, so a single output
     * buffer may carry several updates; the last one is the most recent. Returns null
     * when the buffer holds no percentage yet (e.g. while the file list is still built).
     */
    public function parseProgressPercent(string $buffer): ?int
    {
        if (preg_match_all('/(\d{1,3})%/', $buffer, $matches) < 1) {
            return null;
        }

        $last = end($matches[1]);
        if ($last === false) {
            return null;
        }

        return max(0, min(100, (int)$last));
    }

    private function sshCommand(EnvironmentConfig $environment): string
    {
        return sprintf('ssh -p %d -o BatchMode=yes -o StrictHostKeyChecking=accept-new', $environment->port);
    }

    private function target(EnvironmentConfig $environment): string
    {
        return $environment->user === ''
            ? $environment->host
            : $environment->user . '@' . $environment->host;
    }
}
