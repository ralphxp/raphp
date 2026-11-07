<?php

declare(strict_types=1);

namespace Raphp\Error;

use Throwable;

/**
 * An error that travels between processes as the `error` object of a response (FRAMEWORK 6.4).
 *
 * Every subclass has a fixed wire code. PHP's own `getCode()` must return an int, so the
 * string code is exposed as {@see self::wireCode()}.
 *
 * On the wire an error is `{"code": string, "message": string, "data": any}`.
 * {@see self::toWire()} produces it and {@see self::fromWire()} reads it back into the right
 * subclass. The stack trace and the previous exception do not cross the wire.
 */
abstract class RpcException extends RaphpException
{
    public function __construct(string $message = '', public readonly mixed $data = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The code as it appears on the wire.
     */
    abstract public function wireCode(): string;

    /**
     * @return array{code: string, message: string, data: mixed}
     */
    public function toWire(): array
    {
        return ['code' => $this->wireCode(), 'message' => $this->getMessage(), 'data' => $this->data];
    }

    /**
     * The most of a peer's error message that is kept. The message ends up in logs and exception
     * traces, and a peer could send megabytes.
     */
    public const int MAX_PEER_MESSAGE_BYTES = 2048;

    /**
     * Rebuilds the exception from a received `error` object.
     *
     * - a framework code gives its own subclass;
     * - a well-formed `E_APP_*` code gives an {@see ApplicationException};
     * - any other code (a newer peer, say) gives an {@see InternalErrorException} whose `data`
     *   keeps the original code and data, and whose message does not echo the code;
     * - a malformed object (no string `code` or `message`) gives a {@see ProtocolException}.
     *
     * Extra keys are ignored, so a newer peer can add fields.
     *
     * @param array<array-key, mixed> $error
     */
    public static function fromWire(array $error): self
    {
        $code = $error['code'] ?? null;
        $message = $error['message'] ?? null;
        if (!is_string($code) || $code === '' || !is_string($message)) {
            return new ProtocolException('malformed error object: "code" must be a non-empty string and "message" a string');
        }

        $message = Text::truncate($message, self::MAX_PEER_MESSAGE_BYTES);
        $data = $error['data'] ?? null;
        $known = ErrorCode::fromWire($code);
        if ($known !== null) {
            return match ($known) {
                ErrorCode::MethodNotFound => new MethodNotFoundException($message, $data),
                ErrorCode::InvalidParams => new InvalidParamsException($message, $data),
                ErrorCode::Cancelled => new RequestCancelledException($message, $data),
                ErrorCode::Timeout => new RequestTimeoutException($message, $data),
                ErrorCode::WorkerCrashed => new WorkerCrashedException($message, $data),
                ErrorCode::WorkerUnavailable => new WorkerUnavailableException($message, $data),
                ErrorCode::PayloadTooLarge => new PayloadTooLargeException($message, $data),
                ErrorCode::Protocol => new ProtocolException($message, $data),
                ErrorCode::Internal => new InternalErrorException($message, $data),
            };
        }

        if (ApplicationException::isValidCode($code)) {
            return new ApplicationException($code, $message, $data);
        }

        return new InternalErrorException('an error with an unknown code was received', ['code' => $code, 'message' => $message, 'data' => $data]);
    }
}
