<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Backend\Download;

/**
 * An artifact that has just been atomically claimed for a single download.
 */
final readonly class ConsumedArtifact
{
    public function __construct(
        public string $filePath,
        public string $downloadName,
        public int $byteSize,
    ) {}
}
