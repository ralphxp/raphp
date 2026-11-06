<?php

declare(strict_types=1);

namespace Raphp\Ipc\Message;

use InvalidArgumentException;

/**
 * A notification nothing answers. With a `ref` it belongs to the request of that id (progress,
 * streaming); without one it is an application event
 * (`{"kind": "evt", "method": "app.theme_changed", "params": {...}}`).
 */
final readonly class Event implements Envelope
{
    /**
     * @param array<array-key, mixed> $params
     *
     * @throws InvalidArgumentException when the method is empty, the ref is not positive, or the params are a list
     */
    public function __construct(
        public string $method,
        public array $params = [],
        public ?int $ref = null,
    ) {
        Validation::notEmpty($method, '"method"');
        Validation::params($params);
        if ($ref !== null) {
            Validation::positive($ref, '"ref"');
        }
    }

    public function kind(): Kind
    {
        return Kind::Event;
    }

    public function toArray(): array
    {
        $message = ['kind' => Kind::Event->value];
        if ($this->ref !== null) {
            $message['ref'] = $this->ref;
        }

        $message['method'] = $this->method;
        $message['params'] = Validation::object($this->params);

        return $message;
    }
}
