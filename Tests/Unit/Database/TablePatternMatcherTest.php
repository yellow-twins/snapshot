<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YellowTwins\Snapshot\Database\TablePatternMatcher;

final class TablePatternMatcherTest extends TestCase
{
    private TablePatternMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new TablePatternMatcher();
    }

    #[Test]
    public function matchesWildcardPrefix(): void
    {
        $tables = ['cache_hash', 'cache_pages', 'pages', 'tt_content'];

        self::assertSame(['cache_hash', 'cache_pages'], $this->matcher->match($tables, ['cache_*']));
    }

    #[Test]
    public function matchesCharacterClassPattern(): void
    {
        $tables = ['be_sessions', 'fe_sessions', 'fe_users', 'sys_log'];

        self::assertSame(['be_sessions', 'fe_sessions'], $this->matcher->match($tables, ['[bf]e_sessions']));
    }

    #[Test]
    public function matchesExactName(): void
    {
        $tables = ['sys_log', 'sys_history', 'sys_log_backup'];

        self::assertSame(['sys_log'], $this->matcher->match($tables, ['sys_log']));
    }

    #[Test]
    public function combinesMultiplePatternsWithoutDuplicates(): void
    {
        $tables = ['cache_hash', 'sys_log', 'fe_users'];

        self::assertSame(['cache_hash', 'sys_log'], $this->matcher->match($tables, ['cache_*', 'sys_log']));
    }

    #[Test]
    public function returnsEmptyWhenNothingMatches(): void
    {
        self::assertSame([], $this->matcher->match(['pages', 'tt_content'], ['cache_*']));
    }

    #[Test]
    public function returnsEmptyForEmptyPatternList(): void
    {
        self::assertSame([], $this->matcher->match(['pages', 'cache_hash'], []));
    }
}
