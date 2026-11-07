<?php

declare(strict_types=1);

namespace Raphp\Error;

/**
 * Helpers for text that came from another process or goes into a message another process reads.
 */
final class Text
{
    /**
     * Cuts text to at most `$maxBytes` bytes, never in the middle of a UTF-8 character, and marks
     * the cut with `...` (which is counted in the limit). Text that already fits is returned as is.
     *
     * @param positive-int $maxBytes at least 4
     */
    public static function truncate(string $text, int $maxBytes): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        $cut = substr($text, 0, max(0, $maxBytes - 3));
        while ($cut !== '' && preg_match('//u', $cut) !== 1) {
            $cut = substr($cut, 0, -1);
        }

        return $cut . '...';
    }
}
