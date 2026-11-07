<?php

declare(strict_types=1);

namespace Raphp\Runtime\Libc;

use FFI;
use FFI\Exception as FfiException;

/**
 * Asks the kernel to send this process a signal when its parent dies, with `prctl(PR_SET_PDEATHSIG)`
 * (FRAMEWORK 5.6). It works even when the parent is killed with SIGKILL and runs no cleanup.
 *
 * This lives in the runtime's own libc namespace and does not use the UI package, so a headless
 * worker never loads the shim. It needs ext-ffi and a loadable `libc.so.6` (a glibc system).
 */
final class ParentDeathSignal
{
    private const int PR_SET_PDEATHSIG = 1;

    private ?FFI $libc = null;

    /**
     * @param int $signal the signal to receive when the parent dies; SIGTERM unless told otherwise
     *
     * @throws LibcException when FFI or libc is not available, or the call fails
     */
    public function arm(int $signal = SIGTERM): void
    {
        $libc = $this->libc();

        if ($libc->prctl(self::PR_SET_PDEATHSIG, $signal, 0, 0, 0) !== 0) {
            throw new LibcException('prctl(PR_SET_PDEATHSIG) failed');
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
            throw new LibcException('ext-ffi is required to arm the parent-death signal');
        }

        try {
            $this->libc = FFI::cdef('int prctl(int option, unsigned long arg2, unsigned long arg3, unsigned long arg4, unsigned long arg5);', 'libc.so.6');
        } catch (FfiException $exception) {
            throw new LibcException('libc.so.6 could not be loaded: ' . $exception->getMessage(), 0, $exception);
        }

        return $this->libc;
    }
}
