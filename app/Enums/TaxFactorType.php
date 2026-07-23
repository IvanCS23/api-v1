<?php

namespace App\Enums;

/**
 * Equivalente al catálogo `c_TipoFactor` del SAT. VARCHAR + enum de PHP,
 * no ENUM nativo de MySQL (mismo razonamiento que ProductType).
 */
enum TaxFactorType: string
{
    case Tasa = 'tasa';
    case Cuota = 'cuota';
    case Exento = 'exento';
}
