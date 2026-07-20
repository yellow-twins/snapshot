<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Backend\Export;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Symfony\Component\Process\Process;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use YellowTwins\Snapshot\Database\DatabaseConnection;
use YellowTwins\Snapshot\Database\DatabaseConnectionResolver;
use YellowTwins\Snapshot\Database\TablePatternMatcher;
use YellowTwins\Snapshot\Scrubbing\ScrubbingService;
use YellowTwins\Snapshot\Service\DatabaseDumpService;

/**
 * Produces an anonymized SQL dump of THIS instance's database for the backend export module.
 *
 * Security invariant: the live database is never modified. Anonymization runs against a throwaway
 * temporary database that is a copy of the live one; the live database is only ever read (mysqldump).
 * The flow is: copy live -> temp, scrub temp, dump temp, drop temp. The intermediate (un-scrubbed)
 * copy file and the temporary database are always removed in a finally block.
 */
final class DatabaseExportService
{
    /**
     * Tables whose DATA is skipped in the export (structure is kept). Cache, session and log tables
     * carry no snapshot value and may contain personal data, so they are emptied by exclusion.
     *
     * @var list<string>
     */
    private const DEFAULT_DB_EXCLUDE_DATA = [
        'cache_*',
        'cf_*',
        '[bf]e_sessions',
        'sys_log',
        'sys_history',
        'sys_file_processedfile',
    ];

    private const DUMP_FLAGS = ['--no-tablespaces', '--skip-comments'];

    public function __construct(
        private readonly DatabaseConnectionResolver $connectionResolver,
        private readonly DatabaseDumpService $databaseDumpService,
        private readonly ScrubbingService $scrubbingService,
        private readonly TablePatternMatcher $tablePatternMatcher,
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Builds an SQL dump and returns the absolute path to the resulting .sql file in var/snapshot.
     * The caller owns the file afterwards (hands it to the download-token service).
     *
     * @param bool                         $scrub     Anonymize personal data (the safe default). When
     *                                                false, the live database is dumped as-is — only
     *                                                use for a raw export that the environment allows.
     * @param callable(string): void|null $onMessage Receives per-table scrubbing messages
     *
     * @throws DatabaseExportException when the platform is unsupported or the CREATE privilege is missing
     */
    public function export(bool $scrub = true, ?callable $onMessage = null): string
    {
        $this->assertMysqlPlatform();
        $live = $this->connectionResolver->resolveLocal();

        return $scrub ? $this->exportAnonymized($live, $onMessage) : $this->exportRaw($live);
    }

    /**
     * Dumps the live database directly, without anonymization. No temporary database is needed
     * (and thus no CREATE privilege), because the live data is only read, never modified.
     */
    private function exportRaw(DatabaseConnection $live): string
    {
        $artifactFile = $this->storageDirectory() . '/tmp-' . bin2hex(random_bytes(8)) . '.sql';
        try {
            $this->dumpLiveToFile($live, $artifactFile);

            return $artifactFile;
        } catch (\Throwable $exception) {
            @unlink($artifactFile);

            throw $exception;
        }
    }

    /**
     * @param callable(string): void|null $onMessage
     */
    private function exportAnonymized(DatabaseConnection $live, ?callable $onMessage): string
    {
        $tempName = $this->temporaryDatabaseName($live->dbname);
        $temp = $live->withDbname($tempName);

        $storage = $this->storageDirectory();
        $copyFile = $storage . '/tmp-' . bin2hex(random_bytes(8)) . '.copy.sql';
        $artifactFile = $storage . '/tmp-' . bin2hex(random_bytes(8)) . '.sql';

        $this->createDatabase($live, $tempName);
        $connectionName = null;
        try {
            // 1. Copy live -> temp (schema of every table, data of every table except the excluded ones).
            $this->dumpLiveToFile($live, $copyFile);
            $this->databaseDumpService->importLocalFromFile($temp, $copyFile);

            // 2. Anonymize the temp database only. Never the live/DEFAULT connection.
            [$connectionName, $tempConnection] = $this->registerTemporaryConnection($tempName);
            $this->scrubbingService->scrub($tempConnection, [], $onMessage ?? static function (string $message): void {});

            // 3. Dump the scrubbed temp database into the download artifact.
            $this->dumpDatabaseToFile($temp, self::DUMP_FLAGS, $artifactFile);

            return $artifactFile;
        } catch (\Throwable $exception) {
            @unlink($artifactFile);

            throw $exception;
        } finally {
            @unlink($copyFile);
            $this->dropDatabase($live, $tempName);
            if ($connectionName !== null) {
                $this->unregisterTemporaryConnection($connectionName);
            }
        }
    }

    private function assertMysqlPlatform(): void
    {
        $platform = $this->connectionPool
            ->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME)
            ->getDatabasePlatform();

        if (!$platform instanceof AbstractMySQLPlatform) {
            throw new DatabaseExportException('The database export currently supports MySQL / MariaDB only.', 1_752_901_300);
        }
    }

    /**
     * Dumps the live database to $targetFile: pass 1 dumps the schema of every table, pass 2 dumps
     * data for every table except the excluded ones. Excluded-table DATA therefore never leaves the
     * live database. Used both to seed the temp copy (anonymized path) and as the raw artifact.
     */
    private function dumpLiveToFile(DatabaseConnection $live, string $targetFile): void
    {
        $this->dumpDatabaseToFile($live, [...self::DUMP_FLAGS, '--no-data'], $targetFile, false);

        $dataFlags = [...self::DUMP_FLAGS, '--no-create-info', '--single-transaction', '--quick'];
        foreach ($this->excludedTables() as $table) {
            $dataFlags[] = '--ignore-table=' . $live->dbname . '.' . $table;
        }
        $this->dumpDatabaseToFile($live, $dataFlags, $targetFile, true);
    }

