<?php

namespace App\Exceptions\Billing;

use RuntimeException;
use Throwable;

class PacReconciliationRequiredException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(
            'El resultado de la emisión fiscal es ambiguo y requiere reconciliación.',
            previous: $previous,
        );
    }
}
