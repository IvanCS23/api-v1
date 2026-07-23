<?php

namespace Database\Seeders;

use App\Enums\TaxFactorType;
use App\Enums\TaxType;
use App\Models\TaxRate;
use Illuminate\Database\Seeder;

/**
 * Catálogo mínimo de tasas SAT más comunes en México. No calcula
 * impuestos de ventas/facturas todavía. Idempotente: updateOrCreate por
 * (code, name) — evita duplicar si se corre más de una vez.
 */
class TaxRateSeeder extends Seeder
{
    public function run(): void
    {
        $rates = [
            ['code' => '002', 'name' => 'IVA 16%', 'rate' => 0.160000, 'tax_type' => TaxType::Traslado, 'factor_type' => TaxFactorType::Tasa],
            ['code' => '002', 'name' => 'IVA 0%', 'rate' => 0.000000, 'tax_type' => TaxType::Traslado, 'factor_type' => TaxFactorType::Tasa],
            ['code' => '002', 'name' => 'IVA Exento', 'rate' => 0.000000, 'tax_type' => TaxType::Traslado, 'factor_type' => TaxFactorType::Exento],
            ['code' => '001', 'name' => 'ISR Retenido 10%', 'rate' => 0.100000, 'tax_type' => TaxType::Retencion, 'factor_type' => TaxFactorType::Tasa],
        ];

        foreach ($rates as $rate) {
            TaxRate::query()->updateOrCreate(
                ['code' => $rate['code'], 'name' => $rate['name']],
                [
                    'rate' => $rate['rate'],
                    'tax_type' => $rate['tax_type'],
                    'factor_type' => $rate['factor_type'],
                    'active' => true,
                ],
            );
        }
    }
}
