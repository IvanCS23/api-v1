<?php

namespace App\Exceptions\Company;

use RuntimeException;

/**
 * Fase 6.27b — Rechazo de un cambio de datos fiscales o de CSD de la
 * empresa. Lleva el código, el estado HTTP y, si aplica, los problemas por
 * campo. El mensaje nunca incluye llaves, contraseñas ni el cuerpo crudo
 * de Facturapi.
 */
class CompanyFiscalException extends RuntimeException
{
    /**
     * @param  list<array{code: string, field: string, message: string}>  $issues
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $issues = [],
    ) {
        parent::__construct($message);
    }

    /** @return array{code: string, message: string, issues?: list<array{code: string, field: string, message: string}>} */
    public function toResponseArray(): array
    {
        return array_filter([
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'issues' => $this->issues,
        ], fn (mixed $value): bool => $value !== []);
    }
}
