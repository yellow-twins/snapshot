<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YellowTwins\Snapshot\Configuration\ConfigurationLoader;
use YellowTwins\Snapshot\Exception\ConfigurationException;
use YellowTwins\Snapshot\Exception\EnvironmentNotFoundException;

final class ConfigurationLoaderTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $dir = sys_get_temp_dir() . '/snapshot-test-' . uniqid('', true);
        mkdir($dir, 0o775, true);
        $this->projectRoot = $dir;
    }

    protected function tearDown(): void
    {
        $file = $this->projectRoot . '/.snapshot.yaml';
        if (is_file($file)) {
            unlink($file);
        }
        if (is_dir($this->projectRoot)) {
            rmdir($this->projectRoot);
        }
        parent::tearDown();
    }

    #[Test]
    public function loadsEnvironmentsAndInterpolatesEnvPlaceholders(): void
    {
        $this->writeConfig(
            <<<'YAML'
                environments:
                  live:
                    transport: ssh
                    host: "%env(SNAP_HOST)%"
                    user: deploy
                    port: 2222
                    path: "%env(SNAP_PATH)%"
                    file_source: rsync
                defaults:
                  scrub: true
                  db_exclude:
                    - "cache_*"
                YAML,
        );

        $loader = new ConfigurationLoader(static fn(string $name): string|false => match ($name) {
            'SNAP_HOST' => 'live.example.com',
            'SNAP_PATH' => '/var/www/live',
            default => false,
        });

        $config = $loader->load($this->projectRoot);
        $live = $config->getEnvironment('live');

        self::assertSame('live.example.com', $live->host);
        self::assertSame('/var/www/live', $live->path);
        self::assertSame(2222, $live->port);
        self::assertSame('deploy', $live->user);
        self::assertTrue($config->defaults->scrub);
        self::assertSame(['cache_*'], $config->defaults->dbExclude);
    }

    #[Test]
    public function throwsWhenReferencedEnvVarIsMissing(): void
    {
        $this->writeConfig(
            <<<'YAML'
                environments:
                  live:
                    host: "%env(MISSING_VAR)%"
                    path: /var/www/live
                YAML,
        );

        $loader = new ConfigurationLoader(
            static fn(string $name): string|false => $name === 'PRESENT' ? 'value' : false,
        );

        $this->expectException(ConfigurationException::class);
        $loader->load($this->projectRoot);
    }

    #[Test]
    public function throwsWhenConfigFileIsMissing(): void
    {
        $loader = new ConfigurationLoader();

        $this->expectException(ConfigurationException::class);
        $loader->load($this->projectRoot);
    }

    #[Test]
    public function throwsWhenNoEnvironmentsDefined(): void
    {
        $this->writeConfig("defaults:\n  scrub: true\n");
        $loader = new ConfigurationLoader();

        $this->expectException(ConfigurationException::class);
        $loader->load($this->projectRoot);
    }

    #[Test]
    public function rejectsUnsupportedTransport(): void
    {
        $this->writeConfig(
            <<<'YAML'
                environments:
                  live:
                    transport: kubectl
                    host: live.example.com
                    path: /var/www/live
                YAML,
        );
        $loader = new ConfigurationLoader();

        $this->expectException(ConfigurationException::class);
        $loader->load($this->projectRoot);
    }

    #[Test]
    public function unknownEnvironmentThrows(): void
    {
        $this->writeConfig(
            <<<'YAML'
                environments:
                  live:
                    host: live.example.com
                    path: /var/www/live
                YAML,
        );
        $config = (new ConfigurationLoader())->load($this->projectRoot);

        $this->expectException(EnvironmentNotFoundException::class);
        $config->getEnvironment('staging');
    }

    private function writeConfig(string $yaml): void
    {
        file_put_contents($this->projectRoot . '/.snapshot.yaml', $yaml);
    }
}
