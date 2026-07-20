<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Transport;

use YellowTwins\Snapshot\Configuration\EnvironmentConfig;
use YellowTwins\Snapshot\Process\CommandResult;

/**
 * Runs commands on a remote environment. The v1 implementation is SSH; the interface is
 * kept small so a "kubectl exec" transport can be added later without touching callers.
 */
interface TransportInterface
{
    public function supports(EnvironmentConfig $environment): bool;

    /**
     * Runs a shell command on the remote environment.
     *
     * @param string      $remoteCommand A shell-ready command string, interpreted by the remote shell
     * @param string|null $outputFile    If set, stdout is streamed to this local file instead of captured
     * @param int|null    $timeout       Seconds; null disables the timeout (for long dumps)
     * @param callable(int): void|null $onProgress Receives the running byte count streamed to $outputFile
     */
    public function run(EnvironmentConfig $environment, string $remoteCommand, ?string $outputFile = null, ?int $timeout = 3600, ?callable $onProgress = null): CommandResult;

    /**
     * Human-readable connection summary for diagnostics, e.g. "deploy@live.example.com:22".
     */
    public function describe(EnvironmentConfig $environment): string;
}
