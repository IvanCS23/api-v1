<?php

namespace App\Enums;

enum CfdiCancellationMotive: string
{
    case ErrorsWithRelation = '01';
    case ErrorsWithoutRelation = '02';
    case OperationNotPerformed = '03';
    case GlobalInvoiceRelatedOperation = '04';

    public function label(): string
    {
        return match ($this) {
            self::ErrorsWithRelation => 'Comprobante emitido con errores con relación',
            self::ErrorsWithoutRelation => 'Comprobante emitido con errores sin relación',
            self::OperationNotPerformed => 'No se llevó a cabo la operación',
            self::GlobalInvoiceRelatedOperation => 'Operación nominativa relacionada en factura global',
        };
    }
}
