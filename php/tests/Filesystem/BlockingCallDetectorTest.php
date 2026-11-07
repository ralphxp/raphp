<?php

declare(strict_types=1);

namespace Raphp\Tests\Filesystem;

use PHPUnit\Framework\Attributes\Group;
use Raphp\Filesystem\CooperativeFilesystem;
use Raphp\Tests\Support\AsyncTestCase;
use Revolt\EventLoop;

/**
 * The "blocking call detector" of plan P2B.1: a timer that should tick every millisecond records the
 * longest time between two ticks, while a large file is read. A built-in blocking read is the control,
 * to show the detector can see a stall at all.
 */
#[Group('slow')]
final class BlockingCallDetectorTest extends AsyncTestCase
{
    private const int FILE_BYTES = 40 * 1024 * 1024;

    private string $file = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = sys_get_temp_dir() . '/raphp-big-' . bin2hex(random_bytes(6));
        $handle = fopen($this->file, 'wb');
        self::assertNotFalse($handle);
        $block = str_repeat('r', 1024 * 1024);
        for ($i = 0; $i < self::FILE_BYTES / strlen($block); $i++) {
            fwrite($handle, $block);
        }

        fclose($handle);
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        parent::tearDown();
    }

    /**
     * @param \Closure(): mixed $work
     *
     * @return array{float, int} the longest gap between ticks in seconds, and the number of ticks
     */
    private function watchTicks(\Closure $work): array
    {
        $last = hrtime(true);
        $longest = 0.0;
        $ticks = 0;
        $timer = EventLoop::repeat(0.001, static function () use (&$last, &$longest, &$ticks): void {
            $now = hrtime(true);
            $longest = max($longest, ($now - $last) / 1e9);
            $last = $now;
            ++$ticks;
        });

        $work();
        \Amp\delay(0.003); // let a tick happen, so a stall that ended with the work is recorded
        EventLoop::cancel($timer);

        return [$longest, $ticks];
    }

    public function testTheLoopKeepsTickingWhileALargeFileIsStreamed(): void
    {
        $fs = new CooperativeFilesystem();
        $total = 0;

        [$longest, $ticks] = $this->watchTicks(function () use ($fs, &$total): void {
            $stream = $fs->openRead($this->file);
            while (($chunk = $stream->read()) !== null) {
                $total += strlen($chunk);
            }
        });

        self::assertSame(self::FILE_BYTES, $total);
        self::assertGreaterThan(10, $ticks);
        self::assertLessThan(0.025, $longest, sprintf('the loop stalled for %.1f ms while the file was streamed', $longest * 1000));
    }

    public function testTheDetectorSeesABuiltInBlockingRead(): void
    {
        $content = '';

        // Three samples of each: one stall caused by something else on the machine must not decide the result.
        $cooperative = [];
        $blocking = [];
        for ($round = 0; $round < 3; $round++) {
            [$cooperative[]] = $this->watchTicks(function (): void {
                $stream = (new CooperativeFilesystem())->openRead($this->file);
                while ($stream->read() !== null) {
                    // reading and dropping the chunks
                }
            });
            [$blocking[]] = $this->watchTicks(function () use (&$content): void {
                // Let the timer start ticking first, so that its next tick is overdue by the length of the stall.
                \Amp\delay(0.003);
                $content = file_get_contents($this->file);
            });
        }

        self::assertSame(self::FILE_BYTES, strlen((string) $content));
        self::assertGreaterThan(min(PHP_FLOAT_MAX, ...$cooperative) * 2, max(0.0, ...$blocking), 'a built-in read must stall the loop for much longer than the streamed one');
    }
}
