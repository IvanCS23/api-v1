<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\FacturapiOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * Define the model's default state.
     *
     * Solo se fijan los campos NOT NULL sin default a nivel de columna
     * (legal_name, trade_name, rfc, fiscal_regime). El resto (status,
     * language, currency, theme, fiscal_provider, etc.) queda fuera a
     * propósito para que tomen el default real definido en la migración
     * de companies. `uuid` no se fija aquí: lo autogenera el hook
     * `Company::booted()` al crear el modelo.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Fase 6.27b: datos de emisor válidos (InvoicePacReadinessService los
        // exige antes de emitir): RFC de persona moral con fecha real, razón
        // social sin régimen societario y C.P.
        return [
            'legal_name' => 'EMPRESA '.strtoupper(fake()->lexify('????????')),
            'trade_name' => fake()->company(),
            'rfc' => strtoupper(fake()->unique()->lexify('???')).fake()->date('ymd').strtoupper(fake()->bothify('?#?')),
            'fiscal_regime' => '601',
            'postal_code' => '06010',
        ];
    }

    /** Fase 6.27: empresa con su organización de Facturapi y llave Test. */
    public function withFacturapiOrganization(?string $testKey = 'sk_test_FACTORY_ORGANIZATION_KEY', ?string $organizationId = null): static
    {
        return $this->afterCreating(function (Company $company) use ($testKey, $organizationId): void {
            FacturapiOrganization::factory()
                ->testKey($testKey)
                ->create(array_filter([
                    'company_id' => $company->id,
                    'organization_id' => $organizationId,
                ], fn (mixed $value): bool => $value !== null));
        });
    }
}
