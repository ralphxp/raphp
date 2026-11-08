<?php

declare(strict_types=1);

namespace Raphp\Ui\Ffi\Internal;

use FFI;
use FFI\CData;

/**
 * The shim's C functions with PHP types. This is the only code that calls them: FFI makes the methods at load
 * time, so everything it returns arrives as `mixed` and is narrowed here, once.
 *
 * Statuses are returned as they are (negative numbers, `RaphpStatus`); turning them into exceptions is the
 * caller's job. It uses the instance form `$ffi->new()`, never the static one (deprecated in PHP 8.5, D20).
 *
 * @internal
 */
final class Native
{
    public const int STATUS_OK = 0;

    public const int STATUS_INVALID_ARGUMENT = -1;

    public const int STATUS_POISONED = -2;

    public const int STATUS_BUFFER_TOO_SMALL = -6;

    public const int STATUS_CLOSED = -7;

    /** The size of the buffer events are read into; a larger event gets a buffer of its own for the one read. */
    private const int POLL_BUFFER = 65_536;

    private ?CData $pollBuffer = null;

    public function __construct(private readonly FFI $ffi) {}

    public function abiVersion(): int
    {
        return self::integer($this->ffi->raphp_abi_version());
    }

    /**
     * @return CData|null the handle, or null when the shim could not start (the reason is `lastError(null)`)
     */
    public function init(string $configuration): ?CData
    {
        $handle = $this->ffi->raphp_init($configuration, strlen($configuration));

        return $handle instanceof CData ? $handle : null;
    }

    public function send(CData $shim, string $batch): int
    {
        return self::integer($this->ffi->raphp_send($shim, $batch, strlen($batch)));
    }

    public function lastError(?CData $shim): string
    {
        $needed = self::integer($this->ffi->raphp_last_error($shim, null, 0));
        if ($needed < 1) {
            return '';
        }

        $buffer = $this->ffi->new("char[{$needed}]");
        $written = self::integer($this->ffi->raphp_last_error($shim, $buffer, $needed));

        return FFI::string($buffer, max(0, min($written, $needed)));
    }

    public function shutdown(CData $shim): int
    {
        return self::integer($this->ffi->raphp_shutdown($shim));
    }

    public function eventFd(CData $shim): int
    {
        return self::integer($this->ffi->raphp_event_fd($shim));
    }

    /**
     * Reads the events that are queued and fit: whole lines, each ending in a newline.
     *
     * @return string|int the text (never empty), `0` when nothing is queued, or a negative status; for
     *                    {@see self::STATUS_BUFFER_TOO_SMALL} see {@see self::pollLarge()}
     */
    public function poll(CData $shim): string|int
    {
        $this->pollBuffer ??= $this->ffi->new('char[' . self::POLL_BUFFER . ']');
        $result = self::integer($this->ffi->raphp_poll($shim, $this->pollBuffer, self::POLL_BUFFER));

        return $result < 1 ? $result : FFI::string($this->pollBuffer, $result);
    }

    /**
     * Reads the next event, which did not fit the usual buffer, into a buffer made for it and dropped after.
     *
     * @return string|int the text, or a negative status
     */
    public function pollLarge(CData $shim): string|int
    {
        $size = self::integer($this->ffi->raphp_next_event_size($shim));
        if ($size < 1) {
            return 0;
        }

        $buffer = $this->ffi->new("char[{$size}]");
        $result = self::integer($this->ffi->raphp_poll($shim, $buffer, $size));

        return $result < 1 ? $result : FFI::string($buffer, $result);
    }

    public function diagnostics(CData $shim): string
    {
        $needed = self::integer($this->ffi->raphp_diagnostics($shim, null, 0));
        if ($needed < 1) {
            return '';
        }

        $buffer = $this->ffi->new("char[{$needed}]");
        $written = self::integer($this->ffi->raphp_diagnostics($shim, $buffer, $needed));

        return FFI::string($buffer, max(0, min($written, $needed)));
    }

    public function headlessAdvance(CData $shim, int $millis): int
    {
        return self::integer($this->ffi->raphp_headless_advance($shim, $millis));
    }

    /**
     * @return int the size of the picture in bytes, or a negative status
     */
    public function headlessFrameSize(CData $shim, int $window): int
    {
        return self::integer($this->ffi->raphp_headless_frame($shim, $window, null, 0, null, null));
    }

    /**
     * @return array{int, int, string}|int width, height and the pixels, or a negative status; `0` when the
     *                                     picture is not `$expected` bytes any more (the window was resized since)
     */
    public function headlessFrame(CData $shim, int $window, int $expected): array|int
    {
        $width = $this->ffi->new('uint32_t');
        $height = $this->ffi->new('uint32_t');
        $pixels = $this->ffi->new("uint8_t[{$expected}]");

        $size = self::integer($this->ffi->raphp_headless_frame($shim, $window, $pixels, $expected, FFI::addr($width), FFI::addr($height)));
        if ($size < 0) {
            return $size;
        }

        if ($size !== $expected) {
            return 0;
        }

        return [self::integer($width->cdata ?? 0), self::integer($height->cdata ?? 0), FFI::string($pixels, $expected)];
    }

    private static function integer(mixed $value): int
    {
        return is_int($value) ? $value : self::unexpected($value);
    }

    private static function unexpected(mixed $value): never
    {
        throw new \UnexpectedValueException('the shim returned ' . get_debug_type($value) . ' where a number was expected');
    }
}
