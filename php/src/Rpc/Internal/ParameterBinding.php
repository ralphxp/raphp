<?php

declare(strict_types=1);

namespace Raphp\Rpc\Internal;

/**
 * One parameter of an RPC method, prepared once when the method is registered.
 *
 * @internal
 */
final readonly class ParameterBinding
{
    /**
     * @param list<string> $types the names of the accepted types: int, float, string, bool, array, null, mixed
     */
    public function __construct(
        public string $name,
        public BindingKind $kind,
        public array $types,
        public bool $hasDefault,
        public mixed $default,
        public bool $nullable,
    ) {}

    /**
     * Whether a value from `params` may be given to this parameter. No conversion is done: a JSON
     * number that is an integer matches `int` and `float`, a float matches only `float`, and a
     * string is never taken for a number.
     */
    public function accepts(mixed $value): bool
    {
        foreach ($this->types as $type) {
            $matches = match ($type) {
                'mixed' => true,
                'null' => $value === null,
                'int' => is_int($value),
                'float' => is_float($value) || is_int($value),
                'string' => is_string($value),
                'bool' => is_bool($value),
                'array' => is_array($value),
                default => false,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    public function describeTypes(): string
    {
        return implode('|', $this->types);
    }
}
