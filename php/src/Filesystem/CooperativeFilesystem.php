<?php

declare(strict_types=1);

namespace Raphp\Filesystem;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use InvalidArgumentException;
use Raphp\Filesystem\Internal\TimeSlice;

/**
 * The default {@see Filesystem}: ordinary PHP file functions, used in small pieces, with the event loop
 * given a turn in between (FRAMEWORK 3.3).
 *
 * PHP cannot read a regular file without blocking: a file is always "ready", so the loop has nothing to
 * wait for, and the real asynchronous routes need ext-eio, ext-uv or helper processes. Raphp takes
 * none of those by default (the helper processes of amphp/parallel are not tied to their master's
 * life, finding F46). Instead, work is done in chunks, and after `$slice` seconds of it the loop gets a
 * turn, so timers, other connections and the rest of the application keep running. A single chunk can
 * still block for as long as the disk takes to deliver it, so on a very slow or remote disk the loop
 * stalls for that long.
 */
final readonly class CooperativeFilesystem implements Filesystem
{
    public const int DEFAULT_CHUNK_BYTES = 65_536;

    /** Seconds of file work between two turns of the event loop (my number, see docs/BENCHMARKS.md). */
    public const float DEFAULT_SLICE = 0.002;

    /** Directory entries read between two checks of the clock. */
    private const int ENTRIES_PER_CHECK = 256;

    /** @var int<1, max> */
    private int $chunkBytes;

    /**
     * @param int $chunkBytes bytes moved per read or write call, at least 1
     * @param float $slice seconds of work before the event loop gets a turn; 0 gives it one after every chunk
     *
     * @throws InvalidArgumentException when a value is not usable
     */
    public function __construct(
        int $chunkBytes = self::DEFAULT_CHUNK_BYTES,
        private float $slice = self::DEFAULT_SLICE,
    ) {
        if ($chunkBytes < 1) {
            throw new InvalidArgumentException('the chunk size must be at least 1 byte');
        }

        if ($slice < 0.0) {
            throw new InvalidArgumentException('the slice must not be negative');
        }

        $this->chunkBytes = max(1, $chunkBytes);
    }

    public function read(string $path, ?Cancellation $cancellation = null, ?int $maxBytes = null): string
    {
        $this->requireRegularReadable($path, 'read');
        $size = @filesize($path);
        if ($maxBytes !== null && $size !== false && $size > $maxBytes) {
            throw new FilesystemException("cannot read {$path}: it is {$size} bytes, more than the limit of {$maxBytes}");
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw $this->failure($path, 'read');
        }

        try {
            $parts = [];
            $total = 0;
            $slice = new TimeSlice($this->slice);
            while (!feof($handle)) {
                $chunk = fread($handle, $this->chunkBytes);
                if ($chunk === false) {
                    throw $this->failure($path, 'read');
                }

                $total += strlen($chunk);
                if ($maxBytes !== null && $total > $maxBytes) {
                    // The file grew after the size check above.
                    throw new FilesystemException("cannot read {$path}: it is larger than the limit of {$maxBytes} bytes");
                }

                $parts[] = $chunk;
                $slice->checkpoint($cancellation);
            }

            return implode('', $parts);
        } finally {
            fclose($handle);
        }
    }

    public function write(string $path, string $contents, ?Cancellation $cancellation = null, bool $append = false): void
    {
        $handle = @fopen($path, $append ? 'ab' : 'wb');
        if ($handle === false) {
            throw $this->failure($path, 'write');
        }

        try {
            $length = strlen($contents);
            $offset = 0;
            $slice = new TimeSlice($this->slice);
            while ($offset < $length) {
                $written = @fwrite($handle, substr($contents, $offset, $this->chunkBytes));
                if ($written === false || $written === 0) {
                    throw $this->failure($path, 'write');
                }

                $offset += $written;
                $slice->checkpoint($cancellation);
            }

            if (!@fflush($handle)) {
                throw $this->failure($path, 'write');
            }
        } finally {
            fclose($handle);
        }
    }

    public function openRead(string $path): ReadableStream
    {
        $this->requireRegularReadable($path, 'open');

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw $this->failure($path, 'open');
        }

        return new CooperativeReadStream($handle, $path, $this->chunkBytes, $this->slice);
    }

    public function stat(string $path, bool $followLinks = true): FileStat
    {
        $info = $followLinks ? @stat($path) : @lstat($path);
        if ($info === false) {
            throw $this->failure($path, 'stat');
        }

        return new FileStat(
            match ($info['mode'] & 0o170000) {
                0o100000 => FileType::File,
                0o040000 => FileType::Directory,
                0o120000 => FileType::Link,
                default => FileType::Other,
            },
            $info['size'],
            $info['mode'] & 0o7777,
            $info['mtime'],
        );
    }

    public function list(string $path, ?Cancellation $cancellation = null): array
    {
        if (!file_exists($path) && !is_link($path)) {
            throw new FileNotFoundException("cannot list {$path}: no such directory");
        }

        if (!is_dir($path)) {
            throw new FilesystemException("cannot list {$path}: it is not a directory");
        }

        $handle = @opendir($path);
        if ($handle === false) {
            throw $this->failure($path, 'list');
        }

        try {
            $names = [];
            $slice = new TimeSlice($this->slice);
            $sinceCheck = 0;
            while (($name = readdir($handle)) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $names[] = $name;
                if (++$sinceCheck >= self::ENTRIES_PER_CHECK) {
                    $sinceCheck = 0;
                    $slice->checkpoint($cancellation);
                }
            }
        } finally {
            closedir($handle);
        }

        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * @throws FilesystemException
     */
    private function requireRegularReadable(string $path, string $action): void
    {
        if (!file_exists($path)) {
            throw new FileNotFoundException("cannot {$action} {$path}: no such file");
        }

        if (is_dir($path)) {
            throw new FilesystemException("cannot {$action} {$path}: it is a directory");
        }
    }

    private function failure(string $path, string $action): FilesystemException
    {
        $reason = error_get_last()['message'] ?? 'unknown reason';
        error_clear_last();

        if (!file_exists($path) && !is_link($path) && $action !== 'write') {
            return new FileNotFoundException("cannot {$action} {$path}: no such file or directory");
        }

        return new FilesystemException("cannot {$action} {$path}: {$reason}");
    }
}
