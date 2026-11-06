<?php

declare(strict_types=1);

namespace Raphp\Core\Config;

use JsonException;
use Raphp\Core\Logging\LogLevel;

/**
 * Reads `raphp.json` and applies environment overrides, producing an immutable {@see Config}.
 *
 * Validation is strict: unknown keys are errors, an explicit `null` is never treated as
 * "absent", names must be real names (never digits only, so PHP never turns a key into an
 * integer), and strings may not hold control characters. Every problem found is reported
 * together in one {@see InvalidConfigException}, capped at {@see Problems::MAX}. The loader reads no
 * global state: the caller passes the environment, usually `getenv()`, from the entry point.
 * A value that is not a string is reported as a problem, never a crash.
 *
 * The file is resolved once with realpath() and that path is used for every check and for
 * reading, so the checks and the read see the same real local file. Stream wrappers such as
 * `file://` or `phar://` are not accepted.
 *
 * Environment overrides (FRAMEWORK 9.2):
 *  - RAPHP_LOG_LEVEL   a PSR-3 level name, default "info"
 *  - RAPHP_IPC_TRACE   boolean, default false
 *  - RAPHP_UI_ENABLED  boolean, overrides `ui.enabled` from the file
 * An empty value counts as unset.
 *
 * Known limitation: if a JSON object repeats a key, PHP keeps the last one without a warning.
 * Detecting that would need a custom JSON parser.
 */
final class ConfigLoader
{
    /** The largest manifest accepted, in bytes. A guard against accidents (FRAMEWORK 9.2). */
    public const int MAX_BYTES = 1_048_576;

    /** The largest worker pool accepted. A guard against typos such as an extra zero (FRAMEWORK 9.2). */
    public const int MAX_POOL = 256;

    /** The longest single string accepted, in bytes (FRAMEWORK 9.2). */
    public const int MAX_STRING_BYTES = 1024;

    private const array TOP_LEVEL_KEYS = ['name', 'entry', 'workers', 'ui', 'libs', 'php', 'build'];

    private const array TRUE_VALUES = ['1', 'true', 'yes', 'on'];

    private const array FALSE_VALUES = ['0', 'false', 'no', 'off'];

    // \z, not $: in PCRE "$" also matches just before a trailing newline, which would let "name\n" through.
    private const string NAME_PATTERN = '/^[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*\z/';

    private const string WORKER_NAME_PATTERN = '/^[a-z][a-z0-9_-]*\z/';

    private const string LIB_NAME_PATTERN = '/^[a-z][a-z0-9._-]*\z/';

    private const string BACKEND_PATTERN = '/^[a-z][a-z0-9_-]*\z/';

