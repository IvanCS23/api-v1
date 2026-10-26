<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    /**
     * Define the model's default state.
     *
     * company_id no está en $fillable de Client a propósito (ver
     * BelongsToCompany), pero las factories bypasean el guard de mass
     * assignment (Model::unguarded()), así que Company::factory() aquí
     * funciona igual que en UserFactory, y un test puede sobreescribirlo
     * con ->create(['company_id' => $otraEmpresa->id]) cuando necesite
     * simular dos empresas distintas.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->company(),
            'email' => fake()->unique()->safeEmail(),
            // Fase 6.26: RFC de persona moral válido (3 letras, fecha AAMMDD,
            // homoclave), coherente con el régimen 601 y el uso G03.
            'rfc' => strtoupper(fake()->unique()->lexify('???').fake()->date('ymd').fake()->bothify('??#')),
            'codigo_postal' => fake()->numerify('#####'),
            'regimen_fiscal' => '601',
            'uso_cfdi' => 'G03',
            'calle' => fake()->streetName(),
            'estado' => 'CDMX',
            'pais' => 'México',
        ];
    }
}
