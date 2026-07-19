<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Database;

/**
 * Matches table names against shell-style patterns (fnmatch), e.g. "cache_*" or "[bf]e_sessions".
 */
final class TablePatternMatcher
{
    /**
     * Returns the tables that match at least one of the patterns.
     *
     * @param list<string> $tables
     * @param list<string> $patterns
     * @return list<string>
     */
    public function match(array $tables, array $patterns): array
    {
        $matched = [];
        foreach ($tables as $table) {
            foreach ($patterns as $pattern) {
                if (fnmatch($pattern, $table)) {
                    $matched[] = $table;
                    break;
                }
            }
        }

        return $matched;
    }
}
