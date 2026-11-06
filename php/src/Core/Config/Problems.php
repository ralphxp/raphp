<?php

declare(strict_types=1);

namespace Raphp\Core\Config;

/**
 * Collects the problems found in a configuration, so they can be reported together.
 *
 * It keeps at most {@see self::MAX} problems and counts the rest, so a hostile or broken file
 * cannot produce megabytes of output. Anything written by the user (a key, a value) is
 * shortened and has its control characters escaped before it can reach a message.
 *
 * @internal
 */
final class Problems
{
    public const int MAX = 50;

    private const int MAX_PART = 80;

    /** @var list<string> */
    private array $items = [];

    private int $omitted = 0;

    public function add(string $path, string $message): void
    {
        if (count($this->items) >= self::MAX) {
            $this->omitted++;

            return;
        }

        $safePath = self::escape(self::shorten($path));
        $this->items[] = $safePath === '' ? $message : "{$safePath}: {$message}";
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * @return list<string> at most {@see self::MAX} problems
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * How many problems were found after the limit was reached and are therefore not listed.
     */
    public function omitted(): int
    {
        return $this->omitted;
    }

    /**
     * Cuts text to a readable length. It cuts on a character boundary when the text is valid
     * UTF-8, and on a byte boundary when it is not.
     */
    public static function shorten(string $text): string
    {
        if (strlen($text) <= self::MAX_PART) {
            return $text;
        }

        if (preg_match('/^.{0,' . self::MAX_PART . '}/su', $text, $match) === 1) {
            return $match[0] . '...';
        }

        return substr($text, 0, self::MAX_PART) . '...';
    }

    /**
     * Makes text safe to print: control characters (including DEL and the C1 range) become
     * visible escapes, so a newline or a terminal escape sequence cannot change how an error is
     * displayed. Backslashes are escaped only when asked, so paths stay readable.
     */
    public static function escape(string $text, bool $escapeBackslash = false): string
    {
        $text = addcslashes($text, $escapeBackslash ? "\0..\37\177\\" : "\0..\37\177");

        if (preg_match('//u', $text) !== 1) {
            // Not valid UTF-8: show every non-ASCII byte as an escape.
            return (string) preg_replace_callback(
                '/[\x80-\xff]/',
                static fn(array $match): string => sprintf('\\x%02x', ord($match[0])),
                $text,
            );
        }

        // Valid UTF-8: the C1 controls U+0080 to U+009F are the two bytes C2 80 to C2 9F.
        return (string) preg_replace_callback(
            '/\xC2[\x80-\x9F]/',
            static fn(array $match): string => sprintf('\\u%04x', ord($match[0][1])),
            $text,
        );
    }
}
