<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\FacturapiOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FacturapiOrganization>
 */
class FacturapiOrganizationFactory extends Factory
{
    protected $model = FacturapiOrganization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'organization_id' => fake()->unique()->regexify('[0-9a-f]{24}'),
        ];
    }

    /** Llave Test (cifrada por el cast) y su pista, como al vincular. */
    public function testKey(?string $key = 'sk_test_FACTORY_ORGANIZATION_KEY'): static
    {
        return $this->afterMaking(fn (FacturapiOrganization $organization) => $organization->setTestSecretKey($key));
    }
}
