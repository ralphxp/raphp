<?php

declare(strict_types=1);

namespace Raphp\Tests\Ipc\Transport;

use function Amp\async;

use Amp\ByteStream\PendingReadError;
use Amp\ByteStream\StreamException;
use Amp\CancelledException;
use Amp\DeferredCancellation;

use function Amp\delay;

use InvalidArgumentException;
use Raphp\Ipc\Transport\StreamTransport;
use Raphp\Ipc\Transport\Transport;
use Raphp\Tests\Support\AsyncTestCase;
use Raphp\Tests\Support\Recorder;
use Revolt\EventLoop;

/**
 * The transports here run over an in-process socket pair. That is allowed only because no child
 * process is spawned, so the descriptors can never be inherited (FRAMEWORK 6.2, finding F1).
 */
final class StreamTransportTest extends AsyncTestCase
{
    /** @var list<StreamTransport> closed again after each test so no descriptor outlives it */
    private array $opened = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->opened = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->opened as $transport) {
            $transport->close();
        }

        parent::tearDown();
    }

    /**
     * @return array{StreamTransport, StreamTransport, resource, resource}
     */
    private function pair(int $chunkSize = StreamTransport::DEFAULT_CHUNK_SIZE): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($sockets);
        [$first, $second] = $sockets;
        $a = StreamTransport::fromResource($first, $chunkSize);
        $b = StreamTransport::fromResource($second, $chunkSize);
        array_push($this->opened, $a, $b);

        return [$a, $b, $first, $second];
    }

    private function readToEnd(Transport $transport): string
    {
        $all = '';
        while (($chunk = $transport->read()) !== null) {
            $all .= $chunk;
        }

        return $all;
    }

    private function writeUntilItFails(Transport $transport): ?StreamException
    {
        try {
            for ($attempt = 0; $attempt < 20; $attempt++) {
                $transport->write(str_repeat('x', 1024));
                delay(0.005);
            }
        } catch (StreamException $exception) {
            return $exception;
        }

        return null;
    }

    private function openDescriptors(): int
    {
        $paths = glob('/proc/self/fd/*');

        return $paths === false ? 0 : count($paths);
    }


    // ------------------------------------------------------------ delivery

    public function testBytesArriveInTheOrderTheyWereWritten(): void
    {
        [$a, $b] = $this->pair();
        // The reader must run at the same time: a unix socket takes only about 280 small writes
        // before a write has to wait for the reader (finding F38).
        $reader = async(fn(): string => $this->readToEnd($b));
        $expected = '';
        for ($number = 0; $number < 2_000; $number++) {
            $a->write("{$number}\n");
            $expected .= "{$number}\n";
        }

        $a->end();

        self::assertSame($expected, $reader->await());
    }

    public function testBothDirectionsWorkAtTheSameTime(): void
    {
        [$a, $b] = $this->pair();
        $aReads = async(fn(): string => $this->readToEnd($a));
        $bReads = async(fn(): string => $this->readToEnd($b));

        $a->write('from a');
        $b->write('from b');
        $a->end();
        $b->end();

        self::assertSame('from b', $aReads->await());
        self::assertSame('from a', $bReads->await());
    }

    public function testAReadNeverReturnsMoreThanTheChunkSize(): void
    {
        [$a, $b] = $this->pair(16);
        $a->write(str_repeat('x', 100));
        $a->end();
        $largest = 0;
        $total = 0;

        while (($chunk = $b->read()) !== null) {
            $largest = max($largest, strlen($chunk));
            $total += strlen($chunk);
        }

        self::assertSame(100, $total);
        self::assertLessThanOrEqual(16, $largest);
    }

    public function testTheDefaultChunkSizeCapsEachRead(): void
    {
        [$a, $b] = $this->pair();
        $writer = async(function () use ($a): void {
            $a->write(str_repeat('y', 300_000));
            $a->end();
        });
        $largest = 0;
        $total = 0;

        while (($chunk = $b->read()) !== null) {
            $largest = max($largest, strlen($chunk));
            $total += strlen($chunk);
        }

        $writer->await();
        self::assertSame(300_000, $total);
        self::assertLessThanOrEqual(StreamTransport::DEFAULT_CHUNK_SIZE, $largest);
    }

    public function testTwoFibersWritingAtOnceNeverInterleaveTheirBytes(): void
    {
        [$a, $b] = $this->pair();
        $size = 4 * 1024 * 1024;
        $one = async(fn() => $a->write(str_repeat('a', $size)));
        $two = async(fn() => $a->write(str_repeat('b', $size)));
        $reader = async(fn(): string => $this->readToEnd($b));

        $one->await();
        $two->await();
        $a->end();
        $received = $reader->await();

        self::assertTrue(
            $received === str_repeat('a', $size) . str_repeat('b', $size) || $received === str_repeat('b', $size) . str_repeat('a', $size),
            'each write must arrive as one unbroken block',
        );
    }

    // ------------------------------------------------------------ back-pressure

    public function testAWriteLargerThanTheSocketBufferWaitsForTheReaderAndTheLoopKeepsRunning(): void
    {
        [$a, $b] = $this->pair();
        $unit = random_bytes(1024);
        $payload = str_repeat($unit, 16 * 1024);
        $written = new Recorder();
        $ticks = new Recorder();
        $writer = async(function () use ($a, $payload, $written): void {
            $a->write($payload);
            $written->add();
            $a->end();
        });
        $ticker = EventLoop::repeat(0.005, static function () use ($ticks): void {
            $ticks->add();
        });

        // Wait until the loop has ticked a few times (up to 2 s, so a slow machine does not fail the
        // test): ticking while the write is stuck proves the loop is not blocked by it.
        $deadline = hrtime(true) + 2_000_000_000;
        while ($ticks->count() < 5 && hrtime(true) < $deadline) {
            delay(0.01);
        }

        self::assertSame(0, $written->count(), 'the write must wait while nobody reads');
        self::assertGreaterThanOrEqual(5, $ticks->count(), 'the event loop must keep running during the wait');

        $hash = hash_init('md5');
        $total = 0;
        while (($chunk = $b->read()) !== null) {
            hash_update($hash, $chunk);
            $total += strlen($chunk);
        }

        $writer->await();
        EventLoop::cancel($ticker);

        self::assertSame(1, $written->count());
        self::assertSame(strlen($payload), $total);
        self::assertSame(md5($payload), hash_final($hash));
    }

    // ------------------------------------------------------------ closing

    public function testEndingOneDirectionLeavesTheOtherOpen(): void
    {
        [$a, $b] = $this->pair();

        $a->write('request');
        $a->end();

        self::assertSame('request', $this->readToEnd($b));

        $b->write('reply');
        $b->end();

        self::assertSame('reply', $this->readToEnd($a));
    }

    public function testBothSidesAreClosedOnceBothHaveEndedAndTheDescriptorsAreFreed(): void
    {
        [$a, $b, $first, $second] = $this->pair();
        $a->end();
        $b->end();
        $this->readToEnd($a);
        $this->readToEnd($b);
        $this->settle();

        self::assertTrue($a->isClosed());
        self::assertTrue($b->isClosed());
        self::assertFalse(is_resource($first), 'the descriptor must be freed, not left to garbage collection');
        self::assertFalse(is_resource($second));
        self::assertSame([], $this->loopErrors);
    }

    public function testEndingOnlyOurOwnSideDoesNotCloseTheTransport(): void
    {
        [$a] = $this->pair();
        $a->end();
        $this->settle();

        self::assertFalse($a->isClosed());
    }

    public function testThePeerEndingItsSideAloneDoesNotCloseOurTransportEither(): void
    {
        [$a, $b] = $this->pair();
        $b->end();

        self::assertNull($a->read());
        $this->settle();

        self::assertFalse($a->isClosed(), 'we can still write, so the transport is not closed');
        $a->write('still works');
        self::assertSame('still works', $b->read());
    }

    public function testTheCloseCallbackWaitsUntilBothDirectionsAreFinished(): void
    {
        [$a, $b] = $this->pair();
        $calls = new Recorder();
        $a->onClose(static function () use ($calls): void {
            $calls->add();
        });

        $a->end();
        $this->settle();

        self::assertSame(0, $calls->count(), 'ending our own side is not closing');

        $b->end();
        $this->readToEnd($a);
        $this->settle();

        self::assertSame(1, $calls->count());
    }

    public function testClosingFreesTheDescriptorAtOnceNotAtTheNextTick(): void
    {
        [$a, , $first] = $this->pair();

        $a->close();

        self::assertFalse(is_resource($first));
    }

    public function testAWriteStartedWhileEndingWaitsIsRefusedAndTheEarlierOnesStillArrive(): void
    {
        [$a, $b] = $this->pair();
        $size = 2 * 1024 * 1024;
        $first = async(fn() => $a->write(str_repeat('f', $size)));
        $ending = async(fn() => $a->end());
        delay(0.02);

        try {
            $a->write('late');
            self::fail('Expected a StreamException for a write after end().');
        } catch (StreamException) {
            self::addToAssertionCount(1);
        }

        $received = $this->readToEnd($b);
        $first->await();
        $ending->await();

        self::assertSame(str_repeat('f', $size), $received);
    }

    public function testEndingTwiceAtOnceIsHarmlessAndBothCallsReturnAfterTheOutputEnded(): void
    {
        [$a, $b] = $this->pair();
        $write = async(fn() => $a->write(str_repeat('t', 1024 * 1024)));
        $one = async(fn() => $a->end());
        $secondReturned = new Recorder();
        $two = async(function () use ($a, $secondReturned): void {
            $a->end();
            $secondReturned->add();
        });

        delay(0.03);
        self::assertSame(0, $secondReturned->count(), 'a second end() must wait for the first, not return early');

        $reader = async(fn(): string => $this->readToEnd($b));

        $one->await();
        $two->await();
        $write->await();

        $received = $reader->await();

        self::assertIsString($received);
        self::assertSame(1024 * 1024, strlen($received));
    }

    public function testClosingWhileEndIsWaitingLetsEndFinishInsteadOfHanging(): void
    {
        [$a] = $this->pair();
        $write = async(fn() => $a->write(str_repeat('c', 8 * 1024 * 1024)));
        $ending = async(fn() => $a->end());
        delay(0.03);

        $a->close();
        $ending->await();

        try {
            $write->await();
            self::fail('Expected the pending write to fail.');
        } catch (StreamException) {
            self::addToAssertionCount(1);
        }

        self::assertTrue($a->isClosed());
    }

    public function testEndingWithNothingPendingReturnsAtOnce(): void
    {
        [$a, $b] = $this->pair();
        $a->write('x');

        $a->end();

        self::assertSame('x', $this->readToEnd($b));
    }

    public function testEndingWaitsForWritesThatAreStillPending(): void
    {
        [$a, $b] = $this->pair();
        $size = 4 * 1024 * 1024;
        $write = async(fn() => $a->write(str_repeat('p', $size)));
        delay(0.02);

        $ending = async(fn() => $a->end());
        $received = $this->readToEnd($b);
        $write->await();
        $ending->await();

        self::assertSame($size, strlen($received), 'end() must let the pending write finish, unlike close()');
    }

    public function testClosingMakesThePeerSeeTheEndOfInput(): void
    {
        [$a, $b] = $this->pair();
        $a->write('last words');
        $a->close();

        self::assertSame('last words', $this->readToEnd($b));
    }

    public function testWritingToAPeerThatIsGoneFailsWithAStreamExceptionNotAFatalError(): void
    {
        [$a, $b] = $this->pair();
        $b->close();

        self::assertInstanceOf(StreamException::class, $this->writeUntilItFails($a));
        self::assertSame([], $this->loopErrors);
    }

    public function testWritingAfterCloseOrEndFails(): void
    {
        [$a, $b] = $this->pair();
        $a->end();

        try {
            $a->write('x');
            self::fail('Expected a StreamException after end().');
        } catch (StreamException) {
            self::addToAssertionCount(1);
        }

        $b->close();

        try {
            $b->write('x');
            self::fail('Expected a StreamException after close().');
        } catch (StreamException) {
            self::addToAssertionCount(1);
        }
    }

    public function testReadingAfterCloseGivesNullAndClosingTwiceIsHarmless(): void
    {
        [$a] = $this->pair();
        $a->close();
        $a->close();

        self::assertNull($a->read());
        self::assertTrue($a->isClosed());
    }

    public function testClosingWhileAReadIsWaitingEndsTheReadWithNullAndBreaksNothing(): void
    {
        [$a] = $this->pair();
        $read = async(fn(): ?string => $a->read());
        delay(0.02);

        $a->close();

        self::assertNull($read->await());
        $this->settle();
        self::assertSame([], $this->loopErrors);
        self::assertTrue($a->isClosed());
    }

    public function testThePeerClosingWhileAReadIsWaitingEndsTheReadWithNull(): void
    {
        [$a, $b] = $this->pair();
        $read = async(fn(): ?string => $a->read());
        delay(0.02);

        $b->close();

        self::assertNull($read->await());
        $this->settle();
        self::assertSame([], $this->loopErrors);
    }

    public function testClosingWhileAWriteIsWaitingForBackPressureFailsTheWriteCleanly(): void
    {
        [$a] = $this->pair();
        $write = async(fn() => $a->write(str_repeat('z', 16 * 1024 * 1024)));
        delay(0.05);

        $a->close();

        try {
            $write->await();
            self::fail('Expected the waiting write to fail.');
        } catch (StreamException) {
            self::addToAssertionCount(1);
        }

        $this->settle();
        self::assertSame([], $this->loopErrors);
    }

    public function testManyCloseCyclesWithPendingReadsLeaveNoStrayDescriptorsOrErrors(): void
    {
        $before = $this->openDescriptors();

        for ($round = 0; $round < 50; $round++) {
            [$a, $b] = $this->pair();
            $reads = [async(fn(): ?string => $a->read()), async(fn(): ?string => $b->read())];
            delay(0.001);
            $a->close();
            $b->close();
            foreach ($reads as $read) {
                self::assertNull($read->await());
            }
        }

        $this->settle();
        self::assertSame([], $this->loopErrors);
        self::assertLessThanOrEqual($before + 2, $this->openDescriptors(), 'descriptors must be freed');
    }

    public function testTheCloseCallbackRunsOnceWhenTheTransportCloses(): void
    {
        [$a] = $this->pair();
        $calls = new Recorder();
        $a->onClose(static function () use ($calls): void {
            $calls->add('closed');
        });

        $a->close();
        $a->close();
        $this->settle();

        self::assertSame(1, $calls->count());
    }

    public function testACloseCallbackAddedAfterTheTransportClosedStillRuns(): void
    {
        [$a] = $this->pair();
        $a->close();
        $calls = new Recorder();

        $a->onClose(static function () use ($calls): void {
            $calls->add('closed');
        });
        $this->settle();

        self::assertSame(1, $calls->count());
    }

    // ------------------------------------------------------------ reading

    public function testAWaitingReadCanBeCancelledAndTheTransportStillWorks(): void
    {
        [$a, $b] = $this->pair();
        $cancellation = new DeferredCancellation();
        $read = async(fn(): ?string => $a->read($cancellation->getCancellation()));
        delay(0.02);

        $cancellation->cancel();

        try {
            $read->await();
            self::fail('Expected CancelledException.');
        } catch (CancelledException) {
            self::addToAssertionCount(1);
        }

        $b->write('after');

        self::assertSame('after', $a->read());
    }

    public function testASecondReadWhileOneIsWaitingIsRefused(): void
    {
        [$a] = $this->pair();
        $first = async(fn(): ?string => $a->read());
        delay(0.01);

        try {
            $a->read();
            self::fail('Expected PendingReadError.');
        } catch (PendingReadError) {
            self::addToAssertionCount(1);
        }

        $a->close();
        $first->await();
    }

    // ------------------------------------------------------------ construction

    public function testAChunkSizeThatIsNotPositiveIsRefused(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($sockets);

        foreach ([0, -1] as $size) {
            try {
                StreamTransport::fromResource($sockets[0], $size);
                self::fail('Expected InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSomethingThatIsNotAStreamIsRefused(): void
    {
        $closed = fopen('php://memory', 'r+');
        self::assertIsResource($closed);
        fclose($closed);

        foreach ([42, 'text', null, $closed, stream_context_create()] as $notAStream) {
            try {
                StreamTransport::fromResource($notAStream);
                self::fail('Expected InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
