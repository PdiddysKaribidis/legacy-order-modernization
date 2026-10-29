<?php

declare(strict_types=1);

namespace App\Order;

use DomainException;

final class ValidationException extends DomainException
{
    /**
     * @param array<string, string> $errors field name => message
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
