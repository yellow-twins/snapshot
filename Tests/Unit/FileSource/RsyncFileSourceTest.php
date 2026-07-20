<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Tests\Unit\FileSource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YellowTwins\Snapshot\FileSource\RsyncFileSource;

final class RsyncFileSourceTest extends TestCase
{
    private RsyncFileSource $fileSource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fileSource = new RsyncFileSource();
    }

    #[Test]
    public function parsesTotalFileSizeWithThousandsSeparators(): void
    {
        $stats = <<<'TXT'
            Number of files: 623 (reg: 541, dir: 82)
            Number of created files: 617
            Total file size: 405,657,600 bytes
            Total transferred file size: 0 bytes
            TXT;

        self::assertSame(405_657_600, $this->fileSource->parseTotalBytes($stats));
    }

    #[Test]
    public function parsesPlainTotalFileSize(): void
    {
        self::assertSame(1024, $this->fileSource->parseTotalBytes("Total file size: 1024 bytes\n"));
    }

    #[Test]
    public function returnsNullWhenNoStatsLinePresent(): void
    {
        self::assertNull($this->fileSource->parseTotalBytes('some unrelated rsync output'));
    }

    #[Test]
    public function parsesLatestProgressPercentFromBuffer(): void
    {
        // rsync overwrites the line with \r, so a buffer may carry several updates.
        $buffer = "        1,234,567  12%    1.23MB/s    0:00:20\r        9,876,543  87%    2.34MB/s    0:00:03";

        self::assertSame(87, $this->fileSource->parseProgressPercent($buffer));
    }

    #[Test]
    public function parsesSingleProgressPercent(): void
    {
        self::assertSame(0, $this->fileSource->parseProgressPercent('            0   0%    0.00kB/s    0:00:00'));
    }

    #[Test]
    public function returnsNullWhenBufferHasNoPercent(): void
    {
        self::assertNull($this->fileSource->parseProgressPercent('sending incremental file list'));
    }
}
