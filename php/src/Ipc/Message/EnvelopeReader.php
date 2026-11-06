<?php

declare(strict_types=1);

namespace Raphp\Ipc\Message;

use InvalidArgumentException;
use Raphp\Error\ProtocolException;
use Raphp\Error\RpcException;

/**
 * Turns a decoded line into a typed {@see Envelope}, or refuses it with a {@see ProtocolException}
 * (`E_PROTOCOL`).
 *
 * This class checks the *types* of the wire fields; the message classes check the *values*
 * (positive ids, non-empty names), and their complaints are passed on as protocol errors. Extra
 * keys are ignored so a newer peer can add fields. A missing required field, a field of the wrong
 * type (a float or a string where an integer belongs, `null` where an object belongs) and an
 * unknown `kind` are all refused.
 */
final readonly class EnvelopeReader
{
    /**
     * @param array<array-key, mixed> $data one decoded line
     *
     * @throws ProtocolException
     */
    public function read(array $data): Envelope
    {
        $value = $data['kind'] ?? null;
        if (!is_string($value)) {
            throw new ProtocolException('a message needs a string "kind"');
        }

        $kind = Kind::tryFrom($value);
        if ($kind === null) {
            throw new ProtocolException('unknown message kind ' . $this->quote($value));
        }

        try {
            return match ($kind) {
                Kind::Request => new Request($this->integer($data, 'id'), $this->text($data, 'method'), $this->params($data), $this->optionalTrace($data)),
                Kind::Response => $this->response($data),
                Kind::Event => new Event($this->text($data, 'method'), $this->params($data), $this->optionalInteger($data, 'ref')),
                Kind::Cancel => new Cancel($this->integer($data, 'ref')),
                Kind::Hello => new Hello($this->integer($data, 'proto'), $this->text($data, 'role'), $this->text($data, 'name'), $this->integer($data, 'pid'), $this->capabilities($data)),
                Kind::Bye => new Bye($this->optionalText($data, 'reason')),
                Kind::Ping => new Ping(),
                Kind::Pong => new Pong(),
            };
        } catch (InvalidArgumentException $exception) {
            throw new ProtocolException("invalid {$kind->value} message: {$exception->getMessage()}", null, $exception);
        }
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws ProtocolException
     * @throws InvalidArgumentException
     */
    private function response(array $data): Response
    {
        $id = $this->integer($data, 'id');
        $error = $data['error'] ?? null;

        if ($error === null) {
            if (!array_key_exists('result', $data)) {
                throw new ProtocolException('invalid res message: it needs a "result" or an "error"');
            }

            return Response::success($id, $data['result']);
        }

        if (($data['result'] ?? null) !== null) {
            throw new ProtocolException('invalid res message: it holds both a "result" and an "error"');
        }

        if (!is_array($error)) {
            throw new ProtocolException('invalid res message: "error" must be an object');
        }

        return Response::failure($id, RpcException::fromWire($error));
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws ProtocolException
     */
    private function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new ProtocolException("a message needs an integer \"{$key}\"");
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws ProtocolException
     */
    private function optionalInteger(array $data, string $key): ?int
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }

        return $this->integer($data, $key);
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws ProtocolException
     */
    private function text(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new ProtocolException("a message needs a string \"{$key}\"");
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws ProtocolException
     */
    private function optionalText(array $data, string $key): string
    {
        return array_key_exists($key, $data) ? $this->text($data, $key) : '';
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws ProtocolException
     */
    private function optionalTrace(array $data): ?string
    {
        return array_key_exists('trace', $data) ? $this->text($data, 'trace') : null;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     *
     * @throws ProtocolException
     */
    private function params(array $data): array
    {
        if (!array_key_exists('params', $data)) {
            return [];
        }

        $params = $data['params'];
        if (!is_array($params)) {
            throw new ProtocolException('"params" must be an object');
        }

        return $params;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<string>
     *
     * @throws ProtocolException
     */
    private function capabilities(array $data): array
    {
        if (!array_key_exists('caps', $data)) {
            return [];
        }

        $caps = $data['caps'];
        if (!is_array($caps) || !array_is_list($caps)) {
            throw new ProtocolException('"caps" must be a list of strings');
        }

        $names = [];
        foreach ($caps as $cap) {
            if (!is_string($cap)) {
                throw new ProtocolException('"caps" must be a list of strings');
            }

            $names[] = $cap;
        }

        return $names;
    }

    /**
     * A peer's text, made safe to put in a log line: quoted, shortened and with control
     * characters escaped.
     */
    private function quote(string $text): string
    {
        $shortened = strlen($text) > 32 ? substr($text, 0, 32) . '...' : $text;

        $quoted = json_encode($shortened, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $quoted === false ? '""' : $quoted;
    }
}
