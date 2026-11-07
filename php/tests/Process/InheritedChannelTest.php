<?php

declare(strict_types=1);

namespace Raphp\Tests\Process;

use PHPUnit\Framework\TestCase;
use Raphp\Process\ChannelFactory;
use Raphp\Process\InheritedChannel;
use Raphp\Process\SpawnException;

final class InheritedChannelTest extends TestCase
{
    /**
     * The descriptor number this process has for a path or a socket, found in /proc.
     */
    private function descriptorFor(string $target): int
    {
        $entries = scandir('/proc/self/fd');
        self::assertIsArray($entries);
        foreach ($entries as $fd) {
            if ($fd !== '.' && $fd !== '..' && @readlink("/proc/self/fd/{$fd}") === $target) {
                return (int) $fd;
            }
        }

        self::fail("this process has no descriptor for {$target}");
    }

    public function testASocketOnTheDescriptorBecomesATransport(): void
    {
        $channel = (new ChannelFactory())->create();
        $stat = fstat($channel->child);
        self::assertIsArray($stat);
        $descriptor = $this->descriptorFor("socket:[{$stat['ino']}]");

        $transport = (new InheritedChannel())->open($descriptor);

        $transport->close();
        fclose($channel->parent);
        fclose($channel->child);
        self::addToAssertionCount(1);
    }

    public function testADescriptorThatIsNotOpenIsASpawnError(): void
    {
        try {
            (new InheritedChannel())->open(999);
            self::fail('Expected SpawnException.');
        } catch (SpawnException $exception) {
            self::assertStringContainsString('there is no channel on descriptor 999', $exception->getMessage());
        }
    }

    public function testADescriptorThatIsAFileAndNotASocketIsRefused(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'raphp-fd');
        $file = fopen($path, 'r+');
        self::assertIsResource($file);

        try {
            $descriptor = $this->descriptorFor($path);

            try {
                (new InheritedChannel())->open($descriptor);
                self::fail('Expected SpawnException.');
            } catch (SpawnException $exception) {
                self::assertStringContainsString('is not a socket', $exception->getMessage());
            }
        } finally {
            fclose($file);
            unlink($path);
        }
    }
}
