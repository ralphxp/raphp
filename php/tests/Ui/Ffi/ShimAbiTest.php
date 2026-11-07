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
        self::assertSame(-6, $ffi->RAPHP_STATUS_BUFFER_TOO_SMALL);
    }

    public function testTheAbiVersionIsMajorThenMinor(): void
    {
        $version = $this->ffi()->raphp_abi_version();
        self::assertIsInt($version);

        self::assertSame(0, $version >> 16, 'major');
        self::assertSame(2, $version & 0xFFFF, 'minor');
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

    /**
     * Waits, the way the master's loop will, for the event descriptor to become readable, and clears it.
     */
    private function waitForWakeUp(FFI $ffi, mixed $shim): bool
    {
        $fd = $ffi->raphp_event_fd($shim);
        self::assertIsInt($fd);
        self::assertGreaterThanOrEqual(0, $fd);
        $stream = fopen("php://fd/{$fd}", 'r');
        self::assertNotFalse($stream);
        $read = [$stream];
        $write = null;
        $except = null;
        $ready = stream_select($read, $write, $except, 2);
        if ($ready === 1) {
            fread($stream, 8); // clear first ...
        }

        fclose($stream);

        return $ready === 1;
    }

    /**
     * Drains with a buffer of `$capacity` bytes the way PHP must: growing it when an event does not fit.
     *
     * @return list<string>
     */
    private function drain(FFI $ffi, mixed $shim, int $capacity): array
    {
        $lines = [];
        $buffer = $ffi->new("char[{$capacity}]");
        while (true) {
            $result = $ffi->raphp_poll($shim, $buffer, $capacity);
            self::assertIsInt($result);
            if ($result === 0) {
                return $lines;
            }

            if ($result === $ffi->RAPHP_STATUS_BUFFER_TOO_SMALL) {
                $needed = $ffi->raphp_next_event_size($shim);
                self::assertIsInt($needed);
                self::assertGreaterThan($capacity, $needed);
                $capacity = $needed;
                $buffer = $ffi->new("char[{$capacity}]");

                continue;
            }

            if ($result < 1) {
                self::fail("raphp_poll returned the status {$result}");
            }

            array_push($lines, ...explode("\n", rtrim(FFI::string($buffer, $result), "\n")));
        }
    }

    public function testAnEventWakesTheLoopAndIsReadWithPoll(): void
    {
        $ffi = $this->ffi();
        $shim = $ffi->raphp_init('', 0);
        $command = '#emit {"type":"hello","n":1}';

        self::assertSame(0, $ffi->raphp_send($shim, $command, strlen($command)));

        self::assertTrue($this->waitForWakeUp($ffi, $shim), 'the descriptor became readable');
        self::assertSame(['{"type":"hello","n":1}'], $this->drain($ffi, $shim, 4096));
        self::assertSame([], $this->drain($ffi, $shim, 4096), 'then nothing');
        $ffi->raphp_shutdown($shim);
    }

    public function testALargeEventThatDoesNotFitIsNotLostAndDoesNotBlockTheNextOne(): void
    {
        $ffi = $this->ffi();
        $shim = $ffi->raphp_init('', 0);
        $big = '#emit-large 100000';
        $after = '#emit {"type":"after"}';
        $ffi->raphp_send($shim, $big, strlen($big));
        $ffi->raphp_send($shim, $after, strlen($after));

        $lines = $this->drain($ffi, $shim, 1024); // too small for the first event: drain() grows the buffer

        self::assertCount(2, $lines);
        self::assertStringContainsString('"type":"large"', $lines[0]);
        self::assertSame('{"type":"after"}', $lines[1], 'the event behind the large one arrived');
        $ffi->raphp_shutdown($shim);
    }

    public function testTheLargestEventTheShimQueuesFitsAPollBufferOfTheAdvertisedSize(): void
    {
        $ffi = $this->ffi();
        $limit = $ffi->raphp_max_event_bytes();
        self::assertSame(1_048_576, $limit);
        $shim = $ffi->raphp_init('', 0);
        $command = "#emit-report {$limit}";

        $ffi->raphp_send($shim, $command, strlen($command));
        $lines = $this->drain($ffi, $shim, 4096);

        self::assertCount(1, $lines);
        self::assertStringStartsWith('{"type":"event.too_large","event":"large"', $lines[0]);
        $ffi->raphp_shutdown($shim);
    }

    public function testAFailureIsDeliveredAsALastEventAndTheLoopIsWokenByIt(): void
    {
        $ffi = $this->ffi();
        $shim = $ffi->raphp_init('', 0);
        $poison = '#poison the window system failed';

        $ffi->raphp_send($shim, $poison, strlen($poison));

        self::assertTrue($this->waitForWakeUp($ffi, $shim), 'PHP is woken by the failure and need not wait for its next call');
        self::assertSame(-2, $ffi->raphp_send($shim, '[]', 2));
        self::assertSame(['{"type":"shim.failed","reason":"the window system failed"}'], $this->drain($ffi, $shim, 4096), 'poll still works on a poisoned shim');
        $ffi->raphp_shutdown($shim);
    }
}
