<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Tests\Functional\Backend;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use YellowTwins\Snapshot\Backend\Export\DatabaseExportService;
use YellowTwins\Snapshot\Database\DatabaseConnectionResolver;
use YellowTwins\Snapshot\Database\TablePatternMatcher;
use YellowTwins\Snapshot\Scrubbing\ScrubbingService;
use YellowTwins\Snapshot\Scrubbing\ScrubExpressionBuilder;
use YellowTwins\Snapshot\Service\DatabaseDumpService;
use YellowTwins\Snapshot\Transport\SshTransport;

final class DatabaseExportServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['frontend'];

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectionPool = $this->get(ConnectionPool::class);

        $platform = $this->connectionPool
            ->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME)
            ->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('The database export relies on MySQL/MariaDB (mysqldump + CREATE DATABASE).');
        }
    }

    #[Test]
    public function exportsAnonymizedDumpWithoutTouchingTheLiveDatabase(): void
    {
        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $connection->insert('fe_users', ['uid' => 1, 'pid' => 0, 'username' => 'john.doe', 'email' => 'john@real-domain.com', 'name' => 'John Doe']);

        $sqlPath = $this->createService()->export();

        try {
            // 1. The critical guarantee: the live database is untouched.
            $liveRows = $connection->select(['username', 'email', 'name'], 'fe_users', ['uid' => 1])->fetchAllAssociative();
            self::assertCount(1, $liveRows);
            self::assertSame('john.doe', $liveRows[0]['username']);
            self::assertSame('john@real-domain.com', $liveRows[0]['email']);
            self::assertSame('John Doe', $liveRows[0]['name']);

            // 2. The produced dump is anonymized (and carries no real personal data).
            $sql = (string)file_get_contents($sqlPath);
            self::assertStringContainsString('user1@example.invalid', $sql);
            self::assertStringNotContainsString('john@real-domain.com', $sql);
            self::assertStringNotContainsString('john.doe', $sql);

            // 3. No temporary working database is left behind.
            $databases = $connection->executeQuery('SHOW DATABASES')->fetchFirstColumn();
            foreach ($databases as $database) {
                $name = is_scalar($database) ? (string)$database : '';
                self::assertStringNotContainsString('_snap_', $name);
            }
        } finally {
            @unlink($sqlPath);
        }
    }

    #[Test]
    public function rawExportKeepsDataUnanonymized(): void
    {
        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $connection->insert('fe_users', ['uid' => 1, 'pid' => 0, 'username' => 'john.doe', 'email' => 'john@real-domain.com', 'name' => 'John Doe']);

        $sqlPath = $this->createService()->export(false);

        try {
            // The raw export deliberately preserves the real data (for local debugging).
            $sql = (string)file_get_contents($sqlPath);
            self::assertStringContainsString('john@real-domain.com', $sql);
            self::assertStringNotContainsString('user1@example.invalid', $sql);

            // A raw export never creates a temporary database (it reads the live DB directly).
            $databases = $connection->executeQuery('SHOW DATABASES')->fetchFirstColumn();
            foreach ($databases as $database) {
                $name = is_scalar($database) ? (string)$database : '';
                self::assertStringNotContainsString('_snap_', $name);
            }
        } finally {
            @unlink($sqlPath);
        }
    }

    private function createService(): DatabaseExportService
    {
        return new DatabaseExportService(
            new DatabaseConnectionResolver($this->connectionPool),
            new DatabaseDumpService(new SshTransport(), new TablePatternMatcher()),
            new ScrubbingService(new ScrubExpressionBuilder()),
            new TablePatternMatcher(),
            $this->connectionPool,
        );
    }
}
