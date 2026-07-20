<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Tests\Functional\Scrubbing;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use YellowTwins\Snapshot\Scrubbing\ScrubbingService;
use YellowTwins\Snapshot\Scrubbing\ScrubExpressionBuilder;
use YellowTwins\Snapshot\Scrubbing\ScrubRule;

final class ScrubbingServiceTest extends FunctionalTestCase
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
            self::markTestSkipped('Scrubbing relies on MySQL/MariaDB (CONCAT).');
        }
    }

    #[Test]
    public function anonymizesFeUsersWithDefaultRules(): void
    {
        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $connection->insert('fe_users', ['uid' => 1, 'pid' => 0, 'username' => 'john.doe', 'email' => 'john@real-domain.com', 'name' => 'John Doe']);
        $connection->insert('fe_users', ['uid' => 2, 'pid' => 0, 'username' => 'jane.roe', 'email' => 'jane@real-domain.com', 'name' => 'Jane Roe']);

        $this->createService()->scrub($connection, [], static function (string $message): void {});

        $rows = $connection->select(['uid', 'username', 'email', 'name'], 'fe_users', [], [], ['uid' => 'ASC'])->fetchAllAssociative();

        self::assertSame('user1', $rows[0]['username']);
        self::assertSame('user1@example.invalid', $rows[0]['email']);
        self::assertSame('Anonymous User', $rows[0]['name']);
        self::assertSame('user2@example.invalid', $rows[1]['email']);
    }

    #[Test]
    public function truncatesSysLogByDefault(): void
    {
        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $connection->insert('sys_log', ['details' => 'something happened']);
        self::assertGreaterThan(0, $connection->count('*', 'sys_log', []));

        $this->createService()->scrub($connection, [], static function (string $message): void {});

        self::assertSame(0, $connection->count('*', 'sys_log', []));
    }

    #[Test]
    public function configuredOverrideTruncatesAdditionalTable(): void
    {
        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $connection->insert('fe_users', ['uid' => 1, 'pid' => 0, 'username' => 'john.doe', 'email' => 'john@real-domain.com']);

        $this->createService()->scrub($connection, ['fe_users' => ScrubRule::truncate()], static function (string $message): void {});

        self::assertSame(0, $connection->count('*', 'fe_users', []));
    }

    private function createService(): ScrubbingService
    {
        return new ScrubbingService(new ScrubExpressionBuilder());
    }
}
