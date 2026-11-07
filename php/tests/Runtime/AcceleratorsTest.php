<?php

declare(strict_types=1);

namespace Raphp\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Raphp\Runtime\Accelerator;
use Raphp\Runtime\Accelerators;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver\EvDriver;
use Revolt\EventLoop\Driver\EventDriver;
use Revolt\EventLoop\Driver\StreamSelectDriver;
use Revolt\EventLoop\Driver\UvDriver;

final class AcceleratorsTest extends TestCase
{
    /**
     * @param list<string> $loaded the extensions that count as loaded
     */
    private function with(array $loaded, string $driver): Accelerators
    {
        return new Accelerators(static fn(string $extension): bool => in_array($extension, $loaded, true), $driver);
    }

    public function testNothingLoadedMeansRevoltsOwnDriverAndNoAccelerator(): void
    {
        $accelerators = $this->with([], StreamSelectDriver::class);

        self::assertSame([], $accelerators->loaded());
        self::assertNull($accelerators->loopAccelerator());
        self::assertFalse($accelerators->loopIsAccelerated());
        self::assertSame([], $accelerators->unused());
        self::assertSame('event loop: stream_select (no accelerator); accelerators loaded: none', $accelerators->summary());
    }

    public function testALoadedLoopExtensionThatRevoltUsesIsReportedAsTheAccelerator(): void
    {
        $accelerators = $this->with(['uv'], UvDriver::class);

        self::assertSame([Accelerator::Uv], $accelerators->loaded());
        self::assertSame(Accelerator::Uv, $accelerators->loopAccelerator());
        self::assertTrue($accelerators->loopIsAccelerated());
        self::assertSame([], $accelerators->unused());
        self::assertSame('event loop: ' . UvDriver::class . '; accelerators loaded: ext-uv', $accelerators->summary());
    }

    public function testAnExtensionRevoltDoesNotUseIsReportedAsNotUsed(): void
    {
        $both = $this->with(['ev', 'uv', 'curl'], UvDriver::class);

        self::assertSame([Accelerator::Uv, Accelerator::Ev], $both->loaded());
        self::assertSame([Accelerator::Ev], $both->unused());
        self::assertStringEndsWith('accelerators loaded: ext-uv, ext-ev; not used: ext-ev', $both->summary());

        $overridden = $this->with(['ev'], StreamSelectDriver::class); // REVOLT_DRIVER names another driver
        self::assertFalse($overridden->loopIsAccelerated());
        self::assertSame([Accelerator::Ev], $overridden->unused());
    }

    public function testExtEioIsDetectedButNeverUsed(): void
    {
        $accelerators = $this->with(['eio'], StreamSelectDriver::class);

        self::assertSame([Accelerator::Eio], $accelerators->loaded());
        self::assertSame([Accelerator::Eio], $accelerators->unused());
        self::assertFalse(Accelerator::Eio->usedByRaphp());
        self::assertNull(Accelerator::Eio->loopDriver());
        self::assertStringContainsString('not used: ext-eio', $accelerators->summary());
    }

    public function testADriverRaphpDoesNotKnowIsNotMistakenForAnAccelerator(): void
    {
        $accelerators = $this->with([], 'Some\Custom\Driver');

        self::assertNull($accelerators->loopAccelerator());
        self::assertFalse($accelerators->loopIsAccelerated());
        self::assertStringContainsString('Some\Custom\Driver', $accelerators->summary());
    }

    public function testEveryDriverNamedHereIsARealRevoltClass(): void
    {
        self::assertSame(UvDriver::class, Accelerator::Uv->loopDriver());
        self::assertSame(EvDriver::class, Accelerator::Ev->loopDriver());
        self::assertSame(EventDriver::class, Accelerator::Event->loopDriver());
        foreach ([Accelerator::Uv, Accelerator::Ev, Accelerator::Event] as $accelerator) {
            self::assertTrue($accelerator->usedByRaphp());
            self::assertTrue(class_exists((string) $accelerator->loopDriver()), "{$accelerator->value}: no such driver class");
        }
    }

    public function testTheExtensionNamesAreThePhpExtensionNames(): void
    {
        self::assertSame(['uv', 'ev', 'event', 'eio'], array_map(static fn(Accelerator $accelerator): string => $accelerator->value, Accelerator::cases()));
    }

    public function testTheCurrentProcessIsReportedAsItReallyIs(): void
    {
        $accelerators = Accelerators::current();

        $loaded = array_values(array_filter(Accelerator::cases(), static fn(Accelerator $accelerator): bool => extension_loaded($accelerator->value)));
        self::assertSame($loaded, $accelerators->loaded());
        self::assertSame(EventLoop::getDriver()::class, $accelerators->loopDriver());

        // Revolt's rule, when REVOLT_DRIVER is not set: ext-uv, else ext-ev, else ext-event, else stream_select.
        if (getenv('REVOLT_DRIVER') === false && getenv('REVOLT_DRIVER_DEBUG_TRACE') === false) {
            $expected = StreamSelectDriver::class;
            foreach ([Accelerator::Uv, Accelerator::Ev, Accelerator::Event] as $candidate) {
                if (extension_loaded($candidate->value)) {
                    $expected = (string) $candidate->loopDriver();

                    break;
                }
            }

            self::assertSame($expected, $accelerators->loopDriver(), 'Revolt chose a different driver than its documented order');
        }
    }
}
