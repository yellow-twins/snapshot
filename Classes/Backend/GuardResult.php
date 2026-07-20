<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Backend;

/**
 * Outcome of the backend export security evaluation.
 */
final readonly class GuardResult
{
    /**
     * @param list<string> $problems Human-readable blockers; empty when access is granted
     */
    public function __construct(
        public bool $allowed,
        public array $problems,
    ) {}
}
