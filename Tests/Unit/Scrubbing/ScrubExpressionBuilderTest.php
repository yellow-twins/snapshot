<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Tests\Unit\Scrubbing;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YellowTwins\Snapshot\Scrubbing\ScrubExpressionBuilder;

final class ScrubExpressionBuilderTest extends TestCase
{
    private ScrubExpressionBuilder $builder;

    /** @var callable(string): string */
    private $quoteString;

    /** @var callable(string): string */
    private $quoteIdentifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new ScrubExpressionBuilder();
        $this->quoteString = static fn(string $value): string => "'" . $value . "'";
        $this->quoteIdentifier = static fn(string $identifier): string => '`' . $identifier . '`';
    }

    #[Test]
    public function literalTemplateBecomesAQuotedString(): void
    {
        self::assertSame(
            "'Anonymous'",
            $this->builder->build('Anonymous', $this->quoteString, $this->quoteIdentifier),
        );
    }

    #[Test]
    public function emptyLiteralIsQuoted(): void
    {
        self::assertSame("''", $this->builder->build('', $this->quoteString, $this->quoteIdentifier));
    }

    #[Test]
    public function uidTokenInTheMiddleBecomesConcatWithUidColumn(): void
    {
        self::assertSame(
            "CONCAT('user', `uid`, '@example.invalid')",
            $this->builder->build('user{uid}@example.invalid', $this->quoteString, $this->quoteIdentifier),
        );
    }

    #[Test]
    public function uidTokenWithLeadingPrefixOnly(): void
    {
        self::assertSame(
            "CONCAT('user', `uid`)",
            $this->builder->build('user{uid}', $this->quoteString, $this->quoteIdentifier),
        );
    }

    #[Test]
    public function bareUidTokenBecomesJustTheColumn(): void
    {
        self::assertSame(
            'CONCAT(`uid`)',
            $this->builder->build('{uid}', $this->quoteString, $this->quoteIdentifier),
        );
    }
}
