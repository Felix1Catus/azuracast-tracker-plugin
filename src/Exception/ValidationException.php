<?php

declare(strict_types=1);

namespace Plugin\BranchTracker\Exception;

use InvalidArgumentException;

/** Bad input from an admin or the CLI; maps to HTTP 400 (or 409 when $conflict). */
final class ValidationException extends InvalidArgumentException
{
    public function __construct(string $message, public readonly bool $conflict = false)
    {
        parent::__construct($message);
    }
}
