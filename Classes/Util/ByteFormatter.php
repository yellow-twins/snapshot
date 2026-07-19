<?php

declare(strict_types=1);

namespace YellowTwins\Snapshot\Util;

/**
 * Formats byte counts as human-readable strings (base 1024).
 */
final class ByteFormatter
{
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    public function format(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $value = (float)$bytes;
        $unit = 0;
        // Stop at the last index of UNITS (5 = PB).
        while ($value >= 1024 && $unit < 5) {
            $value /= 1024;
            ++$unit;
        }

        return sprintf('%.1f %s', $value, self::UNITS[$unit]);
    }
}
