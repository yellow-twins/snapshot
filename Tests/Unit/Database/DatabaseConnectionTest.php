<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YellowTwins\Snapshot\Database\DatabaseConnection;
use YellowTwins\Snapshot\Exception\SnapshotException;

final class DatabaseConnectionTest extends TestCase
{
    #[Test]
    public function buildsFromTypicalTypo3Params(): void
    {
        $connection = DatabaseConnection::fromParams([
            'driver' => 'mysqli',
            'host' => 'db.example.com',
            'port' => 3307,
            'dbname' => 'app',
            'user' => 'appuser',
            'password' => 'secret',
        ]);

        self::assertSame('db.example.com', $connection->host);
        self::assertSame(3307, $connection->port);
        self::assertSame('app', $connection->dbname);
        self::assertSame('appuser', $connection->user);
        self::assertSame('secret', $connection->password);
        self::assertNull($connection->unixSocket);
    }

    #[Test]
    public function coercesStringPortToInt(): void
    {
        $connection = DatabaseConnection::fromParams(['dbname' => 'app', 'user' => 'u', 'port' => '3306']);

        self::assertSame(3306, $connection->port);
    }

    #[Test]
    public function fallsBackToDefaultPortForInvalidValue(): void
    {
        $connection = DatabaseConnection::fromParams(['dbname' => 'app', 'user' => 'u', 'port' => 'not-a-number']);

        self::assertSame(3306, $connection->port);
    }

    #[Test]
    public function throwsWhenDbnameMissing(): void
    {
        $this->expectException(SnapshotException::class);
        DatabaseConnection::fromParams(['user' => 'u']);
    }

    #[Test]
    public function clientArgumentsUseTcpWhenNoSocket(): void
    {
        $connection = DatabaseConnection::fromParams([
            'host' => 'db',
            'port' => 3306,
            'dbname' => 'app',
            'user' => 'appuser',
            'password' => 'x',
        ]);

        self::assertSame(
            ['--host=db', '--protocol=TCP', '--port=3306', '--user=appuser'],
            $connection->clientArguments(),
        );
    }

    #[Test]
    public function clientArgumentsUseSocketWhenProvided(): void
    {
        $connection = DatabaseConnection::fromParams([
            'dbname' => 'app',
            'user' => 'appuser',
            'unix_socket' => '/var/run/mysqld/mysqld.sock',
        ]);

        self::assertSame(
            ['--socket=/var/run/mysqld/mysqld.sock', '--user=appuser'],
            $connection->clientArguments(),
        );
    }
}
