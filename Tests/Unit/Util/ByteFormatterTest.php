<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Tests\Unit\Util;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YellowTwins\Snapshot\Util\ByteFormatter;

final class ByteFormatterTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function values(): iterable
    {
        yield 'bytes' => [512, '512 B'];
        yield 'exactly 1 KB' => [1024, '1.0 KB'];
        yield 'kilobytes' => [1536, '1.5 KB'];
        yield 'megabytes' => [248 * 1024 * 1024, '248.0 MB'];
        yield 'gigabytes' => [3 * 1024 * 1024 * 1024 + 200 * 1024 * 1024, '3.2 GB'];
        yield 'zero' => [0, '0 B'];
    }

    #[Test]
    #[DataProvider('values')]
    public function formatsBytes(int $bytes, string $expected): void
    {
        self::assertSame($expected, (new ByteFormatter())->format($bytes));
    }
}
