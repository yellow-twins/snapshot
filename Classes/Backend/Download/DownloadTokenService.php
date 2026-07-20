<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Backend\Download;

use TYPO3\CMS\Core\Core\Environment;
use YellowTwins\Snapshot\Exception\SnapshotException;

/**
 * Issues and consumes single-use, expiring download tokens for prepared artifacts.
 *
 * Security properties (directly addressing the "predictable resource location" class of flaw):
 *  - artifacts are stored OUTSIDE the public web root (var/snapshot), never served statically;
 *  - the file name is derived from the SHA-256 hash of a 24-byte random token; the plaintext
 *    token exists only in the one-time URL and is never persisted;
 *  - single use is enforced by an atomic rename — only one request can ever claim an artifact;
 *  - tokens expire; expired artifacts are removed on access and by purge().
 */
final class DownloadTokenService
{
    public function __construct(
        private readonly ?string $storageDirectoryOverride = null,
    ) {}

    public function issue(string $artifactPath, string $downloadName, int $ttlSeconds, int $now): DownloadToken
    {
        $directory = $this->storageDirectory();
        $token = bin2hex(random_bytes(24));
        $hash = hash('sha256', $token);

        $binaryPath = $directory . '/' . $hash . '.bin';
        if (!@rename($artifactPath, $binaryPath)) {
            throw new SnapshotException('Could not move the prepared artifact into secure storage.', 1_752_901_000);
        }

        $size = filesize($binaryPath);
        $expiresAt = $now + $ttlSeconds;
        $meta = [
            'name' => $downloadName,
            'size' => $size === false ? 0 : $size,
            'expires' => $expiresAt,
        ];
        file_put_contents($directory . '/' . $hash . '.meta.json', json_encode($meta, JSON_THROW_ON_ERROR));

        return new DownloadToken($token, $downloadName, $meta['size'], $expiresAt);
    }

    /**
     * Atomically claims the artifact for a single download. Returns null when the token is
     * unknown, already used, or expired. The caller must delete the returned file after streaming.
     */
    public function consume(string $token, int $now): ?ConsumedArtifact
    {
        if (!ctype_xdigit($token)) {
            return null;
        }

        $directory = $this->storageDirectory();
        $hash = hash('sha256', $token);
        $metaPath = $directory . '/' . $hash . '.meta.json';
        $binaryPath = $directory . '/' . $hash . '.bin';

        if (!is_file($metaPath)) {
            return null;
        }

        $meta = $this->readMeta($metaPath);
        if ($meta === null || $meta['expires'] < $now) {
            @unlink($metaPath);
            @unlink($binaryPath);

            return null;
        }

        // Atomic single-use: only one request can win this rename.
        $claimedPath = $directory . '/' . $hash . '.consuming';
        if (!@rename($binaryPath, $claimedPath)) {
            return null;
        }
        @unlink($metaPath);

        return new ConsumedArtifact($claimedPath, $meta['name'], $meta['size']);
    }

    /**
     * Removes expired artifacts and orphaned consumed files. Returns the number removed.
     */
    public function purge(int $now): int
    {
        $directory = $this->storageDirectory();
        $removed = 0;

        foreach (glob($directory . '/*.meta.json') ?: [] as $metaPath) {
            $meta = $this->readMeta($metaPath);
            if ($meta === null || $meta['expires'] < $now) {
                $hash = basename($metaPath, '.meta.json');
                @unlink($metaPath);
                @unlink($directory . '/' . $hash . '.bin');
                ++$removed;
            }
        }
        foreach (glob($directory . '/*.consuming') ?: [] as $leftover) {
            @unlink($leftover);
            ++$removed;
        }

        return $removed;
    }

    public function storageDirectory(): string
    {
        $directory = $this->storageDirectoryOverride ?? rtrim(Environment::getVarPath(), '/') . '/snapshot';
        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new SnapshotException(sprintf('Could not create artifact storage directory "%s".', $directory), 1_752_901_001);
        }

        return $directory;
    }

    /**
     * @return array{name: string, size: int, expires: int}|null
     */
    private function readMeta(string $metaPath): ?array
    {
        $raw = @file_get_contents($metaPath);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['name'], $decoded['size'], $decoded['expires'])) {
            return null;
        }

        return [
            'name' => is_string($decoded['name']) ? $decoded['name'] : 'snapshot',
            'size' => is_int($decoded['size']) ? $decoded['size'] : 0,
            'expires' => is_int($decoded['expires']) ? $decoded['expires'] : 0,
        ];
    }
}
