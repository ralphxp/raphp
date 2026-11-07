<?php

declare(strict_types=1);

namespace Raphp\Filesystem;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\CancelledException;

/**
 * Files and directories for application code, in place of the blocking PHP built-ins (FRAMEWORK 3.3).
 *
 * Every method that can take long accepts a {@see Cancellation} and lets the event loop run while it
 * works. Implementations decide how; the default one, {@see CooperativeFilesystem}, works in small
 * slices. Watching a path for changes is not part of this interface yet: it needs inotify, ext-eio or
 * ext-uv, and none is required.
 */
interface Filesystem
{
    /**
     * The whole content of a file. It is for files that fit comfortably in memory (settings, source,
     * small data). Putting the pieces together into one string is a single copy that no implementation
     * can spread over turns of the event loop; measured on the development laptop, a 32 MiB file
     * stalls the loop for 50 to 90 ms at the end, takes three to six times as long as the built-in
     * `file_get_contents()`, and needs about twice its size in memory. For a large file use
     * {@see self::openRead()}.
     *
     * @param int|null $maxBytes refuse a file larger than this, so a caller cannot be made to hold a huge file by accident
     *
     * @throws FileNotFoundException when there is no such file
     * @throws FilesystemException when it cannot be read, is a directory, or is larger than `$maxBytes`
     * @throws CancelledException
     */
    public function read(string $path, ?Cancellation $cancellation = null, ?int $maxBytes = null): string;

    /**
     * Opens a file for reading in chunks, holding no more than one chunk at a time. The event loop
     * runs between chunks.
     *
     * @throws FileNotFoundException when there is no such file
     * @throws FilesystemException when it cannot be opened or is a directory
     */
    public function openRead(string $path): ReadableStream;

    /**
     * Writes a file, replacing its content (or adding to the end when `$append` is set). The write is
     * not atomic: a failure or a cancellation part way leaves the part already written.
     *
     * @throws FilesystemException when it cannot be written
     * @throws CancelledException
     */
    public function write(string $path, string $contents, ?Cancellation $cancellation = null, bool $append = false): void;

    /**
     * @param bool $followLinks false describes a symbolic link itself instead of what it points to
     *
     * @throws FileNotFoundException when there is nothing at the path
     * @throws FilesystemException
     */
    public function stat(string $path, bool $followLinks = true): FileStat;

    /**
     * The names (not paths) in a directory, sorted, without "." and "..".
     *
     * @return list<string>
     *
     * @throws FileNotFoundException when there is no such directory
     * @throws FilesystemException when it is not a directory or cannot be read
     * @throws CancelledException
     */
    public function list(string $path, ?Cancellation $cancellation = null): array;
}
