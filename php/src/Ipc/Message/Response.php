<?php

declare(strict_types=1);

namespace Raphp\Ipc\Message;

use InvalidArgumentException;
use Raphp\Error\RpcException;

/**
 * The one answer to a {@see Request}: either `{"id": 17, "kind": "res", "result": ...}` or
 * `{"id": 17, "kind": "res", "error": {...}}`. Build it with {@see self::success()} or
 * {@see self::failure()}.
 */
final readonly class Response implements Envelope
{
    private function __construct(
        public int $id,
        public mixed $result,
        public ?RpcException $error,
    ) {
        Validation::positive($id, '"id"');
    }

    /**
     * @throws InvalidArgumentException when the id is not positive
     */
    public static function success(int $id, mixed $result = null): self
    {
        return new self($id, $result, null);
    }

    /**
     * @throws InvalidArgumentException when the id is not positive
     */
    public static function failure(int $id, RpcException $error): self
    {
        return new self($id, null, $error);
    }

    public function isFailure(): bool
    {
        return $this->error !== null;
    }

    public function kind(): Kind
    {
        return Kind::Response;
    }

    public function toArray(): array
    {
        if ($this->error !== null) {
            return ['id' => $this->id, 'kind' => Kind::Response->value, 'error' => $this->error->toWire()];
        }

        return ['id' => $this->id, 'kind' => Kind::Response->value, 'result' => $this->result];
    }
}
