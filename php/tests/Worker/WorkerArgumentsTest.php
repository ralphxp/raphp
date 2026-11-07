<?php

declare(strict_types=1);

namespace Raphp\Tests\Worker;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raphp\Worker\WorkerArguments;

final class WorkerArgumentsTest extends TestCase
{
    public function testTheStandardCommandLineIsUnderstood(): void
    {
        $arguments = WorkerArguments::parse(['worker.php', '--role=worker', '--name=resizer-2', '--parent-pid=4242']);

        self::assertSame('resizer-2', $arguments->name);
        self::assertSame(4242, $arguments->parentPid);
        self::assertTrue($arguments->armDeathSignal);
        self::assertSame([], $arguments->options);
        self::assertSame([], $arguments->positional);
    }

    public function testTheOrderOfTheArgumentsDoesNotMatter(): void
    {
        $arguments = WorkerArguments::parse(['worker.php', '--parent-pid=7', '--name=a', '--role=worker']);

        self::assertSame([7, 'a'], [$arguments->parentPid, $arguments->name]);
    }

    public function testTheTestOnlyFlagTurnsTheDeathSignalOff(): void
    {
        $arguments = WorkerArguments::parse(['worker.php', '--role=worker', '--name=a', '--parent-pid=7', '--no-pdeathsig']);

        self::assertFalse($arguments->armDeathSignal);
        self::assertSame([], $arguments->options, 'it is not an option of the application');
    }

    public function testTheApplicationsOwnArgumentsAreKeptApart(): void
    {
        $arguments = WorkerArguments::parse(['worker.php', '--role=worker', '--name=a', '--parent-pid=7', '--verbose', '--limit=10', 'extra', '--note=a=b', 'more']);

        self::assertSame(['verbose' => '1', 'limit' => '10', 'note' => 'a=b'], $arguments->options);
        self::assertSame(['extra', 'more'], $arguments->positional);
    }

    public function testAnOptionGivenTwiceKeepsTheLastValue(): void
    {
        $arguments = WorkerArguments::parse(['worker.php', '--role=worker', '--name=a', '--parent-pid=7', '--limit=1', '--limit=2']);

        self::assertSame(['limit' => '2'], $arguments->options);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function badCommandLines(): iterable
    {
        yield 'no role' => [['w.php', '--name=a', '--parent-pid=7'], '--role=worker is missing'];
        yield 'another role' => [['w.php', '--role=master', '--name=a', '--parent-pid=7'], '--role=worker is missing'];
        yield 'an empty role' => [['w.php', '--role=', '--name=a', '--parent-pid=7'], '--role=worker is missing'];
        yield 'no arguments at all' => [['w.php'], '--role=worker is missing'];
        yield 'no name' => [['w.php', '--role=worker', '--parent-pid=7'], '--name='];
        yield 'a name with a space' => [['w.php', '--role=worker', '--name=my worker', '--parent-pid=7'], '--name='];
        yield 'a name starting with a digit' => [['w.php', '--role=worker', '--name=1a', '--parent-pid=7'], '--name='];
        yield 'an empty name' => [['w.php', '--role=worker', '--name=', '--parent-pid=7'], '--name='];
        yield 'no parent pid' => [['w.php', '--role=worker', '--name=a'], '--parent-pid='];
        yield 'a parent pid of zero' => [['w.php', '--role=worker', '--name=a', '--parent-pid=0'], '--parent-pid='];
        yield 'a negative parent pid' => [['w.php', '--role=worker', '--name=a', '--parent-pid=-5'], '--parent-pid='];
        yield 'a parent pid that is not a number' => [['w.php', '--role=worker', '--name=a', '--parent-pid=abc'], '--parent-pid='];
        yield 'a parent pid in exponent form' => [['w.php', '--role=worker', '--name=a', '--parent-pid=1e3'], '--parent-pid='];
        yield 'a parent pid with a leading zero' => [['w.php', '--role=worker', '--name=a', '--parent-pid=012'], '--parent-pid='];
        yield 'a parent pid that is too long' => [['w.php', '--role=worker', '--name=a', '--parent-pid=12345678901'], '--parent-pid='];
        yield 'an empty parent pid' => [['w.php', '--role=worker', '--name=a', '--parent-pid='], '--parent-pid='];
    }

    /**
     * @param list<string> $argv
     */
    #[DataProvider('badCommandLines')]
    public function testACommandLineThatIsNotAWorkersIsRefusedWithAMessageToPrint(array $argv, string $fragment): void
    {
        try {
            WorkerArguments::parse($argv);
            self::fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString($fragment, $exception->getMessage());
        }
    }

    public function testTheLargestParentPidIsAccepted(): void
    {
        self::assertSame(4194304, WorkerArguments::parse(['w.php', '--role=worker', '--name=a', '--parent-pid=4194304'])->parentPid);
    }
}
