<?php

declare(strict_types=1);

namespace Raphp\Core\Config;

use JsonException;
use Psr\Log\LogLevel;

/**
 * Reads `raphp.json` and applies environment overrides, producing an immutable {@see Config}.
 *
 * Validation is strict: unknown keys are errors, an explicit `null` is never treated as
 * "absent", and every problem found is reported together in one {@see InvalidConfig}. The
 * loader reads no global state. The caller passes the environment, usually `getenv()`, from
 * the entry point. A value that is not a string is reported as a problem, never a crash.
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

    private const array TOP_LEVEL_KEYS = ['name', 'entry', 'workers', 'ui', 'libs', 'php', 'build'];

    private const array LOG_LEVELS = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
        LogLevel::NOTICE,
        LogLevel::INFO,
        LogLevel::DEBUG,
    ];

    private const array TRUE_VALUES = ['1', 'true', 'yes', 'on'];

    private const array FALSE_VALUES = ['0', 'false', 'no', 'off'];

    // \z, not $: in PCRE "$" also matches just before a trailing newline, which would let "name\n" through.
    private const string NAME_PATTERN = '/^[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*\z/';

    private const string WORKER_NAME_PATTERN = '/^[a-z][a-z0-9_-]*\z/';

    private const string LIB_NAME_PATTERN = '/^[a-z0-9][a-z0-9._-]*\z/';

    private const string EXTENSION_PATTERN = '/^[a-z0-9_]+\z/';

    private const string BYTE_ORDER_MARK = "\xEF\xBB\xBF";

    /**
     * @param array<string, mixed> $environment variables such as RAPHP_LOG_LEVEL, normally from getenv()
     *
     * @throws InvalidConfig
     */
    public function load(string $file, array $environment = []): Config
    {
        $problems = new Problems();
        $data = $this->readJson($file, $problems);

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

        if (!$problems->isEmpty() || $name === null || $entry === null) {
            throw InvalidConfig::because($file, $problems->all());
        }

        return new Config(
            name: $name,
            entry: $entry,
            basePath: dirname((string) realpath($file)),
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
     * @return array<array-key, mixed>|null
     */
    private function readJson(string $file, Problems $problems): ?array
    {
        if (!is_file($file) || !is_readable($file)) {
            $problems->add('', 'the file does not exist or is not readable');

            return null;
        }

        $size = filesize($file);
        if ($size !== false && $size > self::MAX_BYTES) {
            $problems->add('', 'the file is larger than 1 MiB, which is far more than a manifest needs');

            return null;
        }

        $raw = file_get_contents($file);
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
        $section = $this->object($value, 'workers', $problems);
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

        return new UiConfig($enabled, $backend);
    }

    /**
     * @return array<string, string>
     */
    private function libs(mixed $value, Problems $problems): array
    {
        $section = $this->object($value, 'libs', $problems);
        $libs = [];
        foreach ($section ?? [] as $key => $constraint) {
            $libName = (string) $key;
            $path = "libs.{$libName}";
            if (preg_match(self::LIB_NAME_PATTERN, $libName) !== 1) {
                $problems->add($path, 'the library name may use only lowercase letters, digits, ".", "_" and "-"');
            }

            if (!is_string($constraint) || $constraint === '') {
                $problems->add($path, 'the version constraint must be a non-empty string');

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
        $iniSection = $this->object($this->section($fields, 'ini'), 'php.ini', $problems);
        foreach ($iniSection ?? [] as $setting => $settingValue) {
            $path = 'php.ini.' . (string) $setting;
            if (is_bool($settingValue)) {
                $ini[(string) $setting] = $settingValue ? '1' : '0';
            } elseif (is_string($settingValue) || is_int($settingValue) || is_float($settingValue)) {
                $ini[(string) $setting] = (string) $settingValue;
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
    private function logLevel(array $environment, Problems $problems): string
    {
        $raw = $this->environmentValue($environment, 'RAPHP_LOG_LEVEL', $problems);
        if ($raw === '') {
            return LogLevel::INFO;
        }

        $level = strtolower($raw);
        if (!in_array($level, self::LOG_LEVELS, true)) {
            $problems->add('RAPHP_LOG_LEVEL', $this->quote($raw) . ' is not a log level; use one of: ' . implode(', ', self::LOG_LEVELS));

            return LogLevel::INFO;
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
     * @return array<array-key, mixed>|null
     */
    private function object(mixed $value, string $path, Problems $problems): ?array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $problems->add($path, 'must be a JSON object');

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
     */
    private function isCleanRelativePath(string $value): bool
    {
        if (str_starts_with($value, '/') || str_contains($value, '\\') || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
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
     * Quotes a value for an error message, escaping control characters and backslashes so a
     * newline cannot hide in it.
     */
    private function quote(string $value): string
    {
        return "'" . addcslashes($value, "\0..\37\177\\") . "'";
    }
}
