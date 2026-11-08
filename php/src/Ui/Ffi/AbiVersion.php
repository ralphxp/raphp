<?php

declare(strict_types=1);

namespace Raphp\Ui\Ffi;

use InvalidArgumentException;
use Stringable;

/**
 * The ABI version of the shim library, `major << 16 | minor` (docs/protocol/shim-abi.md).
 *
 * While the major number is 0 the ABI is not stable and only the very same version is accepted. From 1 on, the
 * library may have the same major and a minor at least as high as the one PHP needs (a minor bump only
 * adds functions).
 */
final readonly class AbiVersion implements Stringable
{
    public function __construct(
        public int $major,
        public int $minor,
    ) {
        if ($major < 0 || $major > 0xFFFF || $minor < 0 || $minor > 0xFFFF) {
            throw new InvalidArgumentException("an ABI version is two numbers from 0 to 65535, got {$major}.{$minor}");
        }
    }

    public static function fromInt(int $packed): self
    {
        return new self(($packed >> 16) & 0xFFFF, $packed & 0xFFFF);
    }

    public function toInt(): int
    {
        return $this->major << 16 | $this->minor;
    }

    /**
     * Whether a library of version `$found` can be used by PHP code that needs this version.
     */
    public function isSatisfiedBy(self $found): bool
    {
        if ($this->major !== $found->major) {
            return false;
        }

        return $this->major === 0 ? $this->minor === $found->minor : $found->minor >= $this->minor;
    }

    public function __toString(): string
    {
        return "{$this->major}.{$this->minor}";
    }
}
