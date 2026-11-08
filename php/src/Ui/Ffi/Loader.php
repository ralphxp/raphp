<?php

declare(strict_types=1);

namespace Raphp\Ui\Ffi;

use FFI;
use FFI\Exception as FfiException;
use Psr\Log\LoggerInterface;
use Raphp\Ui\Ffi\Internal\Native;

/**
 * Loads the shim library through PHP's FFI and checks it is the one this code was written for.
 *
 * Every way it can fail ends in a {@see ShimLoadException} that says what is wrong and what to do: FFI is not
 * installed, FFI is switched off or restricted by `ffi.enable`, the header or the library cannot be read, or
 * the library has another ABI version. `ffi.enable=preload`, PHP's default, already permits `FFI::cdef` in the
 * command line and micro SAPIs, so no setting has to be changed there (D20).
 *
 * The ABI version is read first, through a header of one function, so that a library of another version is
 * reported as that and not as a missing function.
 */
final readonly class Loader
{
    /** The ABI this code speaks. */
    public const int ABI_MAJOR = 0;

    public const int ABI_MINOR = 4;

    private AbiVersion $expected;

    public function __construct(
        private LoggerInterface $logger,
        ?AbiVersion $expected = null,
    ) {
        $this->expected = $expected ?? new AbiVersion(self::ABI_MAJOR, self::ABI_MINOR);
    }

    /**
     * @param string $library the path of `libraphp.so`
     * @param string $header  the path of `raphp.h`, the preprocessor-free header that `make header` makes
     *
     * @throws ShimLoadException
     */
    public function load(string $library, string $header): Library
    {
        if (!extension_loaded('ffi')) {
            throw new ShimLoadException('PHP\'s FFI extension is not loaded, and the shim is reached through it. Install or enable ext-ffi (on Fedora: dnf install php-ffi).');
        }

        if (!is_file($library) || !is_readable($library)) {
            throw new ShimLoadException("the shim library \"{$library}\" does not exist or cannot be read. Build it with `make shim`.");
        }

        $declarations = is_file($header) ? file_get_contents($header) : false;
        if ($declarations === false) {
            throw new ShimLoadException("the shim header \"{$header}\" does not exist or cannot be read. Generate it with `make header`.");
        }

        $found = $this->readVersion($library);
        if (!$this->expected->isSatisfiedBy($found)) {
            throw new ShimLoadException("the shim library \"{$library}\" has ABI version {$found}, but this code needs {$this->expected}. Rebuild the library with `make shim` from the same sources as this code.");
        }

        try {
            $ffi = FFI::cdef($declarations, $library);
        } catch (FfiException $exception) {
            throw $this->explain($exception, $library, 'the header does not match the library (was it made from other sources?)');
        }

        $this->logger->debug('shim library loaded', ['library' => $library, 'abi' => (string) $found]);

        return new Library(new Native($ffi), $found, $this->logger);
    }

    private function readVersion(string $library): AbiVersion
    {
        try {
            $ffi = FFI::cdef('uint32_t raphp_abi_version(void);', $library);
        } catch (FfiException $exception) {
            throw $this->explain($exception, $library, 'it has no raphp_abi_version, so it is not a shim library of any version this code knows');
        }

        $version = $ffi->raphp_abi_version();

        return AbiVersion::fromInt(is_int($version) ? $version : 0);
    }

    private function explain(FfiException $exception, string $library, string $unresolved): ShimLoadException
    {
        $reason = $exception->getMessage();
        $setting = ini_get('ffi.enable');
        $shown = $setting === false ? 'unknown' : '"' . $setting . '"';

        if (stripos($reason, 'restricted') !== false || stripos($reason, 'ffi.enable') !== false) {
            return new ShimLoadException("PHP does not allow FFI here: ffi.enable is {$shown} and the SAPI is \"" . PHP_SAPI . "\". The default \"preload\" allows it only in the command line and micro SAPIs; set ffi.enable=1 (or true) to allow it elsewhere. PHP said: {$reason}", 0, $exception);
        }

        if (stripos($reason, 'Failed resolving') !== false) {
            return new ShimLoadException("the shim library \"{$library}\" cannot be used: {$unresolved}. PHP said: {$reason}", 0, $exception);
        }

        return new ShimLoadException("the shim library \"{$library}\" could not be loaded: {$reason}. If it exists, a library it needs may be missing (check with `ldd`), or it needs a newer glibc than this system has.", 0, $exception);
    }
}
