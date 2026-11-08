<?php

declare(strict_types=1);

namespace Raphp\Tests\Ui\Ffi;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Raphp\Tests\Support\ChildProcess;
use Raphp\Tests\Support\ShimFixture;
use Raphp\Tests\Support\WorkerFixture;
use Raphp\Ui\Ffi\AbiVersion;
use Raphp\Ui\Ffi\Loader;
use Raphp\Ui\Ffi\ShimLoadException;

/**
 * Every way loading the shim can fail must say what is wrong and what to do (plan P3.5).
 */
final class LoaderTest extends TestCase
{
    private function loader(?AbiVersion $expected = null): Loader
    {
        return new Loader(new NullLogger(), $expected);
    }

    /**
     * @param \Closure(): mixed $load
     */
    private function failure(\Closure $load): string
    {
        try {
            $load();
        } catch (ShimLoadException $exception) {
            return $exception->getMessage();
        }

        self::fail('loading should have failed');
    }

    public function testItLoadsTheLibraryAndReportsItsVersion(): void
    {
        $library = ShimFixture::library();

        self::assertSame('0.4', (string) $library->version);
    }

    public function testALibraryOfAnotherAbiVersionIsReportedAsThatAndNeverUsed(): void
    {
        ShimFixture::library(); // skips when the library is not built

        $message = $this->failure(fn() => $this->loader(new AbiVersion(0, 99))->load(ShimFixture::LIBRARY, ShimFixture::HEADER));

        self::assertStringContainsString('ABI version 0.4', $message);
        self::assertStringContainsString('needs 0.99', $message);
        self::assertStringContainsString('make shim', $message);
    }

    public function testAMissingLibraryNamesThePathAndTheFix(): void
    {
        $message = $this->failure(fn() => $this->loader()->load('/nonexistent/libraphp.so', ShimFixture::HEADER));

        self::assertStringContainsString('/nonexistent/libraphp.so', $message);
        self::assertStringContainsString('make shim', $message);
    }

    public function testAMissingHeaderNamesThePathAndTheFix(): void
    {
        ShimFixture::library();

        $message = $this->failure(fn() => $this->loader()->load(ShimFixture::LIBRARY, '/nonexistent/raphp.h'));

        self::assertStringContainsString('/nonexistent/raphp.h', $message);
        self::assertStringContainsString('make header', $message);
    }

    public function testALibraryThatIsNotTheShimIsSaidNotToBeOne(): void
    {
        $message = $this->failure(fn() => $this->loader()->load('/lib64/libc.so.6', ShimFixture::HEADER));

        self::assertStringContainsString('no raphp_abi_version', $message);
    }

    public function testAFileThatIsNotALibraryIsSaidNotToLoad(): void
    {
        $message = $this->failure(fn() => $this->loader()->load(ShimFixture::HEADER, ShimFixture::HEADER));

        self::assertStringContainsString('could not be loaded', $message);
        self::assertStringContainsString('ldd', $message);
    }

    public function testAHeaderOfOtherSourcesIsSaidNotToMatchTheLibrary(): void
    {
        ShimFixture::library();
        $header = tempnam(sys_get_temp_dir(), 'raphp-header');
        self::assertNotFalse($header);
        file_put_contents($header, "uint32_t raphp_abi_version(void);\nint raphp_no_such_function(void);\n");

        try {
            $message = $this->failure(fn() => $this->loader()->load(ShimFixture::LIBRARY, $header));
        } finally {
            unlink($header);
        }

        self::assertStringContainsString('does not match the library', $message);
        self::assertStringContainsString('raphp_no_such_function', $message);
    }

    #[Group('slow')]
    public function testWhenFfiIsSwitchedOffTheMessageNamesTheSettingAndTheDefault(): void
    {
        ShimFixture::library();

        $result = (new ChildProcess())->run(WorkerFixture::script('ffi-load.php'), [ShimFixture::LIBRARY, ShimFixture::HEADER], readyMarker: 'FAILED', phpOptions: ['ffi.enable=0']);

        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertStringStartsWith('FAILED PHP does not allow FFI here: ffi.enable is "0"', $result->stdout);
        self::assertStringContainsString('"preload"', $result->stdout);
        self::assertStringContainsString('ffi.enable=1', $result->stdout);
    }

    #[Group('slow')]
    public function testTheDefaultSettingAllowsTheCommandLine(): void
    {
        ShimFixture::library();

        $result = (new ChildProcess())->run(WorkerFixture::script('ffi-load.php'), [ShimFixture::LIBRARY, ShimFixture::HEADER], readyMarker: 'LOADED', phpOptions: ['ffi.enable=preload']);

        self::assertSame("LOADED 0.4\n", $result->stdout, $result->stderr);
    }
}
