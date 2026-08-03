<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * `sale_id`/`client_id` se resuelven en función del `company_id` ya
     * definido, mismo razonamiento que SaleFactory/QuoteFactory. Los
     * campos `client_*` simulan un snapshot fiscal ya completo por
     * defecto (igual que ClientFactory).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'sale_id' => fn (array $attributes) => Sale::factory()->create(['company_id' => $attributes['company_id']])->id,
            'client_id' => fn (array $attributes) => Client::factory()->create(['company_id' => $attributes['company_id']])->id,
            'created_by' => null,
            'folio' => 'FAC-'.str_pad((string) fake()->unique()->numberBetween(1, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => InvoiceStatus::Draft,
            'subtotal' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
            'total' => 0,
            'currency' => 'MXN',
            'client_name' => fake()->company(),
            'client_rfc' => strtoupper(fake()->unique()->bothify('???######??')),
            'client_regimen_fiscal' => '601',
            'client_uso_cfdi' => 'G03',
            'client_codigo_postal' => fake()->numerify('#####'),
            'client_estado' => 'CDMX',
            'client_pais' => 'México',
            'payment_form' => '03',
            'payment_method' => 'PUE',
        ];
    }
}
