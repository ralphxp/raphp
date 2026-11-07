<?php

declare(strict_types=1);

namespace Raphp\Tests\Process;

use PHPUnit\Framework\TestCase;
use Raphp\Process\ChannelFactory;

final class ChannelFactoryTest extends TestCase
{
    private const int O_CLOEXEC = 0o2000000;

    /**
     * Whether the descriptor behind a stream is close-on-exec, read from /proc.
     *
     * @param resource $stream
     */
    private function isCloseOnExec(mixed $stream): bool
    {
        $stat = fstat($stream);
        self::assertIsArray($stat);
        $inode = $stat['ino'];

        $entries = scandir('/proc/self/fd');
        self::assertIsArray($entries);
        foreach ($entries as $fd) {
            if ($fd === '.' || $fd === '..' || @readlink("/proc/self/fd/{$fd}") !== "socket:[{$inode}]") {
                continue;
            }

            $info = (string) file_get_contents("/proc/self/fdinfo/{$fd}");
            if (preg_match('/^flags:\s+(\d+)/m', $info, $found) !== 1) {
                self::fail("no flags in /proc/self/fdinfo/{$fd}");
            }

            return (octdec($found[1]) & self::O_CLOEXEC) !== 0;
        }

        self::fail("no descriptor found for socket {$inode}");
    }

    public function testBothEndsAreCloseOnExecSoNoChildInheritsThemByAccident(): void
    {
        $channel = (new ChannelFactory())->create();

        self::assertTrue($this->isCloseOnExec($channel->parent));
        self::assertTrue($this->isCloseOnExec($channel->child));

        fclose($channel->parent);
        fclose($channel->child);
    }

    public function testWhatIsWrittenToOneEndArrivesAtTheOtherInBothDirections(): void
    {
        $channel = (new ChannelFactory())->create();

        fwrite($channel->parent, "to the worker\n");
        fwrite($channel->child, "to the master\n");

        self::assertSame("to the worker\n", fgets($channel->child));
        self::assertSame("to the master\n", fgets($channel->parent));

        fclose($channel->parent);
        fclose($channel->child);
    }

    public function testClosingOneEndGivesEndOfInputAtTheOther(): void
    {
        $channel = (new ChannelFactory())->create();

        fclose($channel->child);

        self::assertSame('', (string) fread($channel->parent, 10));
        self::assertTrue(feof($channel->parent));

        fclose($channel->parent);
    }

    public function testEveryChannelIsItsOwnPair(): void
    {
        $factory = new ChannelFactory();
        $first = $factory->create();
        $second = $factory->create();

        fwrite($first->parent, 'only for the first');

        stream_set_blocking($second->child, false);

        self::assertSame('', (string) fread($second->child, 10), 'the second pair must not see what was written to the first');
        self::assertSame('only for the first', fread($first->child, 100));

        foreach ([$first->parent, $first->child, $second->parent, $second->child] as $stream) {
            fclose($stream);
        }
    }
}
