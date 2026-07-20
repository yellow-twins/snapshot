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
     * @param list<string>           $excludes   Path patterns excluded from the transfer
     * @param callable(int): void|null $onProgress Receives the overall transfer percentage (0–100) for live progress display
     */
    public function pullFileadmin(
        EnvironmentConfig $environment,
        string $localFileadminPath,
        array $excludes,
        bool $dryRun,
        ?callable $onProgress = null,
    ): CommandResult;

    /**
     * Estimates the total transfer size in bytes, or null if it cannot be determined.
     *
     * @param list<string> $excludes
     */
    public function estimateBytes(EnvironmentConfig $environment, array $excludes): ?int;
}
