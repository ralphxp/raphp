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
use Raphp\Process\SpawnException;
use Raphp\Process\WorkerHandle;
use Raphp\Rpc\HandlerRegistry;
use Raphp\Rpc\RpcPeer;
use Raphp\Rpc\RpcPeerOptions;
use Raphp\Runtime\Libc\DescriptorGuard;
use Raphp\Tests\Support\AsyncTestCase;
use Raphp\Tests\Support\ShimFixture;
use Raphp\Tests\Support\WorkerFixture;
use Raphp\Ui\Ffi\Shim;
use Raphp\Ui\Ffi\ShimEvent;

/**
 * R17: a worker is started with `proc_open` while a live, multi-threaded shim exists in the master (plan P3.5).
 *
 * The contract these tests pin: **a worker inherits only what the spawner names in `proc_open`** (descriptors 0 to 3:
 * stdin, stdout, stderr and the channel), and nothing the shim owns, nor PHP's watcher of it, nor anything else
 * the master holds. The first version of these tests failed: PHP's `php://fd/N` makes a duplicate of the shim's
 * wake-up descriptor that is *not* close-on-exec, and the worker inherited it (as it did PHP's own leftovers, F59).
 * The spawner now sweeps close-on-exec over the master's descriptors just before it starts a worker (D51).
 * These run with a headless shim; P3.6 repeats them with a real window open, where the display connection and
 * the other descriptors of the window system are also in the master.
 */
#[Group('slow')]
final class ShimWorkerSpawnTest extends AsyncTestCase
{
    private ProcessSpawner $spawner;

    /** @var list<WorkerHandle> */
    private array $handles = [];

    /** @var list<RpcPeer> */
    private array $peers = [];

    /** @var list<Shim> */
    private array $shims = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->spawner = new ProcessSpawner(new ChannelFactory(), new NullLogger());
        $this->handles = [];
        $this->peers = [];
        $this->shims = [];
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

        foreach ($this->shims as $shim) {
            $shim->close();
        }

        parent::tearDown();
    }

    private function startWorker(string $name = 'w1'): RpcPeer
    {
        $handle = $this->spawner->spawn(WorkerFixture::spec($name));
        $this->handles[] = $handle;
        $peer = new RpcPeer($handle->transport, new JsonLineCodec(), new EnvelopeReader(), new NullLogger(), new HandlerRegistry(), options: new RpcPeerOptions(), hello: new Hello(Protocol::VERSION, 'master', 'test', posix_getpid()));
        $this->peers[] = $peer;
        $peer->start();
        $peer->awaitHandshake(new TimeoutCancellation(10.0));

        return $peer;
    }

    /**
     * The worker's descriptors, with the numbers in the targets taken out (an inode differs from run to run).
     *
     * @return array<int, string>
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

    private function startBusyShim(): Shim
    {
        $shim = ShimFixture::library()->start(['refresh_hz' => 1000]);
        $this->shims[] = $shim;
        $shim->listen(static function (ShimEvent $event): void {});
        $shim->send([
            ['op' => 'window.create', 'id' => 1, 'width' => 200, 'height' => 100],
            ['op' => 'widget.create', 'id' => 1, 'type' => 'column'],
            ['op' => 'widget.create', 'id' => 2, 'type' => 'label', 'props' => ['text' => 'x']],
            ['op' => 'widget.append', 'parent' => 1, 'child' => 2],
            ['op' => 'window.set_root', 'id' => 1, 'widget' => 1],
        ]);

        return $shim;
    }

    public function testAWorkerStartedWhileTheShimRunsInheritsNothingThatTheShimOwns(): void
    {
        $baseline = $this->workerDescriptors($this->startWorker('without'));
        self::assertSame('/dev/null', $baseline[0], 'standard input is the one the spawner gave the worker');
        self::assertSame('socket:[]', $baseline[3], 'and the channel is on 3');

        $script = $_SERVER['SCRIPT_FILENAME'];
        self::assertIsString($script);
        $master = realpath($script);
        self::assertIsString($master);
        self::assertNotContains($master, $baseline, 'the master\'s own script file (F59) does not reach a worker either');

        $shim = $this->startBusyShim();
        $shim->advance();
        $withShim = $this->workerDescriptors($this->startWorker('with'));

        self::assertSame($baseline, $withShim, 'the very same descriptors as a worker started with no shim in the master: the shim adds none');
        self::assertSame(
            count(array_keys($baseline, 'anon_inode:[eventfd]', true)),
            count(array_keys($withShim, 'anon_inode:[eventfd]', true)),
            'neither the shim\'s eventfd nor PHP\'s duplicate of it reaches the worker (the eventfd it has is its own loop\'s)',
        );
    }

    public function testWhenTheSweepCannotRunNoWorkerIsStartedAndNothingIsLeftOpen(): void
    {
        $spawner = new ProcessSpawner(new ChannelFactory(), new NullLogger(), new DescriptorGuard(library: '/nonexistent/libc.so'));
        $before = ShimFixture::descriptors();

        try {
            $spawner->spawn(WorkerFixture::spec('never'));
            self::fail('a worker must not be started when its descriptors cannot be kept from it');
        } catch (SpawnException $exception) {
            self::assertStringContainsString('could not start the worker "never"', $exception->getMessage());
            self::assertStringContainsString('/nonexistent/libc.so could not be loaded', $exception->getMessage());
        }

        self::assertCount(count($before), ShimFixture::descriptors(), 'the channel that was made is closed again');
    }

    public function testWorkersCanBeStartedAgainAndAgainWhileTheUiThreadIsBusyAndTheShimStaysWhole(): void
    {
        $shim = $this->startBusyShim();
        $baseline = null;

        for ($round = 1; $round <= 6; $round++) {
            for ($change = 0; $change < 20; $change++) {
                $shim->send([['op' => 'widget.set', 'id' => 2, 'props' => ['text' => "round {$round} change {$change}"]]]);
            }

            $descriptors = $this->workerDescriptors($this->startWorker("w{$round}"));
            $baseline ??= $descriptors;
            self::assertSame($baseline, $descriptors, "round {$round}");
        }

        $last = $shim->send([]);
        $done = false;
        $shim->listen(static function (ShimEvent $event) use ($last, &$done): void {
            $done = $done || ($event->type === 'batch.done' && ($event->fields['batch'] ?? null) === $last);
        });
        self::assertTrue($this->eventually(static function () use (&$done): bool {
            return $done;
        }), 'the shim still applies batches and reports them after six workers were started');
        $shim->advance();
        $windows = $shim->diagnostics()['windows'];
        self::assertIsArray($windows);
        self::assertIsArray($windows['1']);
        self::assertGreaterThan(0, $windows['1']['frames_drawn'], 'and still draws');
    }

    public function testAWorkerKeepsRunningWhenTheShimIsClosedAndTheShimIsUnharmedByTheWorkerEnding(): void
    {
        $shim = $this->startBusyShim();
        $peer = $this->startWorker();

        $shim->close();

        self::assertSame(['text' => 'pong'], $peer->call('echo', ['text' => 'pong'], 10.0), 'closing the shim does not touch the worker');
    }
}
