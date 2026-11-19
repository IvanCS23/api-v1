<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Company;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'client_id' => fn (array $attributes) => Client::factory()->create(['company_id' => $attributes['company_id']])->id,
            'folio' => 'PAG-'.str_pad((string) fake()->unique()->numberBetween(1, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 'draft',
            'payment_date' => now()->subDay(),
            'payment_form' => '03',
            'currency' => 'MXN',
            'exchange_rate' => 1,
            'amount' => 100,
        ];
    }

    public function issued(): static
    {
        return $this->state(fn () => [
            'status' => 'issued',
            'cfdi_uuid' => fake()->uuid(),
            'pac_external_id' => fake()->unique()->regexify('[a-f0-9]{24}'),
            'stamped_at' => now(),
        ]);
    }
}
