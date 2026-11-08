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
            self::markTestSkipped('the test build of the shim does not exist: run make shim-test');
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
        self::assertSame(-7, $ffi->RAPHP_STATUS_CLOSED);
    }

    public function testTheAbiVersionIsMajorThenMinor(): void
    {
        $version = $this->ffi()->raphp_abi_version();
        self::assertIsInt($version);

        self::assertSame(0, $version >> 16, 'major');
        self::assertSame(4, $version & 0xFFFF, 'minor');
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

    public function testAnInvalidBatchIsRefusedAtOnceAndTheShimStaysUsable(): void
    {
        $ffi = $this->ffi();
        $shim = $ffi->raphp_init('', 0);

        $status = $ffi->raphp_send($shim, 'not a batch', 11);

        self::assertSame(-1, $status);
        self::assertStringContainsString('not valid JSON', $this->lastError($ffi, $shim));
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
        $ffi->raphp_send($shim, 'not a batch', 11);

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

    /**
     * Sends a batch and returns the events up to and including its `batch.done`.
     *
     * @param array<array-key, mixed> $commands
     *
     * @return list<array<mixed>>
     */
    private function sendAndWait(FFI $ffi, mixed $shim, int $batch, array $commands): array
    {
        $text = json_encode(['batch' => $batch, 'commands' => $commands], JSON_THROW_ON_ERROR);
        self::assertSame(0, $ffi->raphp_send($shim, $text, strlen($text)));

        $events = [];
        $deadline = hrtime(true) + 5_000_000_000;
        while (hrtime(true) < $deadline) {
            $this->waitForWakeUp($ffi, $shim);
            foreach ($this->drain($ffi, $shim, 4096) as $line) {
                $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($event);
                $events[] = $event;
                if (($event['type'] ?? null) === 'batch.done' && ($event['batch'] ?? null) === $batch) {
                    return $events;
                }
            }
        }

        self::fail('the batch was never reported done');
    }

    public function testABatchOfCommandsIsAppliedAndReportedThroughTheEvents(): void
    {
        $ffi = $this->ffi();
        $shim = $ffi->raphp_init('', 0);

        $events = $this->sendAndWait($ffi, $shim, 7, [
            ['op' => 'window.create', 'id' => 1, 'title' => 'Demo'],
            ['op' => 'widget.create', 'id' => 1, 'type' => 'column'],
            ['op' => 'widget.create', 'id' => 2, 'type' => 'label', 'props' => ['text' => 'Hello']],
            ['op' => 'widget.append', 'parent' => 1, 'child' => 2],
            ['op' => 'window.set_root', 'id' => 1, 'widget' => 1],
            ['op' => 'widget.set', 'id' => 99, 'props' => ['text' => 'nobody']],
        ]);

        self::assertCount(2, $events);
        self::assertSame('error', $events[0]['type']);
        self::assertSame(7, $events[0]['batch']);
        self::assertSame(5, $events[0]['index']);
        self::assertSame('unknown_widget', $events[0]['code']);
        self::assertSame(99, $events[0]['target']);
        self::assertEquals(['type' => 'batch.done', 'batch' => 7, 'applied' => 5, 'failed' => 1], $events[1], 'the key order of the shim\'s JSON does not matter');
        $ffi->raphp_shutdown($shim);
    }

    public function testTheDiagnosticsCanBeReadThroughFfi(): void
    {
        $ffi = $this->ffi();
        $shim = $ffi->raphp_init('', 0);
        $this->sendAndWait($ffi, $shim, 1, [['op' => 'window.create', 'id' => 1], ['op' => 'widget.create', 'id' => 1, 'type' => 'label']]);
        self::assertSame(0, $ffi->raphp_headless_advance($shim, 0), 'a barrier: the first frame has been drawn');

        $needed = $ffi->raphp_diagnostics($shim, null, 0);
        self::assertIsInt($needed);
        if ($needed < 1) {
            self::fail('the diagnostics are empty');
        }

        $buffer = $ffi->new("char[{$needed}]");
        self::assertSame($needed, $ffi->raphp_diagnostics($shim, $buffer, $needed));
        $diagnostics = json_decode(FFI::string($buffer, $needed), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($diagnostics);
        self::assertSame(1, $diagnostics['batches']);
        self::assertSame(2, $diagnostics['commands_applied']);
        self::assertSame(1, $diagnostics['widgets']);
        self::assertSame(['1' => ['redraws_requested' => 1, 'frames_drawn' => 1]], $diagnostics['windows']);
        $ffi->raphp_shutdown($shim);
    }

    public function testAppQuitEndsTheShimInOrderAndLaterCommandsAreRefusedAsClosed(): void
    {
        $ffi = $this->ffi();
        $shim = $ffi->raphp_init('', 0);

        $events = $this->sendAndWait($ffi, $shim, 1, [['op' => 'app.quit']]);

        self::assertSame(['type' => 'app.quit'], $events[0]);
        $deadline = hrtime(true) + 5_000_000_000;
        while ($ffi->raphp_send($shim, '[]', 2) === 0 && hrtime(true) < $deadline) {
            usleep(5_000);
        }

        self::assertSame(-7, $ffi->raphp_send($shim, '[]', 2), 'the shim stopped taking commands');
        self::assertStringContainsString('stopped taking commands', $this->lastError($ffi, $shim));
        self::assertSame(0, $ffi->raphp_shutdown($shim), 'an orderly end, not a timeout');
    }

    /**
     * @param array<array-key, mixed> $commands
     */
    private function send(FFI $ffi, mixed $shim, int $batch, array $commands): void
    {
        $text = json_encode(['batch' => $batch, 'commands' => $commands], JSON_THROW_ON_ERROR);
        self::assertSame(0, $ffi->raphp_send($shim, $text, strlen($text)));
    }

    /**
     * @return array{int, int} how many redraws a window asked for and how many frames were drawn
     */
    private function windowCounters(FFI $ffi, mixed $shim, int $window): array
    {
        $needed = $ffi->raphp_diagnostics($shim, null, 0);
        self::assertIsInt($needed);
        if ($needed < 1) {
            self::fail('the diagnostics are empty');
        }

        $buffer = $ffi->new("char[{$needed}]");
        $ffi->raphp_diagnostics($shim, $buffer, $needed);
        $diagnostics = json_decode(FFI::string($buffer, $needed), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($diagnostics);
        $windows = $diagnostics['windows'];
        self::assertIsArray($windows);
        $entry = $windows[(string) $window];
        self::assertIsArray($entry);
        $requested = $entry['redraws_requested'];
        $drawn = $entry['frames_drawn'];
        self::assertIsInt($requested);
        self::assertIsInt($drawn);

        return [$requested, $drawn];
    }

    public function testTheHeadlessBackendDrawsFramesAtTheClocksPaceAndTheyCanBeRead(): void
    {
        $ffi = $this->ffi();
        $config = '{"refresh_hz":100,"scale":2}';
        $shim = $ffi->raphp_init($config, strlen($config));
        self::assertNotNull($shim);

        $this->send($ffi, $shim, 1, [
            ['op' => 'window.create', 'id' => 1, 'width' => 40, 'height' => 30],
            ['op' => 'widget.create', 'id' => 1, 'type' => 'label', 'props' => ['text' => 'a']],
            ['op' => 'window.set_root', 'id' => 1, 'widget' => 1],
        ]);
        self::assertSame(0, $ffi->raphp_headless_advance($shim, 0));
        self::assertSame([1, 1], $this->windowCounters($ffi, $shim, 1), 'the first frame is drawn at once');

        $this->send($ffi, $shim, 2, [['op' => 'widget.set', 'id' => 1, 'props' => ['text' => 'b']]]);
        self::assertSame(0, $ffi->raphp_headless_advance($shim, 9));
        self::assertSame([2, 1], $this->windowCounters($ffi, $shim, 1), '9 ms after the last frame: still waiting');
        self::assertSame(0, $ffi->raphp_headless_advance($shim, 1));
        self::assertSame([2, 2], $this->windowCounters($ffi, $shim, 1), '10 ms: the frame is drawn');

        $width = $ffi->new('uint32_t');
        $height = $ffi->new('uint32_t');
        $size = $ffi->raphp_headless_frame($shim, 1, null, 0, FFI::addr($width), FFI::addr($height));
        self::assertSame(80 * 60 * 4, $size, 'physical pixels: twice the logical size');
        self::assertSame([80, 60], [$width->cdata, $height->cdata]);
        $pixels = $ffi->new("uint8_t[{$size}]");
        self::assertSame($size, $ffi->raphp_headless_frame($shim, 1, $pixels, $size, null, null));
        self::assertSame("\xff\xff\xff\xff", FFI::string($pixels, 4), 'the corner, where nothing is drawn, is white');
        self::assertSame(-1, $ffi->raphp_headless_frame($shim, 9, null, 0, null, null), 'no such window');
        $ffi->raphp_shutdown($shim);
    }

    public function testABadConfigurationIsRefusedWithAReason(): void
    {
        $ffi = $this->ffi();
        $config = '{"backend":"window","scale":2}'; // refused before any window is made

        $shim = $ffi->raphp_init($config, strlen($config));

        self::assertNull($shim);
        self::assertStringContainsString('applies only to the headless backend', $this->lastError($ffi, null));
    }
}
