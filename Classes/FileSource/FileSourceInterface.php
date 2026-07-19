<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\FileSource;

use YellowTwins\Snapshot\Configuration\EnvironmentConfig;
use YellowTwins\Snapshot\Process\CommandResult;

/**
 * Transfers the remote fileadmin to the local machine. The v1 implementation is rsync;
 * an object-storage (S3) source can be added later behind this interface.
 */
interface FileSourceInterface
{
    public function supports(EnvironmentConfig $environment): bool;

    /**
     * @param list<string>              $excludes   Path patterns excluded from the transfer
     * @param callable(string): void|null $onProgress Receives raw output chunks for live display
     */
    public function pullFileadmin(
        EnvironmentConfig $environment,
        string $localFileadminPath,
        array $excludes,
        bool $dryRun,
        ?callable $onProgress = null,
    ): CommandResult;
}
