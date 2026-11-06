<?php

declare(strict_types=1);

namespace Raphp\Tests\Support;

/**
 * Collects what a callback saw. A closure writing to a captured variable is invisible to static
 * analysis, so tests hand the closure a recorder instead.
 */
final class Recorder
{
    /** @var list<string> */
    private array $entries = [];

    public function add(string $entry = 'called'): void
    {
        $this->entries[] = $entry;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * @return list<string>
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
