<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Database;

use TYPO3\CMS\Core\Database\ConnectionPool;
use YellowTwins\Snapshot\Configuration\EnvironmentConfig;
use YellowTwins\Snapshot\Exception\SnapshotException;
use YellowTwins\Snapshot\Transport\TransportInterface;

/**
 * Resolves database connection details for the local instance (via TYPO3's ConnectionPool)
 * and for a remote environment (by reading its composer-mode settings.php over the transport).
 */
final class DatabaseConnectionResolver
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function resolveLocal(): DatabaseConnection
    {
        $params = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME)->getParams();

        return DatabaseConnection::fromParams($params);
    }

    public function resolveRemote(EnvironmentConfig $environment, TransportInterface $transport): DatabaseConnection
    {
        $php = <<<'PHP'
            error_reporting(0);
            $c = require %s;
            $d = is_array($c) ? ($c["DB"]["Connections"]["Default"] ?? null) : null;
            if (!is_array($d)) { fwrite(STDERR, "no-default-connection"); exit(3); }
            echo json_encode([
                "host" => $d["host"] ?? null,
                "port" => $d["port"] ?? null,
                "dbname" => $d["dbname"] ?? null,
                "user" => $d["user"] ?? null,
                "password" => $d["password"] ?? null,
                "unix_socket" => $d["unix_socket"] ?? null,
            ]);
            PHP;

        $code = sprintf($php, var_export($environment->remoteSettingsFile(), true));
        $remoteCommand = escapeshellarg($environment->php) . ' -r ' . escapeshellarg($code);

        $result = $transport->run($environment, $remoteCommand, null, 60);
        if (!$result->isSuccessful()) {
            throw new SnapshotException(
                sprintf(
                    'Could not read remote database configuration from "%s": %s',
                    $environment->remoteSettingsFile(),
                    trim($result->stderr) !== '' ? trim($result->stderr) : 'exit code ' . $result->exitCode,
                ),
                1_752_900_400,
            );
        }

        $decoded = json_decode(trim($result->stdout), true);
        if (!is_array($decoded)) {
            throw new SnapshotException('Remote database configuration could not be decoded.', 1_752_900_401);
        }

        /** @var array<string, mixed> $decoded */
        return DatabaseConnection::fromParams($decoded);
    }
}
