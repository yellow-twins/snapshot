<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Configuration;

use YellowTwins\Snapshot\Scrubbing\ScrubRule;

/**
 * Default behaviour shared across environments, overridable per pull via CLI options.
 */
final readonly class Defaults
{
    /**
     * @param list<string>             $dbExclude     Table name patterns (fnmatch) whose data is skipped in the dump
     * @param list<string>             $rsyncExcludes Path patterns excluded during fileadmin sync
     * @param list<string>             $postPull      Post-pull hook identifiers to run after a successful pull
     * @param array<string, ScrubRule> $scrubRules    Extra/override anonymization rules, merged over the built-in defaults
     */
    public function __construct(
        public bool $scrub = true,
        public array $dbExclude = [],
        public array $rsyncExcludes = [],
        public array $postPull = [],
        public array $scrubRules = [],
    ) {}
}
