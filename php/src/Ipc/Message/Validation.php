<?php

declare(strict_types=1);

namespace Raphp\Ipc\Message;

use InvalidArgumentException;
use stdClass;

/**
 * The value rules shared by the message classes. The messages state the rule in words that make
 * sense on the wire, because {@see EnvelopeReader} passes them on in its protocol errors.
 *
 * @internal
 */
final class Validation
{
    public const int MAX_METHOD_BYTES = 256;

    public const int MAX_TEXT_BYTES = 256;

    /**
     * @throws InvalidArgumentException
     */
    public static function positive(int $value, string $what): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException("{$what} must be a positive integer");
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function notEmpty(string $value, string $what): void
    {
        if ($value === '') {
            throw new InvalidArgumentException("{$what} must not be empty");
        }
    }

    /**
     * A method name is looked up in a registry and echoed in errors and logs, so it is bounded.
     *
     * @throws InvalidArgumentException
     */
    public static function method(string $method): void
    {
        self::notEmpty($method, '"method"');
        if (strlen($method) > self::MAX_METHOD_BYTES) {
            throw new InvalidArgumentException('"method" must be at most ' . self::MAX_METHOD_BYTES . ' bytes');
        }
    }

    /**
     * Free text from a peer (a `bye` reason) ends up in log lines, so it is bounded.
     *
     * @throws InvalidArgumentException
     */
    public static function shortText(string $value, string $what): void
    {
        if (strlen($value) > self::MAX_TEXT_BYTES) {
            throw new InvalidArgumentException("{$what} must be at most " . self::MAX_TEXT_BYTES . ' bytes');
        }
    }

    /**
     * A trace id ends up in log lines, so it is kept to a safe alphabet and a short length.
     *
     * @throws InvalidArgumentException
     */
    public static function trace(string $trace): void
    {
        if (preg_match('/\A[A-Za-z0-9_.-]{1,64}\z/', $trace) !== 1) {
            throw new InvalidArgumentException('"trace" must be 1 to 64 characters from A-Z, a-z, 0-9, "_", "." and "-"');
        }
    }

    /**
     * Parameters are a JSON object. PHP reads an object whose keys are all digits as a list, and
     * a JSON array as a list too, so a non-empty list is refused. That means an object whose keys
     * are all digits cannot be used as params; key names are otherwise free.
     *
     * @param array<array-key, mixed> $params
     *
     * @throws InvalidArgumentException
     */
    public static function params(array $params): void
    {
        if ($params !== [] && array_is_list($params)) {
            throw new InvalidArgumentException('"params" must be an object with named keys, not a list');
        }
    }

    /**
     * What goes on the wire for an object that may be empty: an empty PHP array would become a
     * JSON list, so it is written as an empty object.
     *
     * @param array<array-key, mixed> $params
     *
     * @return array<array-key, mixed>|stdClass
     */
    public static function object(array $params): array|stdClass
    {
        return $params === [] ? new stdClass() : $params;
    }
}
