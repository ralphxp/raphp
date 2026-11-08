<?php

declare(strict_types=1);

namespace Raphp\Runtime\Libc;

use FFI;
use FFI\Exception as FfiException;

/**
 * Makes every open descriptor from a number up close-on-exec, so that a process started next inherits only the
 * descriptors it is explicitly given (R17, D51).
 *
 * Why it is needed: `proc_open` passes on every descriptor that is not close-on-exec. PHP itself leaves some like
 * that (the main script's file, the opcache lock, an epoll and an eventfd: F59), PHP's `php://fd/N` makes a
 * duplicate that is not (the shim's wake-up descriptor), and the window system's libraries in the shim open
 * connections that Raphp does not control. A sweep just before the start covers all of them, whoever made them.
 * The descriptors a child is meant to have (its standard streams, the channel on 3) are named in the
 * `proc_open` call, which gives them to the child whatever their own flag.
 *
 * It uses `close_range(first, last, CLOSE_RANGE_CLOEXEC)` (glibc 2.34, Linux 5.11: the floor the shim has
 * anyway) and, where the kernel lacks it, one `fcntl` per descriptor listed in `/proc/self/fd`. A descriptor
 * another thread opens during the sweep is missed; libraries that open close-on-exec, as they should, never need it.
 */
final class DescriptorGuard
{
    private const int CLOSE_RANGE_CLOEXEC = 4;

    private const int F_GETFD = 1;

    private const int F_SETFD = 2;

    private const int FD_CLOEXEC = 1;

    private ?FFI $libc = null;

    /**
     * @param bool $useCloseRange false goes straight to the descriptor-by-descriptor way (the one for an older kernel; tests use it)
     */
    public function __construct(private readonly bool $useCloseRange = true) {}

    /**
     * @param int $first the lowest descriptor to mark; 3 keeps standard input, output and error as they are
     *
     * @throws LibcException when libc cannot be used or neither way works
     */
    public function closeOnExecFrom(int $first = 3): void
    {
        $libc = $this->libc();

        if ($this->useCloseRange && $libc->close_range($first, 0xFFFF_FFFF, self::CLOSE_RANGE_CLOEXEC) === 0) {
            return;
        }

        // An older kernel: do it one descriptor at a time.
        $entries = scandir('/proc/self/fd');
        if ($entries === false) {
            throw new LibcException('close_range is not available and /proc/self/fd cannot be read');
        }

        foreach ($entries as $entry) {
            if (!ctype_digit($entry) || (int) $entry < $first) {
                continue;
            }

            $descriptor = (int) $entry;
            $flags = $libc->fcntl($descriptor, self::F_GETFD);
            if (is_int($flags) && $flags >= 0) {
                $libc->fcntl($descriptor, self::F_SETFD, $flags | self::FD_CLOEXEC); // the listing's own handle may be gone: that failure is harmless
            }
        }
    }

    /**
     * @throws LibcException
     */
    private function libc(): FFI
    {
        if ($this->libc !== null) {
            return $this->libc;
        }

        if (!extension_loaded('ffi')) {
            throw new LibcException('ext-ffi is required to keep descriptors from reaching started processes');
        }

        try {
            $this->libc = FFI::cdef('int close_range(unsigned int first, unsigned int last, int flags); int fcntl(int fd, int cmd, ...);', 'libc.so.6');
        } catch (FfiException $exception) {
            throw new LibcException('libc.so.6 could not be loaded: ' . $exception->getMessage(), 0, $exception);
        }

        return $this->libc;
    }
}
