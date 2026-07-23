<?php

namespace Database\Seeders;

use App\Models\PaymentChannel;
use Illuminate\Database\Seeder;

/**
 * Catálogo mínimo de canales internos de cobro (NO el catálogo fiscal
 * "método de pago" PUE|PPD ni "forma de pago" c_FormaPago del SAT — esos
 * todavía no existen, se crearán junto con la integración a Facturapi en
 * Fase 3). Idempotente: puede correr varias veces sin duplicar filas
 * (updateOrCreate por `code`).
 */
class PaymentChannelSeeder extends Seeder
{
    public function run(): void
    {
        $channels = [
            ['code' => 'CASH', 'name' => 'Efectivo', 'requires_bank' => false],
            ['code' => 'BANK_TRANSFER', 'name' => 'Transferencia bancaria', 'requires_bank' => true],
            ['code' => 'CREDIT_CARD', 'name' => 'Tarjeta de crédito', 'requires_bank' => false],
            ['code' => 'DEBIT_CARD', 'name' => 'Tarjeta de débito', 'requires_bank' => false],
            ['code' => 'CHECK', 'name' => 'Cheque', 'requires_bank' => true],
        ];

        foreach ($channels as $channel) {
            PaymentChannel::query()->updateOrCreate(
                ['code' => $channel['code']],
                ['name' => $channel['name'], 'requires_bank' => $channel['requires_bank'], 'active' => true],
            );
        }
    }
}
