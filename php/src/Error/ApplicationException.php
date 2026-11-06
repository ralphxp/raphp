<?php

declare(strict_types=1);

namespace Raphp\Error;

use InvalidArgumentException;
use Throwable;

/**
 * An error defined by the application, not the framework (FRAMEWORK 6.6).
 *
 * Its code starts with `E_APP_` followed by capital letters, digits and underscores, and its
 * `data` is free-form (anything JSON can carry).
 */
final class ApplicationException extends RpcException
{
    private const string CODE_PATTERN = '/^E_APP_[A-Z0-9_]+\z/';

    /**
     * @throws InvalidArgumentException when the code is not a well-formed `E_APP_*` code
     */
    public function __construct(private readonly string $applicationCode, string $message = '', mixed $data = null, ?Throwable $previous = null)
    {
        if (!self::isValidCode($applicationCode)) {
            throw new InvalidArgumentException('an application error code must match E_APP_ followed by capital letters, digits and underscores');
        }

        parent::__construct($message, $data, $previous);
    }

    public static function isValidCode(string $code): bool
    {
        return preg_match(self::CODE_PATTERN, $code) === 1;
    }

    public function wireCode(): string
    {
        return $this->applicationCode;
    }
}
