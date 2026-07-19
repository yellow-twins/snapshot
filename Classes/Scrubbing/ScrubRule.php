<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Scrubbing;

/**
 * A single table's anonymization rule: either truncate the table, or overwrite columns.
 *
 * Column values are templates. A literal string is used verbatim; the token {uid} is replaced
 * with the row's uid so anonymized values (e.g. e-mail addresses) stay unique per row.
 */
final readonly class ScrubRule
{
    /**
     * @param array<string, string> $set Column name => value template (ignored when truncating)
     */
    public function __construct(
        public bool $truncate,
        public array $set,
    ) {}

    public static function truncate(): self
    {
        return new self(true, []);
    }

    /**
     * @param array<string, string> $set
     */
    public static function set(array $set): self
    {
        return new self(false, $set);
    }
}
