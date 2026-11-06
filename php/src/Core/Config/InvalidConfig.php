<?php

declare(strict_types=1);

namespace Raphp\Core\Config;

use RuntimeException;

/**
 * Thrown when a configuration file or environment value is not valid.
 *
 * It carries the problems found (at most {@see Problems::MAX}) and says how many more were
 * left out. Step P1.6 re-parents it under the framework's error hierarchy.
 */
final class InvalidConfig extends RuntimeException
{
    /**
     * @param list<string> $problems
     */
    private function __construct(string $message, private readonly array $problems)
    {
        parent::__construct($message);
    }

    /**
     * @param list<string> $problems
     * @param int $omitted how many further problems were found but are not listed
     */
    public static function because(string $source, array $problems, int $omitted = 0): self
    {
        $lines = array_map(static fn(string $problem): string => "  - {$problem}", $problems);
        if ($omitted > 0) {
            $lines[] = "  ... and {$omitted} more problem" . ($omitted === 1 ? '' : 's') . ' not shown';
        }

        return new self(
            'Invalid configuration (' . Problems::escape($source) . "):\n" . implode("\n", $lines),
            $problems,
        );
    }

    /**
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problems;
    }
}