    private const string INI_NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_.]*\z/';

    private const string EXTENSION_PATTERN = '/^[a-z0-9_]+\z/';

    private const string CONTROL_CHARACTERS = '/[\x00-\x1f\x7f]|\xC2[\x80-\x9f]/';

    private const string BYTE_ORDER_MARK = "\xEF\xBB\xBF";

    /**
     * @param array<string, mixed> $environment variables such as RAPHP_LOG_LEVEL, normally from getenv()
     *
     * @throws InvalidConfigException
     */
    public function load(string $file, array $environment = []): Config
    {
        $problems = new Problems();
        $resolved = realpath($file);
        $data = null;
        if ($resolved === false) {
            $problems->add('', 'the file does not exist or is not readable');
        } else {
            $data = $this->readJson($resolved, $problems);
        }

        $name = null;
        $entry = null;
        $workers = [];
        $ui = new UiConfig();
        $libs = [];
        $php = new PhpConfig();
        $build = null;

        if ($data !== null) {
            $this->rejectUnknown($data, self::TOP_LEVEL_KEYS, '', $problems);
            $name = $this->name($data, $problems);
            $entry = $this->requiredPath($data, 'entry', 'entry', $problems);
            $workers = $this->workers($this->section($data, 'workers'), $problems);
            $ui = $this->ui($this->section($data, 'ui'), $problems);
            $libs = $this->libs($this->section($data, 'libs'), $problems);
            $php = $this->php($this->section($data, 'php'), $problems);
            $build = array_key_exists('build', $data) ? $this->build($data['build'], $problems) : null;
        }

        $logLevel = $this->logLevel($environment, $problems);
        $ipcTrace = $this->envBool($environment, 'RAPHP_IPC_TRACE', $problems) ?? false;
        $uiOverride = $this->envBool($environment, 'RAPHP_UI_ENABLED', $problems);
        if ($uiOverride !== null) {
            $ui = new UiConfig($uiOverride, $ui->backend);
        }

        if (!$problems->isEmpty() || $resolved === false || $name === null || $entry === null) {
            throw InvalidConfigException::because($file, $problems->all(), $problems->omitted());
        }

        return new Config(
            name: $name,
            entry: $entry,
            basePath: dirname($resolved),
            workers: $workers,
            ui: $ui,
            libs: $libs,
            php: $php,
            build: $build,
            logLevel: $logLevel,
            ipcTrace: $ipcTrace,
        );
    }

    /**
     * @param string $path a path already resolved with realpath()
     *
     * @return array<array-key, mixed>|null
     */
    private function readJson(string $path, Problems $problems): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            $problems->add('', 'the file does not exist or is not readable');

            return null;
        }

        $size = filesize($path);
        if ($size !== false && $size > self::MAX_BYTES) {
            $problems->add('', 'the file is larger than 1 MiB, which is far more than a manifest needs');

            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            $problems->add('', 'the file could not be read');

            return null;
        }

        if (str_starts_with($raw, self::BYTE_ORDER_MARK)) {
            $problems->add('', 'the file starts with a UTF-8 byte order mark; save it without one');

            return null;
        }

        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $problems->add('', 'invalid JSON: ' . $exception->getMessage());

            return null;
        }

        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            $problems->add('', 'the top level must be a JSON object');

            return null;
        }

        return $decoded;
    }

    /**
     * An absent section is empty. A section that is present must be valid, so an explicit null
     * is passed on and rejected by the caller.
     *
     * @param array<array-key, mixed> $data
     */
    private function section(array $data, string $key): mixed
    {
        return array_key_exists($key, $data) ? $data[$key] : [];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function name(array $data, Problems $problems): ?string
    {
        $name = $this->requiredString($data, 'name', 'name', $problems);
        if ($name !== null && preg_match(self::NAME_PATTERN, $name) !== 1) {
            $problems->add('name', "must look like 'vendor/package' (lowercase letters, digits, '.', '_' and '-'), got " . $this->quote($name));

            return null;
        }

        return $name;
    }

    /**
     * @return array<string, WorkerConfig>
     */
    private function workers(mixed $value, Problems $problems): array
    {
        $section = $this->object($value, 'workers', $problems, keyedByName: true);
        $workers = [];
        foreach ($section ?? [] as $key => $definition) {
            $workerName = (string) $key;
            $path = "workers.{$workerName}";
            if (preg_match(self::WORKER_NAME_PATTERN, $workerName) !== 1) {
                $problems->add($path, 'the worker name must start with a lowercase letter and use only lowercase letters, digits, "_" and "-"');
            }

            $fields = $this->object($definition, $path, $problems);
            if ($fields === null) {
                continue;
            }

            $this->rejectUnknown($fields, ['entry', 'pool'], $path, $problems);
            $entry = $this->requiredPath($fields, 'entry', "{$path}.entry", $problems);
            $pool = 1;
            if (array_key_exists('pool', $fields)) {
                $poolValue = $fields['pool'];
                if (!is_int($poolValue) || $poolValue < 1 || $poolValue > self::MAX_POOL) {
                    $problems->add("{$path}.pool", 'must be an integer from 1 to ' . self::MAX_POOL);
                } else {
                    $pool = $poolValue;
                }
            }

            if ($entry !== null) {
                $workers[$workerName] = new WorkerConfig($workerName, $entry, $pool);
            }
        }

        return $workers;
    }

    private function ui(mixed $value, Problems $problems): UiConfig
    {
        $fields = $this->object($value, 'ui', $problems);
        if ($fields === null) {
            return new UiConfig();
        }

        $this->rejectUnknown($fields, ['enabled', 'backend'], 'ui', $problems);
        $enabled = $this->optionalBool($fields, 'enabled', 'ui.enabled', $problems) ?? false;
        $backend = $this->optionalString($fields, 'backend', 'ui.backend', $problems) ?? 'default';
        if (preg_match(self::BACKEND_PATTERN, $backend) !== 1) {
            $problems->add('ui.backend', 'must start with a lowercase letter and use only lowercase letters, digits, "_" and "-"');
            $backend = 'default';
        }

        return new UiConfig($enabled, $backend);
    }

    /**
     * @return array<string, string>
     */
    private function libs(mixed $value, Problems $problems): array
    {
        $section = $this->object($value, 'libs', $problems, keyedByName: true);
        $libs = [];
        foreach ($section ?? [] as $key => $constraint) {
            $libName = (string) $key;
            $path = "libs.{$libName}";
            if (preg_match(self::LIB_NAME_PATTERN, $libName) !== 1) {
                $problems->add($path, 'the library name must start with a lowercase letter and use only lowercase letters, digits, ".", "_" and "-"');

                continue;
            }

            if (!is_string($constraint) || $constraint === '') {
                $problems->add($path, 'the version constraint must be a non-empty string');

                continue;
            }

            if (!$this->plainText($constraint, $path, $problems)) {
                continue;
            }

            $libs[$libName] = $constraint;
        }

        return $libs;
    }

    private function php(mixed $value, Problems $problems): PhpConfig
    {
        $fields = $this->object($value, 'php', $problems);
        if ($fields === null) {
            return new PhpConfig();
        }

        $this->rejectUnknown($fields, ['extensions', 'ini'], 'php', $problems);

        $extensions = [];
        if (array_key_exists('extensions', $fields)) {
            $list = $fields['extensions'];
            if (!is_array($list) || !array_is_list($list)) {
                $problems->add('php.extensions', 'must be a list of extension names');
            } else {
                foreach ($list as $index => $extension) {
                    if (!is_string($extension) || preg_match(self::EXTENSION_PATTERN, $extension) !== 1) {
                        $problems->add("php.extensions[{$index}]", 'must be a lowercase extension name such as "sockets"');

                        continue;
                    }

                    $extensions[] = $extension;
                }
            }
        }

        $ini = [];
        $iniSection = $this->object($this->section($fields, 'ini'), 'php.ini', $problems, keyedByName: true);
        foreach ($iniSection ?? [] as $setting => $settingValue) {
            $settingName = (string) $setting;
            $path = "php.ini.{$settingName}";
            if (preg_match(self::INI_NAME_PATTERN, $settingName) !== 1) {
                $problems->add($path, 'the setting name must start with a letter or "_" and use only letters, digits, "_" and "."');

                continue;
            }

            if (is_bool($settingValue)) {
                $ini[$settingName] = $settingValue ? '1' : '0';
            } elseif (is_string($settingValue)) {
                if ($this->plainText($settingValue, $path, $problems)) {
                    $ini[$settingName] = $settingValue;
                }
            } elseif (is_int($settingValue) || is_float($settingValue)) {
                $ini[$settingName] = (string) $settingValue;
            } else {
                $problems->add($path, 'must be a string, number or boolean');
            }
        }

        return new PhpConfig($extensions, $ini);
    }

    private function build(mixed $value, Problems $problems): ?BuildConfig
    {
        $fields = $this->object($value, 'build', $problems);
        if ($fields === null) {
            return null;
        }

        $this->rejectUnknown($fields, ['output', 'embedShim'], 'build', $problems);
        $output = $this->requiredPath($fields, 'output', 'build.output', $problems);
        $embedShim = $this->optionalBool($fields, 'embedShim', 'build.embedShim', $problems) ?? true;

        return $output === null ? null : new BuildConfig($output, $embedShim);
    }

    /**
     * @param array<string, mixed> $environment
     */
    private function logLevel(array $environment, Problems $problems): LogLevel
    {
        $raw = $this->environmentValue($environment, 'RAPHP_LOG_LEVEL', $problems);
        if ($raw === '') {
            return LogLevel::Info;
        }

        $level = LogLevel::fromName($raw);
        if ($level === null) {
            $names = implode(', ', array_map(static fn(LogLevel $case): string => $case->value, LogLevel::cases()));
            $problems->add('RAPHP_LOG_LEVEL', $this->quote($raw) . " is not a log level; use one of: {$names}");

            return LogLevel::Info;
        }

        return $level;
    }

    /**
     * @param array<string, mixed> $environment
     */
    private function envBool(array $environment, string $variable, Problems $problems): ?bool
    {
        $raw = $this->environmentValue($environment, $variable, $problems);
        if ($raw === '') {
            return null;
        }

        $normalised = strtolower($raw);
        if (in_array($normalised, self::TRUE_VALUES, true)) {
            return true;
        }

        if (in_array($normalised, self::FALSE_VALUES, true)) {
            return false;
        }

        $problems->add($variable, $this->quote($raw) . ' is not a boolean; use one of: ' . implode(', ', [...self::TRUE_VALUES, ...self::FALSE_VALUES]));

        return null;
    }

    /**
     * Returns the variable's value, or an empty string when it is unset or empty. A value that
     * is not a string (a caller passing $_SERVER, say) is reported instead of crashing.
     *
     * @param array<string, mixed> $environment
     */
    private function environmentValue(array $environment, string $variable, Problems $problems): string
    {
        $value = $environment[$variable] ?? '';
        if (!is_string($value)) {
            $problems->add($variable, 'must be a string');

            return '';
        }

        return $value;
    }

    /**
     * @param bool $keyedByName true for sections whose keys are names: a JSON object such as
     *                          {"0": ...} decodes to a PHP list, and the message then explains why
     *
     * @return array<array-key, mixed>|null
     */
    private function object(mixed $value, string $path, Problems $problems, bool $keyedByName = false): ?array
    {
        if (!is_array($value)) {
            $problems->add($path, 'must be a JSON object');

            return null;
        }

        if ($value !== [] && array_is_list($value)) {
            $problems->add($path, $keyedByName ? 'must be a JSON object keyed by name (a name cannot be only digits)' : 'must be a JSON object');

            return null;
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param list<string> $allowed
     */
    private function rejectUnknown(array $data, array $allowed, string $path, Problems $problems): void
    {
        foreach (array_keys($data) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                $where = $path === '' ? (string) $key : "{$path}.{$key}";
                $problems->add($where, 'unknown key; allowed here: ' . implode(', ', $allowed));
            }
        }
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function requiredString(array $data, string $key, string $path, Problems $problems): ?string
    {
        if (!array_key_exists($key, $data)) {
            $problems->add($path, 'is required');

            return null;
        }

        return $this->optionalString($data, $key, $path, $problems);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function optionalString(array $data, string $key, string $path, Problems $problems): ?string
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }

        $value = $data[$key];
        if (!is_string($value) || $value === '') {
            $problems->add($path, 'must be a non-empty string');

            return null;
        }

        if (strlen($value) > self::MAX_STRING_BYTES) {
            $problems->add($path, 'must be at most ' . self::MAX_STRING_BYTES . ' bytes');

            return null;
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function optionalBool(array $data, string $key, string $path, Problems $problems): ?bool
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }

        $value = $data[$key];
        if (!is_bool($value)) {
            $problems->add($path, 'must be true or false');

            return null;
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function requiredPath(array $data, string $key, string $path, Problems $problems): ?string
    {
        $value = $this->requiredString($data, $key, $path, $problems);
        if ($value === null) {
            return null;
        }

        if (!$this->isCleanRelativePath($value)) {
            $problems->add($path, "must be a clean relative path inside the project (no leading '/', no '.', '..' or empty segments, no backslashes or control characters), got " . $this->quote($value));

            return null;
        }

        return $value;
    }

    /**
     * A path that stays inside the project and has one spelling: it is relative, every segment is
     * a real name, and it has no backslashes or control characters.
     *
     * The leading-slash test is also covered by the empty-segment rule below (a leading "/"
     * produces an empty first segment). It stays to state the intent plainly.
     */
    private function isCleanRelativePath(string $value): bool
    {
        if (str_starts_with($value, '/') || str_contains($value, '\\') || preg_match(self::CONTROL_CHARACTERS, $value) === 1) {
            return false;
        }

        foreach (explode('/', $value) as $segment) {
            if (in_array($segment, ['', '.', '..'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Free text (a version constraint, an ini value) must be short and free of control characters.
     */
    private function plainText(string $value, string $path, Problems $problems): bool
    {
        if (strlen($value) > self::MAX_STRING_BYTES) {
            $problems->add($path, 'must be at most ' . self::MAX_STRING_BYTES . ' bytes');

            return false;
        }

        if (preg_match(self::CONTROL_CHARACTERS, $value) === 1) {
            $problems->add($path, 'must not contain control characters');

            return false;
        }

        return true;
    }

    /**
     * Quotes a value for an error message: shortened, with control characters and backslashes
     * escaped so a newline cannot hide in it.
     */
    private function quote(string $value): string
    {
        return "'" . Problems::escape(Problems::shorten($value), escapeBackslash: true) . "'";
    }
}
