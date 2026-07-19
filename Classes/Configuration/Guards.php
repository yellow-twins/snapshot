<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Configuration;

/**
 * Safety guards. Snapshot is pull-first; pushing to production is opt-in only.
 */
final readonly class Guards
{
    public function __construct(
        public bool $pushToLive = false,
    ) {}
}
