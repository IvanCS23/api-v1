<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * Define the model's default state.
     *
     * Ver ClientFactory para el razonamiento sobre company_id + factories.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->words(2, true),
            'descripcion' => fake()->sentence(),
            'precio_unitario' => fake()->randomFloat(2, 10, 1000),
            'clave_producto' => strtoupper(fake()->unique()->bothify('########')),
            // '02' = Sí objeto de impuesto (catálogo SAT c_ObjetoImp) — un
            // producto "completo" por defecto debe poder facturarse; ver
            // SaleBillingReadinessService (auditoría Fase 5 — cierre, ahora
            // bloqueante si falta).
            'objeto_imp' => '02',
            'iva' => 16,
        ];
    }
}
