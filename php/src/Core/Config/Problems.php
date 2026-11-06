<?php

declare(strict_types=1);

namespace Raphp\Core\Config;

/**
 * Collects every problem found in a configuration, so they can be reported together.
 *
 * @internal
 */
final class Problems
{
    /** @var list<string> */
    private array $items = [];

    /**
     * The path often contains a key written by the user, so control characters in it (a newline,
     * or a terminal escape sequence) are escaped and cannot alter how the error is displayed.
     */
    public function add(string $path, string $message): void
    {
        $safePath = addcslashes($path, "\0..\37\177");
        $this->items[] = $safePath === '' ? $message : "{$safePath}: {$message}";
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->items;
    }
}
