<?php

declare(strict_types=1);

namespace Raphp\Tests\Runtime\Libc;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raphp\Runtime\Libc\DescriptorGuard;
use Raphp\Runtime\Libc\LibcException;
use Raphp\Tests\Support\ShimFixture;

final class DescriptorGuardTest extends TestCase
{
    /**
     * Opens a descriptor that is not close-on-exec, the way `php://fd/N` does.
     *
     * @return array{int, resource} its number and the stream
     */
    private function openInheritable(): array
    {
        $before = ShimFixture::descriptors();
        $stream = fopen('php://fd/0', 'r');
        self::assertNotFalse($stream);
        $new = array_diff_key(ShimFixture::descriptors(), $before);
        self::assertCount(1, $new, 'one new descriptor');

        return [(int) array_key_first($new), $stream];
    }

    private function closesOnExec(int $descriptor): bool
    {
        $info = file_get_contents("/proc/self/fdinfo/{$descriptor}");
        self::assertNotFalse($info);
        if (preg_match('/^flags:\s+(\d+)/m', $info, $match) !== 1) {
            self::fail("no flags in /proc/self/fdinfo/{$descriptor}");
        }

        return (intval($match[1], 8) & 0o2000000) !== 0;
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function ways(): iterable
    {
        yield 'with close_range' => [true];
        yield 'one descriptor at a time (an older kernel)' => [false];
    }

    #[DataProvider('ways')]
    public function testItMarksTheDescriptorsFromThreeUpAndLeavesTheStandardOnesAlone(bool $useCloseRange): void
    {
        [$descriptor, $stream] = $this->openInheritable();
        self::assertGreaterThanOrEqual(3, $descriptor);
        self::assertFalse($this->closesOnExec($descriptor), 'PHP\'s duplicate of a descriptor is inheritable: that is the problem');
        $standard = [0 => $this->closesOnExec(0), 1 => $this->closesOnExec(1), 2 => $this->closesOnExec(2)];

        (new DescriptorGuard($useCloseRange))->closeOnExecFrom();

        self::assertTrue($this->closesOnExec($descriptor));
        self::assertSame($standard, [0 => $this->closesOnExec(0), 1 => $this->closesOnExec(1), 2 => $this->closesOnExec(2)], 'descriptors 0 to 2 are not touched');
        self::assertNotFalse(fstat($stream), 'and the stream still works');
    }

    #[DataProvider('ways')]
    public function testItStopsAtTheFirstNumberItIsGiven(bool $useCloseRange): void
    {
        [$descriptor, $stream] = $this->openInheritable();

        (new DescriptorGuard($useCloseRange))->closeOnExecFrom($descriptor + 1);

        self::assertFalse($this->closesOnExec($descriptor), 'below the first number: left as it was');
        self::assertNotFalse(fstat($stream));
    }

    #[DataProvider('ways')]
    public function testTheFirstNumberItIsGivenIsMarkedToo(bool $useCloseRange): void
    {
        [$descriptor, $stream] = $this->openInheritable();

        (new DescriptorGuard($useCloseRange))->closeOnExecFrom($descriptor);

        self::assertTrue($this->closesOnExec($descriptor));
        self::assertNotFalse(fstat($stream));
    }

    public function testWhenLibcCannotBeLoadedItIsALibcExceptionThatSaysSo(): void
    {
        try {
            (new DescriptorGuard(library: '/nonexistent/libc.so'))->closeOnExecFrom();
            self::fail('there is no such library');
        } catch (LibcException $exception) {
            self::assertStringContainsString('/nonexistent/libc.so could not be loaded', $exception->getMessage());
        }
    }

    public function testItIsHarmlessToRunTwice(): void
    {
        [$descriptor, $stream] = $this->openInheritable();
        $guard = new DescriptorGuard();

        $guard->closeOnExecFrom();
        $guard->closeOnExecFrom();

        self::assertTrue($this->closesOnExec($descriptor));
        self::assertNotFalse(fstat($stream));
    }
}
