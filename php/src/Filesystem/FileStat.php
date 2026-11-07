<?php

declare(strict_types=1);

namespace Raphp\Filesystem;

/**
 * What the filesystem says about a path.
 */
final readonly class FileStat
{
    /**
     * @param int $permissions the permission bits, for example 0644 (not the file type bits)
     * @param int $modified seconds since the epoch
     */
    public function __construct(
        public FileType $type,
        public int $size,
        public int $permissions,
        public int $modified,
    ) {}
}
