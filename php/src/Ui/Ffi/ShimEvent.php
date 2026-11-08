<?php

declare(strict_types=1);

namespace Raphp\Ui\Ffi;

/**
 * One event from the shim: its `type` and every field it came with (the type included), as decoded from the
 * shim's JSON line.
 */
final readonly class ShimEvent
{
    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(
        public string $type,
        public array $fields,
    ) {}

    /**
     * @throws ShimException when the line is not a JSON object with a string `type`
     */
    public static function fromLine(string $line): self
    {
        try {
            $decoded = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ShimException('the shim produced an event that is not valid JSON: ' . $exception->getMessage(), 0, $exception);
        }

        $type = is_array($decoded) ? ($decoded['type'] ?? null) : null;
        if (!is_array($decoded) || !is_string($type)) {
            throw new ShimException('the shim produced an event without a "type"');
        }

        $fields = [];
        foreach ($decoded as $key => $value) {
            $fields[(string) $key] = $value;
        }

        return new self($type, $fields);
    }
}
