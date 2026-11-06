<?php

declare(strict_types=1);

namespace Raphp\Tests\Core\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raphp\Core\Config\BuildConfig;
use Raphp\Core\Config\ConfigLoader;
use Raphp\Core\Config\InvalidConfig;
use Raphp\Core\Config\PhpConfig;
use Raphp\Core\Config\UiConfig;
use Raphp\Core\Config\WorkerConfig;
use Raphp\Core\Logging\LogLevel;

final class ConfigLoaderTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/raphp-config-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700, true);
    }

    protected function tearDown(): void
    {
        $entries = scandir($this->directory);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            unlink($this->directory . '/' . $entry);
        }

        rmdir($this->directory);
    }

    /**
     * @param array<string, mixed>|string $content an array is encoded as JSON, a string is written as it is
     */
    private function write(array|string $content): string
    {
        $file = $this->directory . '/raphp.json';
        file_put_contents($file, is_string($content) ? $content : json_encode($content, JSON_THROW_ON_ERROR));

        return $file;
    }

    /**
     * @param array<string, mixed>|string $content
     * @param array<string, mixed> $environment
     *
     * @return list<string>
     */
    private function problemsFor(array|string $content, array $environment = []): array
    {
        try {
            (new ConfigLoader())->load($this->write($content), $environment);
        } catch (InvalidConfig $exception) {
            return $exception->problems();
        }

        self::fail('Expected InvalidConfig, but the configuration was accepted.');
    }

    /**
     * @return array<string, mixed>
     */
    private static function minimal(): array
    {
        return ['name' => 'acme/files', 'entry' => 'src/main.php'];
    }

    // ---------------------------------------------------------------- valid manifests

    public function testAMinimalManifestGetsTheDefaults(): void
    {
        $config = (new ConfigLoader())->load($this->write(self::minimal()));

        self::assertSame('acme/files', $config->name);
        self::assertSame('src/main.php', $config->entry);
        self::assertSame(realpath($this->directory), $config->basePath);
        self::assertSame([], $config->workers);
        self::assertEquals(new UiConfig(false, 'default'), $config->ui);
        self::assertSame([], $config->libs);
        self::assertEquals(new PhpConfig(), $config->php);
        self::assertNull($config->build);
        self::assertSame(LogLevel::Info, $config->logLevel);
        self::assertFalse($config->ipcTrace);
    }

    public function testAFullManifestIsLoaded(): void
    {
        $config = (new ConfigLoader())->load($this->write([
            'name' => 'acme/files',
            'entry' => 'src/main.php',
            'workers' => [
                'main' => ['entry' => 'src/worker.php', 'pool' => 2],
                'scan-2' => ['entry' => 'src/scan.php'],
                'one' => ['entry' => 'src/one.php', 'pool' => 1],
            ],
            'ui' => ['enabled' => true, 'backend' => 'drawn'],
            'libs' => ['libraphp' => '^0.1', 'other.lib_2' => '>=1.0 <2.0'],
            'php' => [
                'extensions' => ['ffi', 'pcntl', 'posix', 'sockets'],
                'ini' => ['memory_limit' => '256M', 'opcache.enable' => true, 'precision' => 14, 'assert.exception' => false, 'ratio' => 1.5],
            ],
            'build' => ['output' => 'dist/files', 'embedShim' => false],
        ]));

        self::assertEquals(new WorkerConfig('main', 'src/worker.php', 2), $config->workers['main']);
        self::assertEquals(new WorkerConfig('scan-2', 'src/scan.php', 1), $config->workers['scan-2']);
        self::assertEquals(new WorkerConfig('one', 'src/one.php', 1), $config->workers['one']);
        self::assertEquals(new UiConfig(true, 'drawn'), $config->ui);
        self::assertSame(['libraphp' => '^0.1', 'other.lib_2' => '>=1.0 <2.0'], $config->libs);
        self::assertSame(['ffi', 'pcntl', 'posix', 'sockets'], $config->php->extensions);
        self::assertSame(
            ['memory_limit' => '256M', 'opcache.enable' => '1', 'precision' => '14', 'assert.exception' => '0', 'ratio' => '1.5'],
            $config->php->ini,
        );
        self::assertEquals(new BuildConfig('dist/files', false), $config->build);
    }

    public function testTheBuildSectionDefaultsToEmbeddingTheShim(): void
    {
        $config = (new ConfigLoader())->load($this->write([...self::minimal(), 'build' => ['output' => 'dist/app']]));

        self::assertEquals(new BuildConfig('dist/app', true), $config->build);
    }

    public function testThePoolBoundariesAreOneAndTwoHundredAndFiftySix(): void
    {
        $load = fn(int $pool): int => (new ConfigLoader())->load($this->write([
            ...self::minimal(),
            'workers' => ['main' => ['entry' => 'w.php', 'pool' => $pool]],
        ]))->workers['main']->pool;

        self::assertSame(1, $load(1));
        self::assertSame(256, $load(256));
    }

    public function testNoKeyInAnAcceptedConfigIsAnInteger(): void
    {
        $config = (new ConfigLoader())->load($this->write([
            ...self::minimal(),
            'workers' => ['w1' => ['entry' => 'w.php']],
            'libs' => ['lib1' => '^1'],
            'php' => ['ini' => ['x1' => 'a']],
        ]));

        foreach ([$config->workers, $config->libs, $config->php->ini] as $map) {
            self::assertSame([], array_filter(array_keys($map), is_int(...)));
        }
    }

    // ---------------------------------------------------------------- files and paths

    public function testBasePathIsTheResolvedDirectoryWhenTheFileIsGivenRelatively(): void
    {
        $this->write(self::minimal());
        $previous = getcwd();
        self::assertNotFalse($previous);
        chdir($this->directory);

        try {
            $config = (new ConfigLoader())->load('raphp.json');
        } finally {
            chdir($previous);
        }

        self::assertSame(realpath($this->directory), $config->basePath);
        self::assertStringStartsWith('/', $config->basePath);
    }

    public function testBasePathFollowsASymlinkToTheRealDirectory(): void
    {
        $real = $this->directory . '/real';
        mkdir($real);
        file_put_contents($real . '/raphp.json', json_encode(self::minimal(), JSON_THROW_ON_ERROR));
        $link = $this->directory . '/link.json';
        symlink($real . '/raphp.json', $link);

        try {
            $config = (new ConfigLoader())->load($link);
        } finally {
            unlink($real . '/raphp.json');
            rmdir($real);
        }

        self::assertSame(realpath($this->directory) . '/real', $config->basePath);
    }

    public function testAMissingFileIsReported(): void
    {
        try {
            (new ConfigLoader())->load($this->directory . '/missing.json');
            self::fail('Expected InvalidConfig.');
        } catch (InvalidConfig $exception) {
            self::assertSame(['the file does not exist or is not readable'], $exception->problems());
            self::assertStringContainsString('missing.json', $exception->getMessage());
        }
    }

    public function testAStreamWrapperPathIsNotAccepted(): void
    {
        $file = $this->write(self::minimal());

        try {
            (new ConfigLoader())->load('file://' . $file);
            self::fail('Expected InvalidConfig.');
        } catch (InvalidConfig $exception) {
            self::assertSame(['the file does not exist or is not readable'], $exception->problems());
        }
    }

    public function testADirectoryIsNotAManifest(): void
    {
        try {
            (new ConfigLoader())->load($this->directory);
            self::fail('Expected InvalidConfig.');
        } catch (InvalidConfig $exception) {
            self::assertSame(['the file does not exist or is not readable'], $exception->problems());
        }
    }

    public function testAnUnreadableFileIsReported(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('root can read any file, so permissions cannot make it unreadable');
        }

        $file = $this->write(self::minimal());
        chmod($file, 0o000);

        try {
            (new ConfigLoader())->load($file);
            self::fail('Expected InvalidConfig.');
        } catch (InvalidConfig $exception) {
            self::assertSame(['the file does not exist or is not readable'], $exception->problems());
        } finally {
            chmod($file, 0o600);
        }
    }

    public function testAnOversizedFileIsRejectedWithoutBeingParsed(): void
    {
        $problems = $this->problemsFor(str_repeat(' ', 1_048_577));

        self::assertSame(['the file is larger than 1 MiB, which is far more than a manifest needs'], $problems);
    }

    public function testAFileOfExactlyTheSizeLimitIsStillRead(): void
    {
        $manifest = '{"name":"acme/files","entry":"a.php"}';

        $config = (new ConfigLoader())->load($this->write($manifest . str_repeat(' ', 1_048_576 - strlen($manifest))));

        self::assertSame('acme/files', $config->name);
    }

    public function testNestingUpToTheJsonDepthLimitIsParsedAndBeyondItIsRejected(): void
    {
        $nested = static fn(int $levels): string => '{"name":"acme/files","entry":"a.php","php":{"ini":{"x":'
            . str_repeat('[', $levels) . str_repeat(']', $levels) . '}}}';

        self::assertStringContainsString('php.ini.x: must be a string, number or boolean', implode("\n", $this->problemsFor($nested(60))));
        self::assertStringContainsString('invalid JSON: Maximum stack depth exceeded', implode("\n", $this->problemsFor($nested(61))));
    }

    // ---------------------------------------------------------------- environment

    public function testEnvironmentVariablesOverrideTheFileAndTheDefaults(): void
    {
        $manifest = [...self::minimal(), 'ui' => ['enabled' => false, 'backend' => 'drawn']];

        $config = (new ConfigLoader())->load($this->write($manifest), [
            'RAPHP_LOG_LEVEL' => 'DEBUG',
            'RAPHP_IPC_TRACE' => 'true',
            'RAPHP_UI_ENABLED' => '1',
        ]);

        self::assertSame(LogLevel::Debug, $config->logLevel);
        self::assertTrue($config->ipcTrace);
        self::assertEquals(new UiConfig(true, 'drawn'), $config->ui);
    }

    public function testTheEnvironmentCanSwitchOffTheUiTheFileTurnsOn(): void
    {
        $manifest = [...self::minimal(), 'ui' => ['enabled' => true]];

        $config = (new ConfigLoader())->load($this->write($manifest), ['RAPHP_UI_ENABLED' => 'off']);

        self::assertFalse($config->ui->enabled);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function booleanSpellings(): iterable
    {
        foreach (['1', 'true', 'yes', 'on', 'TRUE', 'Yes', 'ON'] as $spelling) {
            yield "true as {$spelling}" => [$spelling, true];
        }

        foreach (['0', 'false', 'no', 'off', 'FALSE', 'No', 'OFF'] as $spelling) {
            yield "false as {$spelling}" => [$spelling, false];
        }
    }

    #[DataProvider('booleanSpellings')]
    public function testEveryDocumentedBooleanSpellingIsAccepted(string $spelling, bool $expected): void
    {
        $config = (new ConfigLoader())->load($this->write([...self::minimal(), 'ui' => ['enabled' => !$expected]]), [
            'RAPHP_IPC_TRACE' => $spelling,
            'RAPHP_UI_ENABLED' => $spelling,
        ]);

        self::assertSame($expected, $config->ipcTrace);
        self::assertSame($expected, $config->ui->enabled);
    }

    /**
     * @return iterable<string, array{string, LogLevel}>
     */
    public static function logLevelSpellings(): iterable
    {
        foreach (LogLevel::cases() as $level) {
            yield $level->value => [$level->value, $level];
            yield strtoupper($level->value) => [strtoupper($level->value), $level];
            yield ucfirst($level->value) => [ucfirst($level->value), $level];
        }
    }

    #[DataProvider('logLevelSpellings')]
    public function testEveryLogLevelIsAcceptedInAnyCase(string $spelling, LogLevel $expected): void
    {
        $config = (new ConfigLoader())->load($this->write(self::minimal()), ['RAPHP_LOG_LEVEL' => $spelling]);

        self::assertSame($expected, $config->logLevel);
    }

    public function testEmptyEnvironmentValuesCountAsUnset(): void
    {
        $config = (new ConfigLoader())->load($this->write(self::minimal()), [
            'RAPHP_LOG_LEVEL' => '',
            'RAPHP_IPC_TRACE' => '',
            'RAPHP_UI_ENABLED' => '',
        ]);

        self::assertSame(LogLevel::Info, $config->logLevel);
        self::assertFalse($config->ipcTrace);
        self::assertFalse($config->ui->enabled);
    }

    public function testUnrelatedEnvironmentVariablesAreIgnored(): void
    {
        $config = (new ConfigLoader())->load($this->write(self::minimal()), ['PATH' => '/usr/bin', 'HOME' => '/root']);

        self::assertSame(LogLevel::Info, $config->logLevel);
    }

    public function testTheLoaderKeepsNoStateBetweenLoads(): void
    {
        $loader = new ConfigLoader();
        $file = $this->write(self::minimal());

        $first = $loader->load($file, ['RAPHP_LOG_LEVEL' => 'debug']);
        $second = $loader->load($file);

        self::assertSame(LogLevel::Debug, $first->logLevel);
        self::assertSame(LogLevel::Info, $second->logLevel);
    }

    public function testInvalidEnvironmentValuesAreReportedByVariableName(): void
    {
        $problems = $this->problemsFor(self::minimal(), [
            'RAPHP_LOG_LEVEL' => 'loud',
            'RAPHP_IPC_TRACE' => 'maybe',
            'RAPHP_UI_ENABLED' => '2',
        ]);

        $all = implode("\n", $problems);
        self::assertStringContainsString("RAPHP_LOG_LEVEL: 'loud' is not a log level; use one of: emergency, alert, critical, error, warning, notice, info, debug", $all);
        self::assertStringContainsString("RAPHP_IPC_TRACE: 'maybe' is not a boolean; use one of: 1, true, yes, on, 0, false, no, off", $all);
        self::assertStringContainsString("RAPHP_UI_ENABLED: '2' is not a boolean", $all);
    }

    public function testNonStringEnvironmentValuesAreReportedInsteadOfCrashing(): void
    {
        $problems = $this->problemsFor(self::minimal(), [
            'RAPHP_IPC_TRACE' => 1,
            'RAPHP_LOG_LEVEL' => ['debug'],
            'RAPHP_UI_ENABLED' => true,
        ]);

        $all = implode("\n", $problems);
        self::assertStringContainsString('RAPHP_IPC_TRACE: must be a string', $all);
        self::assertStringContainsString('RAPHP_LOG_LEVEL: must be a string', $all);
        self::assertStringContainsString('RAPHP_UI_ENABLED: must be a string', $all);
    }

    public function testEnvironmentValuesWithSurroundingWhitespaceAreRejected(): void
    {
        $problems = $this->problemsFor(self::minimal(), ['RAPHP_LOG_LEVEL' => "debug\n", 'RAPHP_IPC_TRACE' => ' true ']);

        $all = implode("\n", $problems);
        self::assertStringContainsString("RAPHP_LOG_LEVEL: 'debug\\n' is not a log level", $all);
        self::assertStringContainsString("RAPHP_IPC_TRACE: ' true ' is not a boolean", $all);
    }

    public function testAnEnvironmentProblemIsStillReportedWhenTheFileIsMissing(): void
    {
        try {
            (new ConfigLoader())->load($this->directory . '/missing.json', ['RAPHP_LOG_LEVEL' => 'loud']);
            self::fail('Expected InvalidConfig.');
        } catch (InvalidConfig $exception) {
            self::assertCount(2, $exception->problems());
        }
    }

    // ---------------------------------------------------------------- invalid manifests

    /**
     * @return iterable<string, array{array<string, mixed>|string, string}>
     */
    public static function invalidManifests(): iterable
    {
        $base = self::minimal();
        $raw = static fn(string $sections): string => '{"name":"acme/files","entry":"a.php",' . $sections . '}';

        yield 'not JSON' => ['{nope', 'invalid JSON'];
        yield 'a JSON list' => ['[1, 2]', 'the top level must be a JSON object'];
        yield 'a JSON string' => ['"text"', 'the top level must be a JSON object'];
        yield 'an empty file' => ['', 'invalid JSON'];
        yield 'a byte order mark' => ["\xEF\xBB\xBF{\"name\":\"acme/files\",\"entry\":\"a.php\"}", 'the file starts with a UTF-8 byte order mark'];

        yield 'missing name' => [['entry' => 'a.php'], 'name: is required'];
        yield 'missing entry' => [['name' => 'acme/files'], 'entry: is required'];
        yield 'name is not a string' => [['name' => 5, 'entry' => 'a.php'], 'name: must be a non-empty string'];
        yield 'name is empty' => [['name' => '', 'entry' => 'a.php'], 'name: must be a non-empty string'];
        yield 'name is too long' => [['name' => 'a/' . str_repeat('b', 1030), 'entry' => 'a.php'], 'name: must be at most 1024 bytes'];
        yield 'unknown top-level key' => [[...$base, 'colour' => 'red'], 'colour: unknown key; allowed here: name, entry'];

        foreach (['files', 'Acme/Files', '-acme/files', '.acme/files', '_acme/files', 'acme/-files', 'acme/.files', 'acme/_files', 'acme/files/extra', '/files', 'acme/', 'acme//files', "acme/files\n", 'acme/fi les'] as $name) {
            yield 'name ' . json_encode($name) => [['name' => $name, 'entry' => 'a.php'], "name: must look like 'vendor/package'"];
        }

        $badPaths = ['/etc/passwd', 'src/../../x.php', '../x.php', './a.php', '.', '..', 'src//a.php', 'src/', 'src\\x.php', "src/a\0b.php", "src/a\x01b.php", "src/a\x1fb.php", "src/a\x7fb.php", "src/a\tb.php", "src/a\rb.php", "src/a\nb.php", "src/a\u{0085}b.php"];
        foreach ($badPaths as $path) {
            yield 'entry ' . json_encode($path) => [['name' => 'acme/files', 'entry' => $path], 'entry: must be a clean relative path inside the project'];
            yield 'worker entry ' . json_encode($path) => [[...$base, 'workers' => ['main' => ['entry' => $path]]], 'workers.main.entry: must be a clean relative path inside the project'];
            yield 'build output ' . json_encode($path) => [[...$base, 'build' => ['output' => $path]], 'build.output: must be a clean relative path inside the project'];
        }

        yield 'entry is too long' => [['name' => 'acme/files', 'entry' => 'a/' . str_repeat('b', 1030)], 'entry: must be at most 1024 bytes'];

        yield 'workers is a list' => [[...$base, 'workers' => [1]], 'workers: must be a JSON object keyed by name'];
        yield 'workers is null' => [[...$base, 'workers' => null], 'workers: must be a JSON object'];
        yield 'worker is not an object' => [[...$base, 'workers' => ['main' => 'x']], 'workers.main: must be a JSON object'];
        yield 'worker is null' => [[...$base, 'workers' => ['main' => null]], 'workers.main: must be a JSON object'];
        yield 'worker without entry' => [[...$base, 'workers' => ['main' => []]], 'workers.main.entry: is required'];
        yield 'worker unknown key' => [[...$base, 'workers' => ['main' => ['entry' => 'w.php', 'colour' => 1]]], 'workers.main.colour: unknown key'];
        yield 'worker named with only digits' => [$raw('"workers":{"0":{"entry":"w.php"}}'), 'workers: must be a JSON object keyed by name (a name cannot be only digits)'];
        yield 'worker named 7' => [$raw('"workers":{"7":{"entry":"w.php"}}'), 'workers.7: the worker name must start with a lowercase letter'];

        foreach (['Main', '9w', '-w', '_w', 'w w', 'w.x', "main\n"] as $name) {
            yield 'worker name ' . json_encode($name) => [[...$base, 'workers' => [$name => ['entry' => 'w.php']]], 'the worker name must start with a lowercase letter'];
        }

        foreach ([0, -1, 257, 99999999999, '2', 2.5, true, null] as $pool) {
            yield 'worker pool ' . json_encode($pool) => [[...$base, 'workers' => ['main' => ['entry' => 'w.php', 'pool' => $pool]]], 'workers.main.pool: must be an integer from 1 to 256'];
        }

        yield 'ui is not an object' => [[...$base, 'ui' => 'yes'], 'ui: must be a JSON object'];
        yield 'ui is null' => [[...$base, 'ui' => null], 'ui: must be a JSON object'];
        yield 'ui enabled is not a boolean' => [[...$base, 'ui' => ['enabled' => 'yes']], 'ui.enabled: must be true or false'];
        yield 'ui unknown key' => [[...$base, 'ui' => ['theme' => 'dark']], 'ui.theme: unknown key'];
        yield 'ui backend is empty' => [[...$base, 'ui' => ['backend' => '']], 'ui.backend: must be a non-empty string'];
        foreach (['Drawn', '9x', '-x', 'x y', "drawn\n"] as $backend) {
            yield 'ui backend ' . json_encode($backend) => [[...$base, 'ui' => ['backend' => $backend]], 'ui.backend: must start with a lowercase letter'];
        }

        yield 'libs is null' => [[...$base, 'libs' => null], 'libs: must be a JSON object'];
        yield 'libs is a list' => [[...$base, 'libs' => ['^1']], 'libs: must be a JSON object keyed by name'];
        yield 'lib named 0' => [$raw('"libs":{"0":"^1"}'), 'libs: must be a JSON object keyed by name (a name cannot be only digits)'];
        yield 'lib named 1' => [$raw('"libs":{"1":"^1"}'), 'libs.1: the library name must start with a lowercase letter'];
        yield 'lib named -1' => [$raw('"libs":{"-1":"^1"}'), 'libs.-1: the library name must start with a lowercase letter'];
        foreach (['LibRaphp', '9lib', '-lib', '.lib', '_lib', 'a b', "libraphp\n"] as $name) {
            yield 'lib name ' . json_encode($name) => [[...$base, 'libs' => [$name => '^1']], 'the library name must start with a lowercase letter'];
        }

        yield 'lib constraint is a number' => [[...$base, 'libs' => ['libraphp' => 1]], 'libs.libraphp: the version constraint must be a non-empty string'];
        yield 'lib constraint is empty' => [[...$base, 'libs' => ['libraphp' => '']], 'libs.libraphp: the version constraint must be a non-empty string'];
        yield 'lib constraint has a newline' => [[...$base, 'libs' => ['libraphp' => "^1\n"]], 'libs.libraphp: must not contain control characters'];
        yield 'lib constraint has an escape character' => [[...$base, 'libs' => ['libraphp' => "^1\x1b[31m"]], 'libs.libraphp: must not contain control characters'];
        yield 'lib constraint has a C1 control' => [[...$base, 'libs' => ['libraphp' => "^1\u{0085}"]], 'libs.libraphp: must not contain control characters'];
        yield 'lib constraint is too long' => [[...$base, 'libs' => ['libraphp' => str_repeat('1', 1025)]], 'libs.libraphp: must be at most 1024 bytes'];

        yield 'php is null' => [[...$base, 'php' => null], 'php: must be a JSON object'];
        yield 'php unknown key' => [[...$base, 'php' => ['colour' => 1]], 'php.colour: unknown key; allowed here: extensions, ini'];
        yield 'php extensions is not a list' => [[...$base, 'php' => ['extensions' => ['a' => 'ffi']]], 'php.extensions: must be a list of extension names'];
        yield 'php extensions is null' => [[...$base, 'php' => ['extensions' => null]], 'php.extensions: must be a list of extension names'];
        foreach (['FFI', 'ffi-x', 'ffi.x', 'ffi x', '', "ffi\n", 5, null, ['ffi']] as $extension) {
            yield 'php extension ' . json_encode($extension) => [[...$base, 'php' => ['extensions' => [$extension]]], 'php.extensions[0]: must be a lowercase extension name'];
        }

        yield 'php ini is null' => [[...$base, 'php' => ['ini' => null]], 'php.ini: must be a JSON object'];
        yield 'php ini is a list' => [[...$base, 'php' => ['ini' => ['x']]], 'php.ini: must be a JSON object keyed by name'];
        yield 'php ini named 0' => [$raw('"php":{"ini":{"0":"x"}}'), 'php.ini: must be a JSON object keyed by name (a name cannot be only digits)'];
        yield 'php ini named 1' => [$raw('"php":{"ini":{"1":"x"}}'), 'php.ini.1: the setting name must start with a letter'];
        yield 'php ini named -1' => [$raw('"php":{"ini":{"-1":"x"}}'), 'php.ini.-1: the setting name must start with a letter'];
        yield 'php ini name with a space' => [[...$base, 'php' => ['ini' => ['bad name' => 'x']]], 'php.ini.bad name: the setting name must start with a letter'];
        yield 'php ini name with a dash' => [[...$base, 'php' => ['ini' => ['bad-name' => 'x']]], 'php.ini.bad-name: the setting name must start with a letter'];
        yield 'php ini value is an array' => [[...$base, 'php' => ['ini' => ['memory_limit' => ['1G']]]], 'php.ini.memory_limit: must be a string, number or boolean'];
        yield 'php ini value is null' => [[...$base, 'php' => ['ini' => ['memory_limit' => null]]], 'php.ini.memory_limit: must be a string, number or boolean'];
        yield 'php ini value has a newline' => [[...$base, 'php' => ['ini' => ['memory_limit' => "1G\n"]]], 'php.ini.memory_limit: must not contain control characters'];
        yield 'php ini value is too long' => [[...$base, 'php' => ['ini' => ['memory_limit' => str_repeat('1', 1025)]]], 'php.ini.memory_limit: must be at most 1024 bytes'];

        yield 'build is null' => [[...$base, 'build' => null], 'build: must be a JSON object'];
        yield 'build is not an object' => [[...$base, 'build' => 'dist'], 'build: must be a JSON object'];
        yield 'build without output' => [[...$base, 'build' => ['embedShim' => true]], 'build.output: is required'];
        yield 'build embedShim is not a boolean' => [[...$base, 'build' => ['output' => 'dist', 'embedShim' => 1]], 'build.embedShim: must be true or false'];
        yield 'build unknown key' => [[...$base, 'build' => ['output' => 'dist', 'colour' => 1]], 'build.colour: unknown key; allowed here: output, embedShim'];
    }

    /**
     * @param array<string, mixed>|string $content
     */
    #[DataProvider('invalidManifests')]
    public function testAnInvalidManifestIsRejectedWithAnExactMessage(array|string $content, string $expectedProblem): void
    {
        self::assertStringContainsString($expectedProblem, implode("\n", $this->problemsFor($content)));
    }

    public function testEveryProblemIsReportedTogether(): void
    {
        $problems = $this->problemsFor(
            ['name' => 'Bad Name', 'workers' => ['main' => ['pool' => 0]], 'colour' => 'red'],
            ['RAPHP_LOG_LEVEL' => 'loud'],
        );

        $all = implode("\n", $problems);
        self::assertStringContainsString("name: must look like 'vendor/package'", $all);
        self::assertStringContainsString('entry: is required', $all);
        self::assertStringContainsString('colour: unknown key', $all);
        self::assertStringContainsString('workers.main.entry: is required', $all);
        self::assertStringContainsString('workers.main.pool: must be an integer from 1 to 256', $all);
        self::assertStringContainsString("RAPHP_LOG_LEVEL: 'loud' is not a log level", $all);
        self::assertGreaterThanOrEqual(6, count($problems));
    }

    public function testTheExceptionMessageListsEveryProblemOnItsOwnLine(): void
    {
        try {
            (new ConfigLoader())->load($this->write(['colour' => 'red']));
            self::fail('Expected InvalidConfig.');
        } catch (InvalidConfig $exception) {
            self::assertStringStartsWith('Invalid configuration (', $exception->getMessage());
            self::assertStringContainsString("\n  - name: is required", $exception->getMessage());
            self::assertStringContainsString("\n  - entry: is required", $exception->getMessage());
        }
    }

    // ---------------------------------------------------------------- bounded and safe output

    public function testTheProblemListIsCappedAndTheRestIsCounted(): void
    {
        $manifest = self::minimal();
        for ($index = 0; $index < 200; $index++) {
            $manifest["key{$index}"] = 1;
        }

        try {
            (new ConfigLoader())->load($this->write($manifest));
            self::fail('Expected InvalidConfig.');
        } catch (InvalidConfig $exception) {
            self::assertCount(50, $exception->problems());
            self::assertStringEndsWith("\n  ... and 150 more problems not shown", $exception->getMessage());
        }
    }

    public function testASingleOmittedProblemIsNotPluralised(): void
    {
        $manifest = self::minimal();
        for ($index = 0; $index < 51; $index++) {
            $manifest["key{$index}"] = 1;
        }

        try {
            (new ConfigLoader())->load($this->write($manifest));
            self::fail('Expected InvalidConfig.');
        } catch (InvalidConfig $exception) {
            self::assertStringEndsWith("\n  ... and 1 more problem not shown", $exception->getMessage());
        }
    }

    public function testExactlyFiftyProblemsOmitNothing(): void
    {
        $manifest = self::minimal();
        for ($index = 0; $index < 50; $index++) {
            $manifest["key{$index}"] = 1;
        }

        try {
            (new ConfigLoader())->load($this->write($manifest));
            self::fail('Expected InvalidConfig.');
        } catch (InvalidConfig $exception) {
            self::assertCount(50, $exception->problems());
            self::assertStringNotContainsString('more problem', $exception->getMessage());
        }
    }

    public function testAHugeKeyCannotInflateTheMessage(): void
    {
        try {
            (new ConfigLoader())->load($this->write([...self::minimal(), str_repeat('k', 100_000) => 1]));
            self::fail('Expected InvalidConfig.');
        } catch (InvalidConfig $exception) {
            self::assertLessThan(1_000, strlen($exception->getMessage()));
            self::assertStringContainsString('...: unknown key', $exception->getMessage());
        }
    }

    public function testAMultibyteKeyIsCutOnACharacterBoundary(): void
    {
        $problems = $this->problemsFor([...self::minimal(), str_repeat('é', 100) => 1]);

        self::assertSame(1, preg_match('//u', implode("\n", $problems)), 'the shortened key must stay valid UTF-8');
        self::assertStringStartsWith(str_repeat('é', 80) . '...: unknown key', $problems[0]);
    }

    public function testControlCharactersInUserKeysCannotReachTheErrorOutput(): void
    {
        $problems = $this->problemsFor([
            ...self::minimal(),
            "evil\e[31m\nkey" => 1,
            'workers' => ["main\n" => ['entry' => 'w.php'], "x\e[2J" => ['entry' => 'w.php']],
        ]);

        self::assertNotSame([], $problems);
        foreach ($problems as $problem) {
            self::assertSame(0, preg_match('/[\x00-\x1f\x7f]/', $problem), 'A control character reached: ' . json_encode($problem));
        }

        self::assertStringContainsString('evil\\033[31m\\nkey: unknown key', implode("\n", $problems));
    }

    public function testC1ControlCharactersInKeysAreEscaped(): void
    {
        $problems = $this->problemsFor([...self::minimal(), "a\u{0085}b" => 1, "c\u{009b}d" => 1]);

        $all = implode("\n", $problems);
        self::assertStringContainsString('a\\u0085b: unknown key', $all);
        self::assertStringContainsString('c\\u009bd: unknown key', $all);
        self::assertSame(0, preg_match('/\xC2[\x80-\x9f]/', $all));
    }

    public function testTheFilePathInTheMessageIsEscaped(): void
    {
        try {
            (new ConfigLoader())->load($this->directory . "/evil\nname\e[31m.json");
            self::fail('Expected InvalidConfig.');
        } catch (InvalidConfig $exception) {
            self::assertSame(1, substr_count($exception->getMessage(), "\n"), 'only the line break after the header may remain');
            self::assertStringContainsString('evil\\nname\\033[31m.json', $exception->getMessage());
        }
    }

    public function testAnInvalidUtf8FilePathIsEscapedToValidUtf8(): void
    {
        try {
            (new ConfigLoader())->load($this->directory . "/missing-\xff.json");
            self::fail('Expected InvalidConfig.');
        } catch (InvalidConfig $exception) {
            self::assertSame(1, preg_match('//u', $exception->getMessage()), 'the message must be valid UTF-8');
            self::assertStringContainsString('missing-\\xff.json', $exception->getMessage());
        }
    }

    public function testQuotedValuesEscapeBackslashesSoAnEscapeCannotBeForged(): void
    {
        $problems = $this->problemsFor(['name' => 'a\\nb/c', 'entry' => 'a.php']);

        self::assertStringContainsString("got 'a\\\\nb/c'", implode("\n", $problems));
    }

    public function testErrorMessagesEscapeControlCharactersInValuesSoANewlineCannotHide(): void
    {
        $problems = $this->problemsFor(['name' => "acme/files\n", 'entry' => 'a.php']);

        self::assertStringContainsString("got 'acme/files\\n'", implode("\n", $problems));
        self::assertCount(1, $problems);
    }

    public function testAHugeQuotedValueIsShortened(): void
    {
        $problems = $this->problemsFor(['name' => str_repeat('A', 500), 'entry' => 'a.php']);

        self::assertLessThan(400, strlen($problems[0]));
        self::assertStringContainsString("AAAA...'", $problems[0]);
    }

    public function testAStringOfExactlyTheLengthLimitIsAccepted(): void
    {
        $entry = str_repeat('a', 1024);

        $config = (new ConfigLoader())->load($this->write(['name' => 'acme/files', 'entry' => $entry]));

        self::assertSame($entry, $config->entry);
    }

    public function testEveryControlCharacterInAKeyIsEscapedIncludingNulUnitSeparatorAndDel(): void
    {
        $problems = $this->problemsFor([
            ...self::minimal(),
            "nul\0key" => 1,
            "us\x1fkey" => 1,
            "del\x7fkey" => 1,
            "tab\tkey" => 1,
            "cr\rkey" => 1,
        ]);

        $all = implode("\n", $problems);
        self::assertSame(0, preg_match('/[\x00-\x09\x0b-\x1f\x7f]/', $all), 'a raw control character reached the output');
        self::assertStringContainsString('nul\\000key: unknown key', $all);
        self::assertStringContainsString('us\\037key: unknown key', $all);
        self::assertStringContainsString('del\\177key: unknown key', $all);
        self::assertStringContainsString('tab\\tkey: unknown key', $all);
        self::assertStringContainsString('cr\\rkey: unknown key', $all);
    }

    public function testDelAndNulInQuotedValuesAreEscaped(): void
    {
        $problems = $this->problemsFor(['name' => "acme/f\x7f\0", 'entry' => 'a.php']);

        self::assertStringContainsString("got 'acme/f\\177\\000'", implode("\n", $problems));
    }
}
