<?php

declare(strict_types=1);

namespace Raphp\Tests\Ui\Ffi;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Raphp\Ipc\Codec\JsonLineCodec;
use Raphp\Ipc\Message\EnvelopeReader;
use Raphp\Ipc\Message\Hello;
use Raphp\Ipc\Protocol;
use Raphp\Process\ChannelFactory;
use Raphp\Process\ProcessSpawner;
use Raphp\Process\WorkerHandle;
use Raphp\Rpc\HandlerRegistry;
use Raphp\Rpc\RpcPeer;
use Raphp\Rpc\RpcPeerOptions;
use Raphp\Tests\Support\AsyncTestCase;
use Raphp\Tests\Support\ShimEventRecorder;
use Raphp\Tests\Support\ShimFixture;
use Raphp\Tests\Support\WorkerFixture;
use Raphp\Ui\Ffi\Shim;

/**
 * The shim with a REAL window, driven from PHP (plan P3.6). These open a window on the session they run in, so
 * they do nothing unless `RAPHP_WINDOW_TESTS=1` is set, and `make window-test` runs each in its own process:
 * winit allows one event loop per process for its whole life (F61), so two cannot share one.
 */
#[Group('window')]
final class ShimWindowTest extends AsyncTestCase
{
    /** @var list<WorkerHandle> */
    private array $handles = [];

    /** @var list<RpcPeer> */
    private array $peers = [];

    private ?Shim $shim = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RAPHP_WINDOW_TESTS') !== '1') {
            self::markTestSkipped('opens a real window on your session: run `make window-test`');
        }

        $this->handles = [];
        $this->peers = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->peers as $peer) {
            $peer->close();
        }

        foreach ($this->handles as $handle) {
            $handle->kill();

            try {
                $handle->waitForExit(new TimeoutCancellation(3.0));
            } catch (CancelledException) {
                // nothing more can be done
            }

            $handle->close();
        }

        $this->shim?->close();
        parent::tearDown();
    }

    private function startWorker(string $name): RpcPeer
    {
        $handle = (new ProcessSpawner(new ChannelFactory(), new NullLogger()))->spawn(WorkerFixture::spec($name));
        $this->handles[] = $handle;
        $peer = new RpcPeer($handle->transport, new JsonLineCodec(), new EnvelopeReader(), new NullLogger(), new HandlerRegistry(), options: new RpcPeerOptions(), hello: new Hello(Protocol::VERSION, 'master', 'test', posix_getpid()));
        $this->peers[] = $peer;
        $peer->start();
        $peer->awaitHandshake(new TimeoutCancellation(10.0));

        return $peer;
    }

    /**
     * @return array<int, string> the worker's descriptors with the numbers in the targets taken out
     */
    private function workerDescriptors(RpcPeer $worker): array
    {
        $descriptors = $worker->call('fds', [], 10.0);
        self::assertIsArray($descriptors);

        $shapes = [];
        foreach ($descriptors as $fd => $target) {
            self::assertIsString($target);
            $shapes[(int) $fd] = (string) preg_replace('/\[\d+]/', '[]', $target);
        }

        ksort($shapes);

        return $shapes;
    }

    /**
     * @return int how many frames window 1 has drawn
     */
    private function framesDrawn(Shim $shim): int
    {
        $windows = $shim->diagnostics()['windows'] ?? [];
        $entry = is_array($windows) ? ($windows['1'] ?? []) : [];

        return is_array($entry) && is_int($entry['frames_drawn'] ?? null) ? $entry['frames_drawn'] : 0;
    }

    private function openWindow(): Shim
    {
        $shim = ShimFixture::library()->start(['backend' => 'window']);
        $this->shim = $shim;
        $recorder = new ShimEventRecorder($shim);
        $batch = $shim->send([
            ['op' => 'window.create', 'id' => 1, 'title' => 'Raphp PHP window test', 'width' => 320, 'height' => 200],
            ['op' => 'widget.create', 'id' => 1, 'type' => 'label', 'props' => ['text' => 'a']],
            ['op' => 'window.set_root', 'id' => 1, 'widget' => 1],
        ]);
        $events = $recorder->until(ShimEventRecorder::batchDone($batch), 10.0);

        self::assertSame([], array_values(array_filter($events, static fn($event): bool => $event->type === 'error')), 'the window was refused');
        self::assertTrue($this->eventually(fn(): bool => $this->framesDrawn($shim) >= 1, 10.0), 'the first frame was never drawn');

        return $shim;
    }

    public function testARealWindowOpensDrawsAndIsClosedFromPhp(): void
    {
        $shim = $this->openWindow();
        $recorder = new ShimEventRecorder($shim);

        $batch = $shim->send([['op' => 'window.destroy', 'id' => 1]]);
        $recorder->until(ShimEventRecorder::batchDone($batch), 10.0);

        self::assertSame(2, $shim->diagnostics()['batches'], 'the open and the destroy are the only batches');
    }

    /**
     * R17 with a real window: the master now holds the display connection and the window system's other
     * descriptors, and a worker started beside them must inherit none of them.
     */
    public function testAWorkerStartedWhileARealWindowIsOpenInheritsOnlyWhatItIsGiven(): void
    {
        $baseline = $this->workerDescriptors($this->startWorker('without'));
        $this->openWindow();

        $withWindow = $this->workerDescriptors($this->startWorker('with'));

        self::assertSame($baseline, $withWindow, 'the very same descriptors as a worker started before the window existed');
        self::assertSame([], array_values(array_filter($withWindow, static fn(string $target): bool => str_contains($target, 'wayland') || str_contains($target, 'X11') || str_contains($target, 'dbus'))), 'no display or bus connection');
    }
}
