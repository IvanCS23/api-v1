<?php

namespace App\Enums;

enum CfdiCancellationMotive: string
{
    case ErrorsWithRelation = '01';
    case ErrorsWithoutRelation = '02';
    case OperationNotPerformed = '03';
    case GlobalInvoiceRelatedOperation = '04';

    /**
     * Fase 6.31: motivos que se pueden usar en cada ambiente.
     *
     * - 04 (operación en factura global) exige sustitución y el sistema no
     *   emite facturas globales: retirado en ambos ambientes.
     * - 01 exige emitir antes el CFDI sustituto, que el sistema aún no
     *   hace: sólo en Test.
     *
     * @return list<self>
     */
    public static function availableIn(?string $environment): array
    {
        return $environment === 'live'
            ? [self::ErrorsWithoutRelation, self::OperationNotPerformed]
            : [self::ErrorsWithRelation, self::ErrorsWithoutRelation, self::OperationNotPerformed];
    }

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
