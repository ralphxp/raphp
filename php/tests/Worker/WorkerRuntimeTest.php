<?php

declare(strict_types=1);

namespace Raphp\Tests\Worker;

use Amp\CancelledException;

use function Amp\delay;

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
use Raphp\Tests\Support\ChildProcess;
use Raphp\Tests\Support\WorkerFixture;
use Raphp\Worker\WorkerRuntime;

/**
 * The worker's own start-up and shutdown, run through the real entry code (the worker fixture is a
 * thin script around {@see WorkerRuntime}).
 */
#[Group('slow')]
final class WorkerRuntimeTest extends AsyncTestCase
{
    /** @var list<WorkerHandle> */
    private array $handles = [];

    /** @var list<RpcPeer> */
    private array $peers = [];

    protected function setUp(): void
    {
        parent::setUp();
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

        parent::tearDown();
    }

    /**
     * @param list<string> $extraArguments
     *
     * @return array{WorkerHandle, RpcPeer}
     */
    private function start(array $extraArguments = []): array
    {
        $handle = (new ProcessSpawner(new ChannelFactory(), new NullLogger()))->spawn(WorkerFixture::spec('w1', extraArguments: $extraArguments));
        $this->handles[] = $handle;
        $peer = new RpcPeer($handle->transport, new JsonLineCodec(), new EnvelopeReader(), new NullLogger(), new HandlerRegistry(), options: new RpcPeerOptions(), hello: new Hello(Protocol::VERSION, 'master', 'test', posix_getpid()));
        $this->peers[] = $peer;
        $peer->start();
        $peer->awaitHandshake(new TimeoutCancellation(10.0));

        return [$handle, $peer];
    }

    // ------------------------------------------------------------ start-up errors

    public function testACommandLineThatIsNotAWorkersEndsWithUsageErrorAndAMessageOnStderr(): void
    {
        $result = (new ChildProcess())->run(WorkerFixture::script('worker.php'), ['--name=a'], timeout: 20.0);

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('--role=worker is missing', $result->stderr);
    }

    public function testWithoutAChannelOnDescriptorThreeTheWorkerEndsWithItsOwnExitCode(): void
    {
        $result = (new ChildProcess())->run(WorkerFixture::script('worker.php'), ['--role=worker', '--name=a', '--parent-pid=' . posix_getpid()], timeout: 20.0);

        self::assertSame(WorkerRuntime::EXIT_NO_CHANNEL, $result->exitCode);
    }

    public function testAWorkerWhoseMasterNeverSaysHelloGivesUpWithAFailureCode(): void
    {
        $handle = (new ProcessSpawner(new ChannelFactory(), new NullLogger()))->spawn(WorkerFixture::spec('lonely', extraArguments: ['--handshake-timeout=0.4']));
        $this->handles[] = $handle;
        $started = hrtime(true);

        $status = $handle->waitForExit(new TimeoutCancellation(10.0));

        self::assertSame(WorkerRuntime::EXIT_FAILURE, $status->code);
        self::assertGreaterThanOrEqual(0.35, (hrtime(true) - $started) / 1e9, 'it waited for the hello for the time it was given');
    }

    // ------------------------------------------------------------ the end

    public function testAByeFromTheMasterEndsTheWorkerCleanly(): void
    {
        [$handle, $peer] = $this->start();

        $peer->shutdown('done');
        $status = $handle->waitForExit(new TimeoutCancellation(5.0));

        self::assertTrue($status->isSuccess(), $status->describe());
    }

    public function testAProtocolViolationByTheMasterEndsTheWorkerWithAFailureCode(): void
    {
        $handle = (new ProcessSpawner(new ChannelFactory(), new NullLogger()))->spawn(WorkerFixture::spec('w1'));
        $this->handles[] = $handle;
        $hello = '{"kind":"hello","proto":1,"role":"master","name":"test","pid":' . posix_getpid() . ',"caps":[]}';

        $handle->transport->write($hello . "\nthis is not json\n");
        $status = $handle->waitForExit(new TimeoutCancellation(5.0));

        self::assertSame(WorkerRuntime::EXIT_FAILURE, $status->code);
    }

    public function testADifferentProtocolVersionFromTheMasterEndsTheWorkerWithAFailureCode(): void
    {
        $handle = (new ProcessSpawner(new ChannelFactory(), new NullLogger()))->spawn(WorkerFixture::spec('w1'));
        $this->handles[] = $handle;

        $handle->transport->write('{"kind":"hello","proto":2,"role":"master","name":"test","pid":1,"caps":[]}' . "\n");
        $status = $handle->waitForExit(new TimeoutCancellation(5.0));

        self::assertSame(WorkerRuntime::EXIT_FAILURE, $status->code);
    }

    // ------------------------------------------------------------ signals

    public function testCtrlCBelongsToTheMasterSoAWorkerIgnoresSigint(): void
    {
        [$handle, $peer] = $this->start();

        $handle->signal(SIGINT);
        delay(0.4);

        self::assertTrue($handle->isRunning(), 'a terminal Ctrl-C reaches every process in the group; the worker must not die of it');
        self::assertSame($handle->pid, $peer->call('pid', timeout: 5.0));
    }

    public function testSigtermEndsABusyWorkerThatInstalledNoHandler(): void
    {
        // The contrast to finding F43: the default action does not depend on the event loop.
        [$handle, $peer] = $this->start();
        self::assertSame('spinning', $peer->call('spin', timeout: 5.0));
        delay(0.3);

        $handle->terminate();
        $status = $handle->waitForExit(new TimeoutCancellation(3.0));

        self::assertSame(SIGTERM, $status->signal);
    }
}
