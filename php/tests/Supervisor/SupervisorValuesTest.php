<?php

declare(strict_types=1);

namespace Raphp\Tests\Supervisor;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raphp\Supervisor\Internal\ProcessMemory;
use Raphp\Supervisor\RecyclePolicy;
use Raphp\Supervisor\RestartPolicy;
use Raphp\Supervisor\Routing;
use Raphp\Supervisor\WorkerDefinition;

final class SupervisorValuesTest extends TestCase
{
    /**
     * @return iterable<string, array{Closure(): object}>
     */
    public static function badValues(): iterable
    {
        yield 'a negative first delay' => [static fn(): object => new RestartPolicy(-0.1)];
        yield 'a longest delay shorter than the first' => [static fn(): object => new RestartPolicy(1.0, 0.5)];
        yield 'no restarts allowed' => [static fn(): object => new RestartPolicy(0.1, 1.0, 0)];
        yield 'a window of zero' => [static fn(): object => new RestartPolicy(0.1, 1.0, 5, 0.0)];
        yield 'no calls before recycling' => [static fn(): object => new RecyclePolicy(maxCalls: 0)];
        yield 'no memory before recycling' => [static fn(): object => new RecyclePolicy(maxMemoryBytes: 0)];
        yield 'a drain timeout of zero' => [static fn(): object => new RecyclePolicy(maxCalls: 5, drainTimeout: 0.0)];
        yield 'a worker name with a space' => [static fn(): object => new WorkerDefinition('my worker', ['/bin/true'])];
        yield 'a worker name starting with a digit' => [static fn(): object => new WorkerDefinition('1main', ['/bin/true'])];
        yield 'an empty command' => [static fn(): object => new WorkerDefinition('main', [])];
        yield 'an empty executable' => [static fn(): object => new WorkerDefinition('main', [''])];
        yield 'a pool of zero' => [static fn(): object => new WorkerDefinition('main', ['/bin/true'], pool: 0)];
        yield 'a pool beyond the limit' => [static fn(): object => new WorkerDefinition('main', ['/bin/true'], pool: 257)];
        yield 'a handshake timeout of zero' => [static fn(): object => new WorkerDefinition('main', ['/bin/true'], handshakeTimeout: 0.0)];
        yield 'a ready timeout of zero' => [static fn(): object => new WorkerDefinition('main', ['/bin/true'], readyTimeout: 0.0)];
        yield 'a shutdown grace of zero' => [static fn(): object => new WorkerDefinition('main', ['/bin/true'], shutdownGrace: 0.0)];
        yield 'a terminate grace of zero' => [static fn(): object => new WorkerDefinition('main', ['/bin/true'], terminateGrace: 0.0)];
    }

    /**
     * @param Closure(): object $build
     */
    #[DataProvider('badValues')]
    public function testAValueThatMakesNoSenseIsRefused(Closure $build): void
    {
        $this->expectException(InvalidArgumentException::class);

        $build();
    }

    public function testTheWaitBeforeARestartDoublesFromTheFirstDelayUpToTheLongest(): void
    {
        $policy = new RestartPolicy(0.1, 10.0);

        $delays = array_map($policy->delayAfter(...), range(1, 9));

        self::assertEqualsWithDelta([0.1, 0.2, 0.4, 0.8, 1.6, 3.2, 6.4, 10.0, 10.0], $delays, 1e-9);
    }

    public function testNoCrashYetMeansTheFirstDelay(): void
    {
        self::assertSame(0.1, (new RestartPolicy(0.1, 10.0))->delayAfter(0));
    }

    public function testTheDefaultsAreTheDocumentedOnes(): void
    {
        $restart = new RestartPolicy();
        $recycle = new RecyclePolicy();
        $definition = new WorkerDefinition('main', ['/bin/true']);

        self::assertSame([0.1, 10.0, 5, 60.0], [$restart->initialDelay, $restart->maxDelay, $restart->maxRestarts, $restart->window]);
        self::assertNull($recycle->maxCalls);
        self::assertNull($recycle->maxMemoryBytes);
        self::assertSame(1, $definition->pool);
        self::assertSame(Routing::RoundRobin, $definition->routing);
        self::assertSame(2.0, $definition->rpc->pingInterval, 'the heartbeat is on for supervised workers');
        self::assertSame(5.0, $definition->shutdownGrace);
        self::assertSame(2.0, $definition->terminateGrace);
    }

    public function testMembersOfAPoolAreNumberedAndASingleWorkerKeepsItsName(): void
    {
        self::assertSame('main', (new WorkerDefinition('main', ['/bin/true']))->memberName(1));
        $pool = new WorkerDefinition('resizer', ['/bin/true'], pool: 3);

        self::assertSame(['resizer-1', 'resizer-2', 'resizer-3'], [$pool->memberName(1), $pool->memberName(2), $pool->memberName(3)]);
    }

    public function testThePoolMayHaveTheMaximumNumberOfMembers(): void
    {
        self::assertSame(256, (new WorkerDefinition('main', ['/bin/true'], pool: 256))->pool);
    }

    public function testTheRoutingNamesAreTheOnesInTheManifest(): void
    {
        self::assertSame('round-robin', Routing::RoundRobin->value);
        self::assertSame('least-busy', Routing::LeastBusy->value);
    }

    // ------------------------------------------------------------ memory

    public function testTheMemoryOfThisProcessIsReadInBytes(): void
    {
        $bytes = (new ProcessMemory())->residentBytes(posix_getpid());

        self::assertNotNull($bytes);
        self::assertGreaterThan(5_000_000, $bytes, 'a PHP process holds more than 5 MB');
        self::assertLessThan(8_000_000_000, $bytes);
    }

    public function testAProcessThatDoesNotExistHasNoMemoryFigure(): void
    {
        self::assertNull((new ProcessMemory())->residentBytes(4_000_000));
    }

    public function testTheFigureIsTakenFromTheVmRssLineInKilobytes(): void
    {
        $root = sys_get_temp_dir() . '/raphp-proc-' . bin2hex(random_bytes(4));
        mkdir("{$root}/42", 0o700, true);
        mkdir("{$root}/43", 0o700, true);
        file_put_contents("{$root}/42/status", "Name:\tphp\nVmPeak:\t  999999 kB\nVmRSS:\t   12345 kB\nVmData:\t 5 kB\n");
        file_put_contents("{$root}/43/status", "Name:\tphp\nNothingUseful: 1\n");

        try {
            self::assertSame(12345 * 1024, (new ProcessMemory($root))->residentBytes(42));
            self::assertNull((new ProcessMemory($root))->residentBytes(43), 'a status file without VmRSS (a kernel thread) gives no figure');
        } finally {
            @unlink("{$root}/42/status");
            @unlink("{$root}/43/status");
            @rmdir("{$root}/42");
            @rmdir("{$root}/43");
            @rmdir($root);
        }
    }
}
