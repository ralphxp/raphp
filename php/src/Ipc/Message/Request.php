<?php

declare(strict_types=1);

namespace Raphp\Ipc\Message;

use InvalidArgumentException;

/**
 * A call: `{"id": 17, "kind": "req", "method": "files.scan", "params": {...}}`. It is answered by
 * zero or more {@see Event}s with `ref = id`, then exactly one {@see Response}.
 *
 * The optional `trace` is an id that follows one operation across processes, so log lines on
 * both sides can be matched (letters, digits, `_`, `.`, `-`, at most 64 characters).
 */
final readonly class Request implements Envelope
{
    /**
     * @param array<array-key, mixed> $params
     *
     * @throws InvalidArgumentException when the id is not positive, the method is empty, the params are a list, or the trace id is not valid
     */
    public function __construct(
        public int $id,
        public string $method,
        public array $params = [],
        public ?string $trace = null,
    ) {
        Validation::positive($id, '"id"');
        Validation::method($method);
        Validation::params($params);
        if ($trace !== null) {
            Validation::trace($trace);
        }
    }

    public function kind(): Kind
    {
        return Kind::Request;
    }

    public function toArray(): array
    {
        $message = ['id' => $this->id, 'kind' => Kind::Request->value, 'method' => $this->method, 'params' => Validation::object($this->params)];
        if ($this->trace !== null) {
            $message['trace'] = $this->trace;
        }

        return $message;
    }
}
