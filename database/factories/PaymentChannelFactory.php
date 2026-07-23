<?php

namespace Database\Factories;

use App\Models\PaymentChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentChannel>
 */
class PaymentChannelFactory extends Factory
{
    protected $model = PaymentChannel::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('CHANNEL_####')),
            'name' => fake()->words(2, true),
            'requires_bank' => false,
            'active' => true,
        ];
    }
}
