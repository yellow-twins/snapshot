<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Backend\Download;

/**
 * A freshly issued, single-use download token. The plaintext token is only ever returned here
 * (and placed in the one-time URL); only its hash is persisted.
 */
final readonly class DownloadToken
{
    public function __construct(
        public string $token,
        public string $downloadName,
        public int $byteSize,
        public int $expiresAt,
    ) {}

    public function secondsRemaining(int $now): int
    {
        return max(0, $this->expiresAt - $now);
    }
}
