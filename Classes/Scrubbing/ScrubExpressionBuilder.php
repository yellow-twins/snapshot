<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Scrubbing;

/**
 * Turns a value template into an SQL expression, resolving the {uid} token to the uid column
 * so anonymized values remain unique per row. Quoting is injected to stay DBMS-agnostic and
 * unit-testable.
 */
final class ScrubExpressionBuilder
{
    private const UID_TOKEN = '{uid}';

    /**
     * @param callable(string): string $quoteString     Quotes a string literal
     * @param callable(string): string $quoteIdentifier Quotes a column identifier
     */
    public function build(string $template, callable $quoteString, callable $quoteIdentifier): string
    {
        if (!str_contains($template, self::UID_TOKEN)) {
            return $quoteString($template);
        }

        $parts = [];
        foreach (explode(self::UID_TOKEN, $template) as $index => $segment) {
            if ($index > 0) {
                $parts[] = $quoteIdentifier('uid');
            }
            if ($segment !== '') {
                $parts[] = $quoteString($segment);
            }
        }

        return 'CONCAT(' . implode(', ', $parts) . ')';
    }
}
