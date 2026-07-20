<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Database;

use YellowTwins\Snapshot\Exception\SnapshotException;

/**
 * Minimal database connection details needed to drive the mysql / mysqldump clients.
 */
final readonly class DatabaseConnection
{
    public function __construct(
        public string $host,
        public int $port,
        public string $dbname,
        public string $user,
        public string $password,
        public ?string $unixSocket = null,
    ) {}

    /**
     * @param array<string, mixed> $params TYPO3 / Doctrine connection parameters
     */
    public static function fromParams(array $params): self
    {
        $dbname = $params['dbname'] ?? '';
        $user = $params['user'] ?? '';
        if (!is_string($dbname) || $dbname === '') {
            throw new SnapshotException('Database connection is missing a "dbname".', 1_752_900_300);
        }
        if (!is_string($user)) {
            throw new SnapshotException('Database connection "user" must be a string.', 1_752_900_301);
        }

        $host = $params['host'] ?? '127.0.0.1';
        $password = $params['password'] ?? '';
        $socket = $params['unix_socket'] ?? null;

        return new self(
            host: is_string($host) ? $host : '127.0.0.1',
            port: self::toPort($params['port'] ?? 3306),
            dbname: $dbname,
            user: $user,
            password: is_string($password) ? $password : '',
            unixSocket: is_string($socket) && $socket !== '' ? $socket : null,
        );
    }

    /**
     * Returns a copy pointing at a different database on the same server (same host, port,
     * credentials). Used to target a throwaway temporary database for the anonymized export.
     */
    public function withDbname(string $dbname): self
    {
        return new self(
            host: $this->host,
            port: $this->port,
            dbname: $dbname,
            user: $this->user,
            password: $this->password,
            unixSocket: $this->unixSocket,
        );
    }

    /**
     * Connection flags for the mysql / mysqldump CLIs. The password is intentionally
     * omitted here and passed via the MYSQL_PWD environment variable instead.
     *
     * @return list<string>
     */
    public function clientArguments(): array
    {
        if ($this->unixSocket !== null) {
            $args = ['--socket=' . $this->unixSocket];
        } else {
            $args = ['--host=' . $this->host, '--protocol=TCP', '--port=' . $this->port];
        }
        if ($this->user !== '') {
            $args[] = '--user=' . $this->user;
        }

        return $args;
    }

    private static function toPort(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int)$value;
        }

        return 3306;
    }
}
