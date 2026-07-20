<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Service;

use Symfony\Component\Process\Process;
use TYPO3\CMS\Core\Core\Environment;
use YellowTwins\Snapshot\Configuration\EnvironmentConfig;
use YellowTwins\Snapshot\Database\DatabaseConnection;
use YellowTwins\Snapshot\Database\TablePatternMatcher;
use YellowTwins\Snapshot\Exception\SnapshotException;
use YellowTwins\Snapshot\Process\CommandResult;
use YellowTwins\Snapshot\Transport\TransportInterface;

/**
 * Dumps a remote database via mysqldump and imports it into the local database.
 *
 * The dump is produced in two passes so that excluded tables keep their structure but lose
 * their data: pass 1 dumps the schema of all tables, pass 2 dumps data for every table
 * except the excluded ones. This keeps the import valid while skipping cache/session bloat.
 */
final class DatabaseDumpService
{
    private const DUMP_FLAGS = ['--no-tablespaces', '--skip-comments'];

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly TablePatternMatcher $tablePatternMatcher,
    ) {}

    /**
     * Streams a remote dump into the given local file.
     *
     * @param list<string> $excludePatterns fnmatch patterns for tables whose data is skipped
     */
    public function dumpRemoteToFile(
        EnvironmentConfig $environment,
        DatabaseConnection $remote,
        array $excludePatterns,
        string $targetFile,
        ?callable $onProgress = null,
    ): void {
        $tables = $this->listRemoteTables($environment, $remote);
        $excluded = $this->tablePatternMatcher->match($tables, $excludePatterns);

        $structure = $this->clientCommand('mysqldump', $remote, [...self::DUMP_FLAGS, '--no-data'], true);

        $dataArgs = [...self::DUMP_FLAGS, '--no-create-info', '--single-transaction', '--quick'];
        foreach ($excluded as $table) {
            $dataArgs[] = '--ignore-table=' . $remote->dbname . '.' . $table;
        }
        $data = $this->clientCommand('mysqldump', $remote, $dataArgs, true);

        $result = $this->transport->run($environment, $structure . ' && ' . $data, $targetFile, null, $onProgress);
        if (!$result->isSuccessful()) {
            @unlink($targetFile);
            throw new SnapshotException(
                sprintf('Remote database dump failed: %s', $this->firstLine($result->stderr, 'exit code ' . $result->exitCode)),
                1_752_900_500,
            );
        }
    }

    /**
     * Whether the remote provides typo3_console's `database:export`. When it does, we prefer it:
     * TYPO3 bootstraps and resolves the real DB connection itself (including credentials that
     * only exist as web-context environment variables), so we never need to extract credentials.
     */
    public function remoteHasTypo3Console(EnvironmentConfig $environment): bool
    {
        $binary = escapeshellarg($environment->remoteTypo3Binary());
        $command = sprintf('test -x %s && %s database:export --help >/dev/null 2>&1 && echo yes', $binary, $binary);
        $result = $this->transport->run($environment, $command, null, 60);

        return $result->isSuccessful() && str_contains($result->stdout, 'yes');
    }

    /**
     * Dumps the remote database with `typo3 database:export`, streaming SQL into the local file.
     *
     * @param list<string> $excludePatterns Table name / wildcard patterns passed as -e options
     */
    public function dumpRemoteViaConsole(EnvironmentConfig $environment, array $excludePatterns, string $targetFile, ?callable $onProgress = null): void
    {
        $parts = [escapeshellarg($environment->remoteTypo3Binary()), 'database:export', '-c', 'Default'];
        foreach ($excludePatterns as $pattern) {
            $parts[] = '-e';
            $parts[] = escapeshellarg($pattern);
        }

        $result = $this->transport->run($environment, implode(' ', $parts), $targetFile, null, $onProgress);
        if (!$result->isSuccessful()) {
            @unlink($targetFile);
            throw new SnapshotException(
                sprintf('Remote typo3_console database:export failed: %s', $this->firstLine($result->stderr, 'exit code ' . $result->exitCode)),
                1_752_900_530,
            );
        }
    }

    /**
     * Returns the remote database size in bytes (data + index), or null if it cannot be read.
     */
    public function remoteDatabaseBytes(EnvironmentConfig $environment, DatabaseConnection $connection): ?int
    {
        $query = sprintf(
            "SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = '%s'",
            str_replace("'", '', $connection->dbname),
        );
        $command = $this->clientCommand('mysql', $connection, ['-N', '-e', $query], false);
        $result = $this->transport->run($environment, $command, null, 30);
        if (!$result->isSuccessful()) {
            return null;
        }

        $value = trim($result->stdout);

        return ctype_digit($value) ? (int)$value : null;
    }

    /**
     * Verifies that the given database is reachable and selectable on the remote.
     */
    public function remoteConnectionCheck(EnvironmentConfig $environment, DatabaseConnection $connection): CommandResult
    {
        $command = $this->clientCommand('mysql', $connection, ['-N', '-e', 'SELECT 1'], true);

        return $this->transport->run($environment, $command, null, 30);
    }

    /**
     * Imports a dump into the local database via typo3_console, which uses TYPO3's own
     * (already bootstrapped) connection — no need to reconstruct client credentials.
     */
    /**
     * @param callable(int): void|null $onProgress Receives the percentage (0-100) of the dump fed to the import
     */
    public function importLocalViaConsole(string $file, ?callable $onProgress = null): void
    {
        $binary = Environment::getProjectPath() . '/vendor/bin/typo3';
        if (!is_file($binary)) {
            throw new SnapshotException(
                sprintf('Local typo3 console binary not found at "%s". Is helhum/typo3-console installed?', $binary),
                1_752_900_540,
            );
        }

        $totalBytes = @filesize($file);
        if ($totalBytes === false) {
            throw new SnapshotException(sprintf('Unable to read dump file "%s".', $file), 1_752_900_541);
        }

        $process = new Process([PHP_BINARY, $binary, 'database:import']);
        $process->setTimeout(null);
        $process->setInput($this->streamDump($file, $totalBytes, $onProgress));
        $process->run();

        if (!$process->isSuccessful()) {
            throw new SnapshotException(
                sprintf('Local database import failed: %s', $this->firstLine($process->getErrorOutput(), 'exit code ' . (string)$process->getExitCode())),
                1_752_900_542,
            );
        }
    }

    /**
     * Feeds the dump file to the import process in chunks so the caller can report progress
     * as the import consumes stdin.
     *
     * @param callable(int): void|null $onProgress
     *
     * @return \Generator<string>
     */
    private function streamDump(string $file, int $totalBytes, ?callable $onProgress): \Generator
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new SnapshotException(sprintf('Unable to read dump file "%s".', $file), 1_752_900_543);
        }

        try {
            $read = 0;
            while (!feof($handle)) {
                $chunk = fread($handle, 262_144);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $read += strlen($chunk);
                if ($onProgress !== null && $totalBytes > 0) {
                    $onProgress((int)min(100, intdiv($read * 100, $totalBytes)));
                }

                yield $chunk;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Fallback import via the mysql client for setups without typo3_console.
     */
    public function importLocalFromFile(DatabaseConnection $local, string $file): void
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            throw new SnapshotException(sprintf('Unable to read dump file "%s".', $file), 1_752_900_510);
        }

        $command = ['mysql', ...$local->clientArguments(), $local->dbname];
        $process = new Process($command, null, ['MYSQL_PWD' => $local->password]);
        $process->setTimeout(null);
        $process->setInput($handle);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new SnapshotException(
                sprintf('Local database import failed: %s', $this->firstLine($process->getErrorOutput(), 'exit code ' . (string)$process->getExitCode())),
                1_752_900_511,
            );
        }
    }

    /**
     * @return list<string>
     */
    private function listRemoteTables(EnvironmentConfig $environment, DatabaseConnection $remote): array
    {
        $command = $this->clientCommand('mysql', $remote, ['-N', '-e', 'SHOW TABLES'], true);
        $result = $this->transport->run($environment, $command, null, 60);
        if (!$result->isSuccessful()) {
            throw new SnapshotException(
                sprintf('Could not list remote tables: %s', $this->firstLine($result->stderr, 'exit code ' . $result->exitCode)),
                1_752_900_520,
            );
        }

        $tables = [];
        foreach (explode("\n", $result->stdout) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $tables[] = $line;
            }
        }

        return $tables;
    }

    /**
     * Builds a shell-ready mysql/mysqldump command with the password passed via MYSQL_PWD.
     *
     * @param list<string> $extraArgs
     */
    private function clientCommand(string $binary, DatabaseConnection $connection, array $extraArgs, bool $includeDbName): string
    {
        $parts = ['MYSQL_PWD=' . escapeshellarg($connection->password), $binary];
        foreach ([...$connection->clientArguments(), ...$extraArgs] as $argument) {
            $parts[] = escapeshellarg($argument);
        }
        if ($includeDbName) {
            $parts[] = escapeshellarg($connection->dbname);
        }

        return implode(' ', $parts);
    }

    private function firstLine(string $text, string $fallback): string
    {
        $text = trim($text);
        if ($text === '') {
            return $fallback;
        }
        $lines = explode("\n", $text);

        return trim($lines[0]);
    }
}
