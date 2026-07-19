<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Configuration;

use YellowTwins\Snapshot\Database\DatabaseConnection;

/**
 * Immutable description of a single source environment (e.g. "live", "stage").
 */
final readonly class EnvironmentConfig
{
    public function __construct(
        public string $name,
        public string $transport,
        public string $host,
        public string $user,
        public int $port,
        public string $path,
        public string $fileSource,
        public string $php = 'php',
        /**
         * Explicit remote database connection. When null, Snapshot tries to read it from the
         * remote settings.php — which only works if the credentials live there and not solely
         * in web-context environment variables (a common hosting setup).
         */
        public ?DatabaseConnection $database = null,
    ) {}

    /**
     * Absolute path to the TYPO3 console binary on the remote, derived from the project root.
     */
    public function remoteTypo3Binary(): string
    {
        return rtrim($this->path, '/') . '/vendor/bin/typo3';
    }

    /**
     * Absolute path to the TYPO3 settings file on the remote (composer mode).
     */
    public function remoteSettingsFile(): string
    {
        return rtrim($this->path, '/') . '/config/system/settings.php';
    }

    /**
     * Absolute path to the remote fileadmin directory (assumes the default "public" docroot).
     */
    public function remoteFileadminPath(): string
    {
        return rtrim($this->path, '/') . '/public/fileadmin';
    }
}
