<?php

declare(strict_types=1);

namespace Raphp\Tests\Ui\Ffi;

use FFI;
use PHPUnit\Framework\TestCase;

/**
 * The shim's C ABI as PHP uses it: the committed header is loaded with FFI::cdef and the built library is
 * driven through it (plan P3.1). The library used is the debug build made by `make shim` with the
 * `test-hooks` feature, which adds a command that panics, so that the test can prove a panic does not cross
 * into PHP. This is the first test of the framework's own FFI binding code path; the real `Loader` is P3.5.
 */
final class ShimAbiTest extends TestCase
{
    private const string HEADER = __DIR__ . '/../../../../shim/include/raphp.h';

    private const string LIBRARY = __DIR__ . '/../../../../shim/target/debug/libraphp.so';

    private function header(): string
    {
        $header = file_get_contents(self::HEADER);
        self::assertNotFalse($header, 'the committed header is missing: run make header');

        return $header;
    }

    private function ffi(): FFI
    {
        if (!is_file(self::LIBRARY)) {
            self::markTestSkipped('the test build of the shim does not exist: run make shim');
        }

        return FFI::cdef($this->header(), self::LIBRARY);
    }

    private function lastError(FFI $ffi, mixed $shim): string
    {
        $needed = $ffi->raphp_last_error($shim, null, 0);
        self::assertIsInt($needed);
        if ($needed < 1) {
            return '';
        }

        $buffer = $ffi->new("char[{$needed}]");
        $written = $ffi->raphp_last_error($shim, $buffer, $needed);
        self::assertSame($needed, $written);

        return FFI::string($buffer, $needed);
    }

    public function testTheCommittedHeaderIsPlainDeclarationsThatFfiCanRead(): void
    {
        self::assertDoesNotMatchRegularExpression('/^#/m', $this->header(), 'FFI::cdef cannot read preprocessor lines');

        $ffi = $this->ffi(); // cdef also resolves every declared function in the library, so a header that names one that does not exist fails here

        self::assertSame(0, $ffi->RAPHP_STATUS_OK);
        self::assertSame(-2, $ffi->RAPHP_STATUS_POISONED);
        self::assertSame(-3, $ffi->RAPHP_STATUS_PANICKED);
    }

    public function testTheAbiVersionIsMajorThenMinor(): void
    {
        $version = $this->ffi()->raphp_abi_version();
        self::assertIsInt($version);

        self::assertSame(0, $version >> 16, 'major');
        self::assertSame(1, $version & 0xFFFF, 'minor');
    }

    public function testTheShimStartsAcceptsAnEmptyBatchAndShutsDown(): void
    {
        $ffi = $this->ffi();

        $shim = $ffi->raphp_init('{}', 2);

        self::assertNotNull($shim);
        self::assertSame(0, $ffi->raphp_send($shim, '[]', 2));
        self::assertSame(0, $ffi->raphp_shutdown($shim));
    }

    public function testAFailedInitGivesNullAndAReasonWithoutAHandle(): void
    {
        $ffi = $this->ffi();

        $shim = $ffi->raphp_init("\xff\xfe", 2);

        self::assertNull($shim, 'a null pointer arrives in PHP as null');
        self::assertStringContainsString('not valid UTF-8', $this->lastError($ffi, null));
    }

    public function testAnUnknownCommandIsAnErrorAndTheShimStaysUsable(): void
    {
        $ffi = $this->ffi();
        $shim = $ffi->raphp_init('', 0);

        $status = $ffi->raphp_send($shim, '[{"op":"nonsense"}]', 19);

        self::assertSame(-4, $status);
        self::assertStringContainsString('not implemented yet', $this->lastError($ffi, $shim));
        self::assertSame(0, $ffi->raphp_send($shim, '[]', 2), 'still usable');
        self::assertSame(0, $ffi->raphp_shutdown($shim));
    }

    public function testAPanicInsideACommandDoesNotCrossIntoPhpAndPoisonsTheShim(): void
    {
        $ffi = $this->ffi();
        $shim = $ffi->raphp_init('', 0);

        $status = $ffi->raphp_send($shim, '#panic', 6); // a Rust panic; if it unwound into PHP this test process would die

        self::assertSame(-3, $status, 'the panic became an error code');
        self::assertStringContainsString('a command panicked on purpose', $this->lastError($ffi, $shim));
        self::assertSame(-2, $ffi->raphp_send($shim, '[]', 2), 'poisoned: every later call is refused');
        self::assertStringContainsString('a command panicked on purpose', $this->lastError($ffi, $shim), 'the cause is still readable');
        self::assertSame(0, $ffi->raphp_shutdown($shim), 'and shutting down still works');
    }

    public function testTheErrorTextCanBeReadInTwoStepsAndInASmallBuffer(): void
    {
        $ffi = $this->ffi();
        $shim = $ffi->raphp_init('', 0);
        $ffi->raphp_send($shim, '[{"op":"nonsense"}]', 19);

        $full = $ffi->raphp_last_error($shim, null, 0);
        $small = $ffi->new('char[10]');
        $reported = $ffi->raphp_last_error($shim, $small, 10);

        self::assertGreaterThan(10, $full);
        self::assertSame($full, $reported, 'the full length is returned so PHP can ask again');
        self::assertSame(10, strlen(FFI::string($small, 10)));
        $ffi->raphp_shutdown($shim);
    }
}
