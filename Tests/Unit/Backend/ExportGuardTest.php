<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Tests\Unit\Backend;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use YellowTwins\Snapshot\Backend\ExportGuard;

final class ExportGuardTest extends TestCase
{
    private const ENV_KEYS = [
        'SNAPSHOT_BACKEND_ENABLED',
        'SNAPSHOT_ALLOWED_IPS',
        'SNAPSHOT_REQUIRE_MFA',
        'SNAPSHOT_ALLOW_UNSCRUBBED',
    ];

    /** @var array<string, string|false> */
    private array $envBackup = [];

    private mixed $beUserBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::ENV_KEYS as $key) {
            $this->envBackup[$key] = getenv($key);
            putenv($key);
        }
        $this->beUserBackup = $GLOBALS['BE_USER'] ?? null;
        unset($GLOBALS['BE_USER']);
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            $value === false ? putenv($key) : putenv($key . '=' . $value);
        }
        if ($this->beUserBackup !== null) {
            $GLOBALS['BE_USER'] = $this->beUserBackup;
        } else {
            unset($GLOBALS['BE_USER']);
        }
        parent::tearDown();
    }

    #[Test]
    public function isDisabledByDefault(): void
    {
        putenv('SNAPSHOT_REQUIRE_MFA=0');

        $result = (new ExportGuard())->evaluate(new ServerRequest());

        self::assertFalse($result->allowed);
        self::assertNotEmpty($result->problems);
        self::assertStringContainsStringIgnoringCase('disabled', implode(' ', $result->problems));
    }

    #[Test]
    public function isAllowedWhenEnabledWithoutMfaAndNoAllowlist(): void
    {
        putenv('SNAPSHOT_BACKEND_ENABLED=1');
        putenv('SNAPSHOT_REQUIRE_MFA=0');

        $result = (new ExportGuard())->evaluate(new ServerRequest());

        self::assertTrue($result->allowed);
        self::assertSame([], $result->problems);
    }

    #[Test]
    public function blocksAnAddressOutsideTheAllowlist(): void
    {
        putenv('SNAPSHOT_BACKEND_ENABLED=1');
        putenv('SNAPSHOT_REQUIRE_MFA=0');
        putenv('SNAPSHOT_ALLOWED_IPS=10.0.0.1');

        $result = (new ExportGuard())->evaluate($this->requestFromIp('192.168.5.5'));

        self::assertFalse($result->allowed);
        self::assertStringContainsStringIgnoringCase('allowlist', implode(' ', $result->problems));
    }

    #[Test]
    public function allowsAnAddressInsideTheAllowlist(): void
    {
        putenv('SNAPSHOT_BACKEND_ENABLED=1');
        putenv('SNAPSHOT_REQUIRE_MFA=0');
        putenv('SNAPSHOT_ALLOWED_IPS=10.0.0.1, 192.168.5.0/24');

        $result = (new ExportGuard())->evaluate($this->requestFromIp('192.168.5.5'));

        self::assertTrue($result->allowed);
    }

    #[Test]
    public function requiresMfaByDefault(): void
    {
        putenv('SNAPSHOT_BACKEND_ENABLED=1');
        // SNAPSHOT_REQUIRE_MFA unset → mandatory; no active MFA on the (absent) backend user.

        $result = (new ExportGuard())->evaluate(new ServerRequest());

        self::assertFalse($result->allowed);
        self::assertStringContainsStringIgnoringCase('two-factor', implode(' ', $result->problems));
    }

    #[Test]
    public function unscrubbedExportIsOffByDefaultAndEnabledByTheEnvironment(): void
    {
        self::assertFalse((new ExportGuard())->allowsUnscrubbedExport());

        putenv('SNAPSHOT_ALLOW_UNSCRUBBED=1');
        self::assertTrue((new ExportGuard())->allowsUnscrubbedExport());
    }

    private function requestFromIp(string $ip): ServerRequest
    {
        return (new ServerRequest())->withAttribute(
            'normalizedParams',
            new NormalizedParams(['REMOTE_ADDR' => $ip], [], '', ''),
        );
    }
}
