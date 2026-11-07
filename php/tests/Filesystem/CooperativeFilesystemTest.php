<?php

declare(strict_types=1);

namespace Raphp\Tests\Filesystem;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use InvalidArgumentException;
use Raphp\Filesystem\CooperativeFilesystem;
use Raphp\Filesystem\FileNotFoundException;
use Raphp\Filesystem\FilesystemException;
use Raphp\Filesystem\FileType;
use Raphp\Tests\Support\AsyncTestCase;
use Revolt\EventLoop;

final class CooperativeFilesystemTest extends AsyncTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/raphp-fs-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    /**
     * @return list<string> every path directly inside the directory, including hidden ones
     */
    private static function entries(string $directory): array
    {
        $names = scandir($directory);

        return array_map(static fn(string $name): string => "{$directory}/{$name}", array_values(array_diff($names === false ? [] : $names, ['.', '..'])));
    }

    protected function tearDown(): void
    {
        foreach (self::entries($this->dir) as $entry) {
            if (is_link($entry) || is_file($entry)) {
                @chmod($entry, 0o600);
                unlink($entry);
            } elseif (is_dir($entry)) {
                foreach (self::entries($entry) as $inner) {
                    unlink($inner);
                }

                rmdir($entry);
            }
        }

        rmdir($this->dir);
        parent::tearDown();
    }

    public function testItWritesAndReadsBackWhatWasWritten(): void
    {
        $fs = new CooperativeFilesystem();
        $content = random_bytes(300_000) . "\0\r\n end";

        $fs->write("{$this->dir}/a.bin", $content);

        self::assertSame($content, $fs->read("{$this->dir}/a.bin"));
        self::assertSame($content, file_get_contents("{$this->dir}/a.bin"));
    }

    public function testAnEmptyFileReadsAsAnEmptyString(): void
    {
        $fs = new CooperativeFilesystem();
        $fs->write("{$this->dir}/empty", '');

        self::assertSame('', $fs->read("{$this->dir}/empty"));
        self::assertSame(0, $fs->stat("{$this->dir}/empty")->size);
    }

    public function testWritingReplacesAndAppendingAdds(): void
    {
        $fs = new CooperativeFilesystem();
        $fs->write("{$this->dir}/f", 'long old content');
        $fs->write("{$this->dir}/f", 'new');
        self::assertSame('new', $fs->read("{$this->dir}/f"));

        $fs->write("{$this->dir}/f", ' and more', append: true);
        self::assertSame('new and more', $fs->read("{$this->dir}/f"));
    }

    public function testChunkSizesBelowAndAroundTheContentLengthAllWork(): void
    {
        $content = str_repeat('0123456789', 25); // 250 bytes
        foreach ([1, 7, 249, 250, 251, 65_536] as $chunk) {
            $fs = new CooperativeFilesystem($chunk, 0.0);
            $fs->write("{$this->dir}/c", $content);

            self::assertSame($content, $fs->read("{$this->dir}/c"), "chunk size {$chunk}");
        }
    }

    public function testAMissingFileIsReportedAsNotFound(): void
    {
        $fs = new CooperativeFilesystem();

        foreach ([fn() => $fs->read("{$this->dir}/nope"), fn() => $fs->stat("{$this->dir}/nope"), fn() => $fs->list("{$this->dir}/nope")] as $call) {
            try {
                $call();
                self::fail('expected a FileNotFoundException');
            } catch (FileNotFoundException $exception) {
                self::assertStringContainsString("{$this->dir}/nope", $exception->getMessage());
            }
        }
    }

    public function testReadingADirectoryIsAnError(): void
    {
        $this->expectException(FilesystemException::class);
        $this->expectExceptionMessageMatches('/it\ is\ a\ directory/');

        (new CooperativeFilesystem())->read($this->dir);
    }

    public function testAFileThatCannotBeReadSaysWhy(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('root can read any file');
        }

        file_put_contents("{$this->dir}/secret", 'x');
        chmod("{$this->dir}/secret", 0o000);

        try {
            (new CooperativeFilesystem())->read("{$this->dir}/secret");
            self::fail('expected an exception');
        } catch (FilesystemException $exception) {
            self::assertNotInstanceOf(FileNotFoundException::class, $exception);
            self::assertStringContainsString('ermission denied', $exception->getMessage());
        }
    }

    public function testAFileLargerThanTheLimitIsRefusedBeforeItIsRead(): void
    {
        $fs = new CooperativeFilesystem();
        $fs->write("{$this->dir}/big", str_repeat('x', 1000));

        self::assertSame(str_repeat('x', 1000), $fs->read("{$this->dir}/big", maxBytes: 1000), 'a file exactly at the limit is allowed');

        $this->expectException(FilesystemException::class);
        $this->expectExceptionMessageMatches('/more\ than\ the\ limit\ of\ 999/');

        $fs->read("{$this->dir}/big", maxBytes: 999);
    }

    public function testWritingWhereNothingCanBeWrittenSaysSo(): void
    {
        try {
            (new CooperativeFilesystem())->write("{$this->dir}/no-such-dir/f", 'x');
            self::fail('expected an exception');
        } catch (FilesystemException $exception) {
            self::assertStringContainsString("{$this->dir}/no-such-dir/f", $exception->getMessage());
            self::assertStringContainsString('cannot write', $exception->getMessage());
        }
    }

    public function testWritingToAFullDiskFails(): void
    {
        if (!file_exists('/dev/full')) {
            self::markTestSkipped('this system has no /dev/full');
        }

        $this->expectException(FilesystemException::class);
        $this->expectExceptionMessageMatches('/cannot write \/dev\/full/');

        (new CooperativeFilesystem())->write('/dev/full', 'data');
    }

    public function testStatReportsTheSpecialPermissionBitsToo(): void
    {
        mkdir("{$this->dir}/shared");
        chmod("{$this->dir}/shared", 0o2775);

        self::assertSame(0o2775, (new CooperativeFilesystem())->stat("{$this->dir}/shared")->permissions);

        rmdir("{$this->dir}/shared");
    }

    public function testStatDescribesFilesDirectoriesAndLinks(): void
    {
        $fs = new CooperativeFilesystem();
        file_put_contents("{$this->dir}/file", 'abcde');
        chmod("{$this->dir}/file", 0o640);
        mkdir("{$this->dir}/sub");
        symlink("{$this->dir}/file", "{$this->dir}/link");

        $file = $fs->stat("{$this->dir}/file");
        self::assertSame(FileType::File, $file->type);
        self::assertSame(5, $file->size);
        self::assertSame(0o640, $file->permissions);
        self::assertEqualsWithDelta(time(), $file->modified, 5);

        self::assertSame(FileType::Directory, $fs->stat("{$this->dir}/sub")->type);
        self::assertSame(FileType::File, $fs->stat("{$this->dir}/link")->type, 'a link is followed by default');
        self::assertSame(FileType::Link, $fs->stat("{$this->dir}/link", followLinks: false)->type);
        self::assertSame(FileType::Other, $fs->stat('/dev/null')->type);

        rmdir("{$this->dir}/sub");
    }

    public function testListGivesSortedNamesWithoutDotEntries(): void
    {
        $fs = new CooperativeFilesystem();
        foreach (['b', 'a', '.hidden', 'C', '10', '9'] as $name) {
            touch("{$this->dir}/{$name}");
        }

        self::assertSame(['.hidden', '10', '9', 'C', 'a', 'b'], $fs->list($this->dir));
    }

    public function testAnEmptyDirectoryListsAsEmpty(): void
    {
        mkdir("{$this->dir}/empty");

        self::assertSame([], (new CooperativeFilesystem())->list("{$this->dir}/empty"));

        rmdir("{$this->dir}/empty");
    }

    public function testAFileIsNotADirectoryToList(): void
    {
        touch("{$this->dir}/plain");

        $this->expectException(FilesystemException::class);
        $this->expectExceptionMessageMatches('/it\ is\ not\ a\ directory/');

        (new CooperativeFilesystem())->list("{$this->dir}/plain");
    }

    public function testALargeDirectoryIsListedCompletelyAcrossSeveralTurnsOfTheLoop(): void
    {
        for ($i = 0; $i < 1300; $i++) {
            touch(sprintf('%s/f%04d', $this->dir, $i));
        }

        $ticks = 0;
        $timer = EventLoop::repeat(0.0, static function () use (&$ticks): void {
            ++$ticks;
        });
        $names = (new CooperativeFilesystem(slice: 0.0))->list($this->dir);
        EventLoop::cancel($timer);

        self::assertCount(1300, $names);
        self::assertSame('f0000', $names[0]);
        self::assertSame('f1299', $names[1299]);
        self::assertGreaterThanOrEqual(4, $ticks, 'the loop ran between the checks (1300 entries, a check every 256)');
    }

    public function testCancellationStopsAReadBetweenChunks(): void
    {
        $fs = new CooperativeFilesystem(1024, 0.0);
        $fs->write("{$this->dir}/long", str_repeat('x', 1_000_000));

        $cancel = new DeferredCancellation();
        EventLoop::delay(0.0, static fn() => $cancel->cancel());

        $this->expectException(CancelledException::class);

        $fs->read("{$this->dir}/long", $cancel->getCancellation());
    }

    public function testCancellationStopsAWriteAndLeavesWhatWasWritten(): void
    {
        $fs = new CooperativeFilesystem(1024, 0.0);
        $cancel = new DeferredCancellation();
        EventLoop::delay(0.0, static fn() => $cancel->cancel());

        try {
            $fs->write("{$this->dir}/partial", str_repeat('x', 1_000_000), $cancel->getCancellation());
            self::fail('expected the write to be cancelled');
        } catch (CancelledException) {
            $size = filesize("{$this->dir}/partial");
            self::assertGreaterThan(0, $size);
            self::assertLessThan(1_000_000, $size);
        }
    }

    public function testACancellationThatIsAlreadyRequestedStopsTheOperationAtOnce(): void
    {
        $fs = new CooperativeFilesystem();
        $fs->write("{$this->dir}/f", 'abc');
        $cancel = new DeferredCancellation();
        $cancel->cancel();

        $this->expectException(CancelledException::class);

        $fs->read("{$this->dir}/f", $cancel->getCancellation());
    }

    public function testAStreamDeliversTheContentInChunksAndThenNull(): void
    {
        $fs = new CooperativeFilesystem(100, 0.0);
        $content = random_bytes(1050);
        $fs->write("{$this->dir}/s", $content);

        $stream = $fs->openRead("{$this->dir}/s");
        $chunks = [];
        while (($chunk = $stream->read()) !== null) {
            $chunks[] = $chunk;
        }

        self::assertSame($content, implode('', $chunks));
        self::assertCount(11, $chunks);
        self::assertSame(100, strlen($chunks[0]));
        self::assertSame(50, strlen($chunks[10]));
        self::assertTrue($stream->isClosed(), 'the stream closes itself at the end');
        self::assertNull($stream->read(), 'and keeps answering null');
    }

    public function testAStreamOfAnEmptyFileEndsAtOnce(): void
    {
        $fs = new CooperativeFilesystem();
        touch("{$this->dir}/none");

        $stream = $fs->openRead("{$this->dir}/none");

        self::assertNull($stream->read());
        self::assertTrue($stream->isClosed());
    }

    public function testAStreamCanBeIteratedWithForeach(): void
    {
        $fs = new CooperativeFilesystem(4, 0.0);
        $fs->write("{$this->dir}/it", 'abcdefghij');

        $seen = [];
        foreach ($fs->openRead("{$this->dir}/it") as $chunk) {
            $seen[] = $chunk;
        }

        self::assertSame(['abcd', 'efgh', 'ij'], $seen);
    }

    public function testClosingAStreamEarlyStopsItAndTellsTheListeners(): void
    {
        $fs = new CooperativeFilesystem(10, 0.0);
        $fs->write("{$this->dir}/early", str_repeat('x', 100));

        $stream = $fs->openRead("{$this->dir}/early");
        $closed = 0;
        $stream->onClose(static function () use (&$closed): void {
            ++$closed;
        });
        self::assertTrue($stream->isReadable());
        self::assertSame(10, strlen((string) $stream->read()));

        $stream->close();
        $stream->close();

        self::assertSame(1, $closed, 'each listener runs once');
        self::assertFalse($stream->isReadable());
        self::assertNull($stream->read());

        $late = 0;
        $stream->onClose(static function () use (&$late): void {
            ++$late;
        });
        self::assertSame(1, $late, 'a listener added after the close runs at once');
    }

    public function testOpeningAMissingFileOrADirectoryFails(): void
    {
        $fs = new CooperativeFilesystem();

        try {
            $fs->openRead("{$this->dir}/missing");
            self::fail('expected a FileNotFoundException');
        } catch (FileNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(FilesystemException::class);
        $this->expectExceptionMessageMatches('/it\ is\ a\ directory/');

        $fs->openRead($this->dir);
    }

    public function testCancellationStopsAStreamBetweenChunks(): void
    {
        $fs = new CooperativeFilesystem(10, 0.0);
        $fs->write("{$this->dir}/cs", str_repeat('x', 1000));
        $stream = $fs->openRead("{$this->dir}/cs");
        $cancel = new DeferredCancellation();

        self::assertNotNull($stream->read($cancel->getCancellation()));
        $cancel->cancel();

        $this->expectException(CancelledException::class);

        $stream->read($cancel->getCancellation());
    }

    public function testAStreamThatIsDroppedClosesItsFile(): void
    {
        $fs = new CooperativeFilesystem();
        $fs->write("{$this->dir}/drop", 'abc');
        $stream = $fs->openRead("{$this->dir}/drop");
        $closed = false;
        $stream->onClose(static function () use (&$closed): void {
            $closed = true;
        });

        unset($stream);

        self::assertTrue($closed);
    }

    public function testUnusableSettingsAreRefused(): void
    {
        foreach ([[0, 0.002], [-1, 0.002], [1024, -0.1]] as [$chunk, $slice]) {
            try {
                new CooperativeFilesystem($chunk, $slice);
                self::fail("expected an InvalidArgumentException for chunk {$chunk}, slice {$slice}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->addToAssertionCount(1);
        new CooperativeFilesystem(1, 0.0); // the smallest usable values
    }
}
