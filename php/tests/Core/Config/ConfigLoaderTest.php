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
        $files = glob($this->directory . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
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
        self::assertSame('info', $config->logLevel);
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
            ],
            'ui' => ['enabled' => true, 'backend' => 'drawn'],
            'libs' => ['libraphp' => '^0.1'],
            'php' => [
                'extensions' => ['ffi', 'pcntl', 'posix', 'sockets'],
                'ini' => ['memory_limit' => '256M', 'opcache.enable' => true, 'precision' => 14, 'assert.exception' => false],
            ],
            'build' => ['output' => 'dist/files', 'embedShim' => false],
        ]));

        self::assertEquals(new WorkerConfig('main', 'src/worker.php', 2), $config->workers['main']);
        self::assertEquals(new WorkerConfig('scan-2', 'src/scan.php', 1), $config->workers['scan-2']);
        self::assertEquals(new UiConfig(true, 'drawn'), $config->ui);
        self::assertSame(['libraphp' => '^0.1'], $config->libs);
        self::assertSame(['ffi', 'pcntl', 'posix', 'sockets'], $config->php->extensions);
        self::assertSame(
            ['memory_limit' => '256M', 'opcache.enable' => '1', 'precision' => '14', 'assert.exception' => '0'],
            $config->php->ini,
        );
        self::assertEquals(new BuildConfig('dist/files', false), $config->build);
    }

    public function testTheBuildSectionDefaultsToEmbeddingTheShim(): void
    {
        $config = (new ConfigLoader())->load($this->write([...self::minimal(), 'build' => ['output' => 'dist/app']]));

        self::assertEquals(new BuildConfig('dist/app', true), $config->build);
    }

    public function testEnvironmentVariablesOverrideTheFileAndTheDefaults(): void
    {
        $manifest = [...self::minimal(), 'ui' => ['enabled' => false, 'backend' => 'drawn']];

        $config = (new ConfigLoader())->load($this->write($manifest), [
            'RAPHP_LOG_LEVEL' => 'DEBUG',
            'RAPHP_IPC_TRACE' => 'true',
            'RAPHP_UI_ENABLED' => '1',
        ]);

        self::assertSame('debug', $config->logLevel);
        self::assertTrue($config->ipcTrace);
        self::assertEquals(new UiConfig(true, 'drawn'), $config->ui);
    }

    public function testTheEnvironmentCanSwitchOffTheUiTheFileTurnsOn(): void
    {
        $manifest = [...self::minimal(), 'ui' => ['enabled' => true]];

        $config = (new ConfigLoader())->load($this->write($manifest), ['RAPHP_UI_ENABLED' => 'off']);

        self::assertFalse($config->ui->enabled);
    }

    public function testEmptyEnvironmentValuesCountAsUnset(): void
    {
        $config = (new ConfigLoader())->load($this->write(self::minimal()), [
            'RAPHP_LOG_LEVEL' => '',
            'RAPHP_IPC_TRACE' => '',
            'RAPHP_UI_ENABLED' => '',
        ]);

        self::assertSame('info', $config->logLevel);
        self::assertFalse($config->ipcTrace);
        self::assertFalse($config->ui->enabled);
    }

    public function testUnrelatedEnvironmentVariablesAreIgnored(): void
    {
        $config = (new ConfigLoader())->load($this->write(self::minimal()), ['PATH' => '/usr/bin', 'HOME' => '/root']);

        self::assertSame('info', $config->logLevel);
    }

    public function testTheLoaderKeepsNoStateBetweenLoads(): void
    {
        $loader = new ConfigLoader();
        $file = $this->write(self::minimal());

        $first = $loader->load($file, ['RAPHP_LOG_LEVEL' => 'debug']);
        $second = $loader->load($file);

        self::assertSame('debug', $first->logLevel);
        self::assertSame('info', $second->logLevel);
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

    /**
     * @return iterable<string, array{array<string, mixed>|string, string}>
     */
    public static function invalidManifests(): iterable
    {
        $base = self::minimal();

        yield 'not JSON' => ['{nope', 'invalid JSON'];
        yield 'a JSON list' => ['[1, 2]', 'the top level must be a JSON object'];
        yield 'a JSON string' => ['"text"', 'the top level must be a JSON object'];
        yield 'an empty file' => ['', 'invalid JSON'];
        yield 'missing name' => [['entry' => 'a.php'], 'name: is required'];
        yield 'missing entry' => [['name' => 'acme/files'], 'entry: is required'];
        yield 'name is not a string' => [['name' => 5, 'entry' => 'a.php'], 'name: must be a non-empty string'];
        yield 'name has no vendor' => [['name' => 'files', 'entry' => 'a.php'], "name: must look like 'vendor/package'"];
        yield 'name is upper case' => [['name' => 'Acme/Files', 'entry' => 'a.php'], "name: must look like 'vendor/package'"];
        yield 'absolute entry' => [['name' => 'acme/files', 'entry' => '/etc/passwd'], 'entry: must be a clean relative path inside the project'];
        yield 'entry leaves the project' => [['name' => 'acme/files', 'entry' => 'src/../../x.php'], 'entry: must be a clean relative path inside the project'];
        yield 'unknown top-level key' => [[...$base, 'colour' => 'red'], 'colour: unknown key; allowed here: name, entry'];
        yield 'workers is a list' => [[...$base, 'workers' => [1]], 'workers: must be a JSON object'];
        yield 'worker is not an object' => [[...$base, 'workers' => ['main' => 'x']], 'workers.main: must be a JSON object'];
        yield 'worker name is upper case' => [[...$base, 'workers' => ['Main' => ['entry' => 'w.php']]], 'workers.Main: the worker name must start with a lowercase letter'];
        yield 'worker without entry' => [[...$base, 'workers' => ['main' => []]], 'workers.main.entry: is required'];
        yield 'worker pool is zero' => [[...$base, 'workers' => ['main' => ['entry' => 'w.php', 'pool' => 0]]], 'workers.main.pool: must be an integer from 1 to 256'];
        yield 'worker pool is a string' => [[...$base, 'workers' => ['main' => ['entry' => 'w.php', 'pool' => '2']]], 'workers.main.pool: must be an integer from 1 to 256'];
        yield 'worker unknown key' => [[...$base, 'workers' => ['main' => ['entry' => 'w.php', 'colour' => 1]]], 'workers.main.colour: unknown key'];
        yield 'ui is not an object' => [[...$base, 'ui' => 'yes'], 'ui: must be a JSON object'];
        yield 'ui enabled is not a boolean' => [[...$base, 'ui' => ['enabled' => 'yes']], 'ui.enabled: must be true or false'];
        yield 'ui unknown key' => [[...$base, 'ui' => ['theme' => 'dark']], 'ui.theme: unknown key'];
        yield 'lib constraint is not a string' => [[...$base, 'libs' => ['libraphp' => 1]], 'libs.libraphp: the version constraint must be a non-empty string'];
        yield 'lib name is upper case' => [[...$base, 'libs' => ['LibRaphp' => '^1']], 'libs.LibRaphp: the library name may use only'];
        yield 'php extensions is not a list' => [[...$base, 'php' => ['extensions' => ['a' => 'ffi']]], 'php.extensions: must be a list of extension names'];
        yield 'php extension is upper case' => [[...$base, 'php' => ['extensions' => ['FFI']]], 'php.extensions[0]: must be a lowercase extension name'];
        yield 'php ini value is an array' => [[...$base, 'php' => ['ini' => ['memory_limit' => ['1G']]]], 'php.ini.memory_limit: must be a string, number or boolean'];
        yield 'build without output' => [[...$base, 'build' => ['embedShim' => true]], 'build.output: is required'];
        yield 'build output leaves the project' => [[...$base, 'build' => ['output' => '../dist']], 'build.output: must be a clean relative path inside the project'];
        yield 'build embedShim is not a boolean' => [[...$base, 'build' => ['output' => 'dist', 'embedShim' => 1]], 'build.embedShim: must be true or false'];

        // Regressions found by probing the loader with hostile input.
        yield 'name with a trailing newline' => [['name' => "acme/files\n", 'entry' => 'a.php'], "name: must look like 'vendor/package'"];
        yield 'worker name with a trailing newline' => [[...$base, 'workers' => ["main\n" => ['entry' => 'w.php']]], 'the worker name must start with a lowercase letter'];
        yield 'lib name with a trailing newline' => [[...$base, 'libs' => ["libraphp\n" => '^1']], 'the library name may use only'];
        yield 'extension with a trailing newline' => [[...$base, 'php' => ['extensions' => ["ffi\n"]]], 'php.extensions[0]: must be a lowercase extension name'];
        yield 'entry with an embedded newline' => [['name' => 'acme/files', 'entry' => "src/a\nb.php"], 'entry: must be a clean relative path inside the project'];
        yield 'entry with a backslash' => [['name' => 'acme/files', 'entry' => 'src\\..\\x.php'], 'entry: must be a clean relative path inside the project'];
        yield 'entry with a dot segment' => [['name' => 'acme/files', 'entry' => './a.php'], 'entry: must be a clean relative path inside the project'];
        yield 'entry that is a dot' => [['name' => 'acme/files', 'entry' => '.'], 'entry: must be a clean relative path inside the project'];
        yield 'entry with an empty segment' => [['name' => 'acme/files', 'entry' => 'src//a.php'], 'entry: must be a clean relative path inside the project'];
        yield 'entry ending in a slash' => [['name' => 'acme/files', 'entry' => 'src/'], 'entry: must be a clean relative path inside the project'];
        yield 'workers is null' => [[...$base, 'workers' => null], 'workers: must be a JSON object'];
        yield 'ui is null' => [[...$base, 'ui' => null], 'ui: must be a JSON object'];
        yield 'libs is null' => [[...$base, 'libs' => null], 'libs: must be a JSON object'];
        yield 'php is null' => [[...$base, 'php' => null], 'php: must be a JSON object'];
        yield 'php ini is null' => [[...$base, 'php' => ['ini' => null]], 'php.ini: must be a JSON object'];
        yield 'build is null' => [[...$base, 'build' => null], 'build: must be a JSON object'];
        yield 'worker pool above the maximum' => [[...$base, 'workers' => ['main' => ['entry' => 'w.php', 'pool' => 257]]], 'workers.main.pool: must be an integer from 1 to 256'];
        yield 'a byte order mark' => ["\xEF\xBB\xBF{\"name\":\"acme/files\",\"entry\":\"a.php\"}", 'the file starts with a UTF-8 byte order mark'];
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

    public function testInvalidEnvironmentValuesAreReportedByVariableName(): void
    {
        $problems = $this->problemsFor(self::minimal(), [
            'RAPHP_LOG_LEVEL' => 'loud',
            'RAPHP_IPC_TRACE' => 'maybe',
            'RAPHP_UI_ENABLED' => '2',
        ]);

        $all = implode("\n", $problems);
        self::assertStringContainsString("RAPHP_LOG_LEVEL: 'loud' is not a log level; use one of: emergency", $all);
        self::assertStringContainsString("RAPHP_IPC_TRACE: 'maybe' is not a boolean", $all);
        self::assertStringContainsString("RAPHP_UI_ENABLED: '2' is not a boolean", $all);
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

    public function testThePoolMaximumItselfIsAccepted(): void
    {
        $config = (new ConfigLoader())->load($this->write([
            ...self::minimal(),
            'workers' => ['main' => ['entry' => 'w.php', 'pool' => ConfigLoader::MAX_POOL]],
        ]));

        self::assertSame(ConfigLoader::MAX_POOL, $config->workers['main']->pool);
    }

    public function testAnOversizedFileIsRejectedWithoutBeingParsed(): void
    {
        $problems = $this->problemsFor(str_repeat(' ', ConfigLoader::MAX_BYTES + 1));

        self::assertSame(['the file is larger than 1 MiB, which is far more than a manifest needs'], $problems);
    }

    public function testAFileAtTheSizeLimitIsStillRead(): void
    {
        $padding = str_repeat(' ', ConfigLoader::MAX_BYTES - strlen('{"name":"acme/files","entry":"a.php"}'));

        $config = (new ConfigLoader())->load($this->write('{"name":"acme/files","entry":"a.php"}' . $padding));

        self::assertSame('acme/files', $config->name);
    }

    public function testErrorMessagesEscapeControlCharactersSoANewlineCannotHide(): void
    {
        $problems = $this->problemsFor(['name' => "acme/files\n", 'entry' => 'a.php']);

        self::assertStringContainsString("got 'acme/files\\n'", implode("\n", $problems));
        self::assertCount(1, $problems);
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
}