    /**
     * Runs mysqldump for the given connection with the given flags, streaming stdout into $targetFile.
     *
     * @param list<string> $extraFlags
     */
    private function dumpDatabaseToFile(DatabaseConnection $connection, array $extraFlags, string $targetFile, bool $append = false): void
    {
        // --no-defaults makes the command hermetic: it ignores the invoking user's option files
        // (e.g. a ~/.my.cnf whose [client] password would otherwise override MYSQL_PWD), so only
        // our explicit arguments and MYSQL_PWD decide the connection.
        $command = ['mysqldump', '--no-defaults', ...$connection->clientArguments(), ...$extraFlags, $connection->dbname];

        $handle = @fopen($targetFile, $append ? 'ab' : 'wb');
        if ($handle === false) {
            throw new DatabaseExportException(sprintf('Unable to open "%s" for writing.', $targetFile), 1_752_901_301);
        }

        $stderr = '';
        $process = new Process($command, null, ['MYSQL_PWD' => $connection->password]);
        $process->setTimeout(null);
        try {
            $process->run(static function (string $type, string $buffer) use ($handle, &$stderr): void {
                if ($type === Process::OUT) {
                    fwrite($handle, $buffer);

                    return;
                }
                $stderr .= $buffer;
            });
        } finally {
            fclose($handle);
        }

        if (!$process->isSuccessful()) {
            throw new DatabaseExportException(
                sprintf('Database dump failed: %s', $this->firstLine($stderr, 'exit code ' . (string)$process->getExitCode())),
                1_752_901_302,
            );
        }
    }

    private function createDatabase(DatabaseConnection $server, string $name): void
    {
        $result = $this->runServerStatement($server, sprintf('CREATE DATABASE `%s`', $name));
        if (!$result->isSuccessful()) {
            throw new DatabaseExportException(
                sprintf(
                    'Could not create the temporary export database. The database user needs the CREATE privilege. (%s)',
                    $this->firstLine($result->getErrorOutput(), 'exit code ' . (string)$result->getExitCode()),
                ),
                1_752_901_303,
            );
        }
    }

    private function dropDatabase(DatabaseConnection $server, string $name): void
    {
        // Best effort — the export already produced (or failed to produce) its artifact by now.
        $this->runServerStatement($server, sprintf('DROP DATABASE IF EXISTS `%s`', $name));
    }

    /**
     * Runs a server-level statement (no database selected) via the mysql client.
     */
    private function runServerStatement(DatabaseConnection $server, string $statement): Process
    {
        $command = ['mysql', '--no-defaults', ...$server->clientArguments(), '-e', $statement];
        $process = new Process($command, null, ['MYSQL_PWD' => $server->password]);
        $process->setTimeout(null);
        $process->run();

        return $process;
    }

    /**
     * Registers the temporary database as a runtime TYPO3 connection so the ScrubbingService can use
     * TYPO3's query builder / schema manager against it. The name is unique per run to avoid reusing
     * a cached connection that points at an already-dropped database.
     *
     * @return array{0: string, 1: Connection}
     */
    private function registerTemporaryConnection(string $tempDbName): array
    {
        /** @var array<string, mixed> $params */
        $params = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME)->getParams();
        $params['dbname'] = $tempDbName;

        $connectionName = 'snapshot_export_' . bin2hex(random_bytes(6));
        $connections = $this->connectionConfigs();
        $connections[$connectionName] = $params;
        $this->writeConnectionConfigs($connections);

        return [$connectionName, $this->connectionPool->getConnectionByName($connectionName)];
    }

    private function unregisterTemporaryConnection(string $connectionName): void
    {
        $connections = $this->connectionConfigs();
        unset($connections[$connectionName]);
        $this->writeConnectionConfigs($connections);
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionConfigs(): array
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $db = is_array($confVars) && is_array($confVars['DB'] ?? null) ? $confVars['DB'] : [];
        $connections = is_array($db['Connections'] ?? null) ? $db['Connections'] : [];

        /** @var array<string, mixed> $connections */
        return $connections;
    }

    /**
     * @param array<string, mixed> $connections
     */
    private function writeConnectionConfigs(array $connections): void
    {
        $confVars = is_array($GLOBALS['TYPO3_CONF_VARS'] ?? null) ? $GLOBALS['TYPO3_CONF_VARS'] : [];
        $db = is_array($confVars['DB'] ?? null) ? $confVars['DB'] : [];
        $db['Connections'] = $connections;
        $confVars['DB'] = $db;
        $GLOBALS['TYPO3_CONF_VARS'] = $confVars;
    }

    /**
     * @return list<string>
     */
    private function excludedTables(): array
    {
        $tables = $this->connectionPool
            ->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME)
            ->createSchemaManager()
            ->listTableNames();

        return $this->tablePatternMatcher->match($tables, self::DEFAULT_DB_EXCLUDE_DATA);
    }

    private function temporaryDatabaseName(string $liveDbName): string
    {
        $base = preg_replace('/[^A-Za-z0-9_]/', '_', $liveDbName) ?? 'db';

        // Keep well under MySQL's 64-char identifier limit.
        return substr($base, 0, 40) . '_snap_' . bin2hex(random_bytes(6));
    }

    private function storageDirectory(): string
    {
        $directory = Environment::getVarPath() . '/snapshot';
        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new DatabaseExportException(sprintf('Unable to create the export directory "%s".', $directory), 1_752_901_304);
        }

        return $directory;
    }

    private function firstLine(string $text, string $fallback): string
    {
        $text = trim($text);
        if ($text === '') {
            return $fallback;
        }

        return trim(explode("\n", $text)[0]);
    }
}
