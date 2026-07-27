<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    protected $model = InvoiceItem::class;

    /**
     * `invoice_id`/`product_id` se resuelven en función del `company_id`
     * ya definido, mismo razonamiento que SaleItemFactory.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'invoice_id' => fn (array $attributes) => Invoice::factory()->create(['company_id' => $attributes['company_id']])->id,
            'product_id' => fn (array $attributes) => Product::factory()->create(['company_id' => $attributes['company_id']])->id,
            'description' => fake()->words(3, true),
            'quantity' => 1,
            'unit_price' => 100,
            'discount' => 0,
            'subtotal' => 100,
            'tax_total' => 0,
            'total' => 100,
            'product_clave_producto' => strtoupper(fake()->unique()->bothify('########')),
            'product_type' => 'product',
        ];
    }
}
