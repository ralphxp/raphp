<?php

declare(strict_types=1);

namespace Raphp\Tests\Process;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raphp\Process\ExitStatus;
use Raphp\Process\OrphanGuard;
use Raphp\Process\WorkerSpec;

final class WorkerSpecTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function goodNames(): iterable
    {
        yield 'a word' => ['main'];
        yield 'with a digit' => ['worker2'];
        yield 'with dash and underscore' => ['image-resizer_v2'];
        yield 'one letter' => ['a'];
        yield 'as long as allowed' => [str_repeat('a', 64)];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badNames(): iterable
    {
        yield 'empty' => [''];
        yield 'starts with a digit' => ['1worker'];
        yield 'starts with a dash' => ['-worker'];
        yield 'has a space' => ['my worker'];
        yield 'has a slash' => ['a/b'];
        yield 'ends with a newline' => ["main\n"];
        yield 'has a dot' => ['a.b'];
        yield 'is not ASCII' => ["w\u{f6}rker"];
        yield 'is too long' => [str_repeat('a', 65)];
    }

    #[DataProvider('goodNames')]
    public function testANameThatIsSafeOnACommandLineAndInALogIsAccepted(string $name): void
    {
        self::assertSame($name, (new WorkerSpec($name, ['/bin/true']))->name);
    }

    #[DataProvider('badNames')]
    public function testAnyOtherNameIsRefused(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WorkerSpec($name, ['/bin/true']);
    }

    public function testACommandNeedsAnExecutable(): void
    {
        foreach ([[], ['']] as $command) {
            try {
                new WorkerSpec('main', $command);
                self::fail('Expected InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTheDefaultsAreTheSafeOnes(): void
    {
        $spec = new WorkerSpec('main', ['/bin/true']);

        self::assertTrue($spec->armDeathSignal);
        self::assertNull($spec->parentPid);
        self::assertNull($spec->cwd);
        self::assertSame([], $spec->environment);
        self::assertSame([], $spec->extraArguments);
    }

    public function testAnExitCodeAndASignalAreDescribedAndOnlyZeroIsSuccess(): void
    {
        self::assertTrue(ExitStatus::exited(0)->isSuccess());
        self::assertFalse(ExitStatus::exited(1)->isSuccess());
        self::assertFalse(ExitStatus::signaled(15)->isSuccess());
        self::assertSame('exited with code 3', ExitStatus::exited(3)->describe());
        self::assertSame('killed by signal 15', ExitStatus::signaled(15)->describe());
        self::assertNull(ExitStatus::exited(3)->signal);
        self::assertNull(ExitStatus::signaled(15)->code);
    }

    public function testTheOrphanGuardAcceptsTheRealParentAndRefusesAnyOtherPid(): void
    {
        $guard = new OrphanGuard(null);

        self::assertTrue($guard->protect(posix_getppid()));
        self::assertFalse($guard->protect(posix_getppid() + 1));
        self::assertFalse($guard->protect(1 === posix_getppid() ? 2 : 1));
        self::assertSame(4, OrphanGuard::EXIT_PARENT_MISMATCH);
    }
}
