<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Tests\Unit\Backend\Download;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YellowTwins\Snapshot\Backend\Download\DownloadTokenService;

final class DownloadTokenServiceTest extends TestCase
{
    private string $storage;
    private DownloadTokenService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . '/snapshot-tok-' . uniqid('', true);
        mkdir($this->storage, 0o700, true);
        $this->service = new DownloadTokenService($this->storage);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storage . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->storage)) {
            rmdir($this->storage);
        }
        parent::tearDown();
    }

    #[Test]
    public function issuedTokenCanBeConsumedExactlyOnce(): void
    {
        $token = $this->service->issue($this->artifact('payload'), 'fileadmin.zip', 900, 1000);

        self::assertSame('fileadmin.zip', $token->downloadName);
        self::assertSame(7, $token->byteSize);

        $first = $this->service->consume($token->token, 1001);
        self::assertNotNull($first);
        self::assertSame('payload', file_get_contents($first->filePath));

        // Second attempt must fail — single use.
        self::assertNull($this->service->consume($token->token, 1002));
    }

    #[Test]
    public function expiredTokenIsRejectedAndCleanedUp(): void
    {
        $token = $this->service->issue($this->artifact('data'), 'fileadmin.zip', 900, 1000);

        self::assertNull($this->service->consume($token->token, 1000 + 901));
        self::assertNull($this->service->consume($token->token, 1000 + 5));
    }

    #[Test]
    public function unknownOrMalformedTokenReturnsNull(): void
    {
        self::assertNull($this->service->consume('not-a-hex-token!', 1000));
        self::assertNull($this->service->consume(str_repeat('a', 48), 1000));
    }

    #[Test]
    public function theStoredArtifactPathDoesNotContainThePlaintextToken(): void
    {
        $token = $this->service->issue($this->artifact('x'), 'fileadmin.zip', 900, 1000);

        foreach (glob($this->storage . '/*') ?: [] as $file) {
            self::assertStringNotContainsString($token->token, basename($file));
        }
    }

    #[Test]
    public function purgeRemovesExpiredArtifacts(): void
    {
        $this->service->issue($this->artifact('a'), 'a.zip', 100, 1000);
        $this->service->issue($this->artifact('b'), 'b.zip', 100, 1000);

        self::assertSame(2, $this->service->purge(2000));
    }

    private function artifact(string $contents): string
    {
        $path = $this->storage . '/incoming-' . uniqid('', true) . '.zip';
        file_put_contents($path, $contents);

        return $path;
    }
}
