<?php

declare(strict_types=1);

namespace Raphp\Core\Config;

use RuntimeException;

/**
 * Thrown when a configuration file or environment value is not valid.
 *
 * It carries every problem found, not just the first one. Step P1.6 re-parents it under
 * the framework's error hierarchy.
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
     */
    public static function because(string $source, array $problems): self
    {
        $lines = array_map(static fn(string $problem): string => "  - {$problem}", $problems);

        return new self("Invalid configuration ({$source}):\n" . implode("\n", $lines), $problems);
    }

    /**
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problems;
    }
}
