<?php

namespace App\Support\Billing;

use App\Enums\InvoiceHealthStatus;

/**
 * Resultado de evaluar una Invoice: el estado global es el peor de sus
 * hallazgos, o Valid cuando no hay ninguno.
 */
final class InvoiceHealthReport
{
    /** @param list<InvoiceHealthFinding> $findings */
    public function __construct(
        public readonly int $invoiceId,
        public readonly int $companyId,
        public readonly InvoiceHealthStatus $status,
        public readonly array $findings,
    ) {}

    /** @param list<InvoiceHealthFinding> $findings */
    public static function fromFindings(int $invoiceId, int $companyId, array $findings): self
    {
        $status = InvoiceHealthStatus::Valid;

        foreach ($findings as $finding) {
            $status = InvoiceHealthStatus::worst($status, $finding->status);
        }

        return new self($invoiceId, $companyId, $status, $findings);
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_map(fn (InvoiceHealthFinding $finding): string => $finding->code, $this->findings);
    }

    public function find(string $code): ?InvoiceHealthFinding
    {
        foreach ($this->findings as $finding) {
            if ($finding->code === $code) {
                return $finding;
            }
        }

        return null;
    }
}
