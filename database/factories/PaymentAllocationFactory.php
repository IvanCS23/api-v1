<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentAllocation>
 */
class PaymentAllocationFactory extends Factory
{
    protected $model = PaymentAllocation::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'payment_id' => fn (array $attributes) => Payment::factory()->create(['company_id' => $attributes['company_id']])->id,
            'invoice_id' => fn (array $attributes) => Invoice::factory()->create(['company_id' => $attributes['company_id']])->id,
            'amount' => 100,
        ];
    }
}
