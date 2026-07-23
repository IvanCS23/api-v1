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
            'rfc' => strtoupper(fake()->unique()->bothify('???######??')),
            'codigo_postal' => fake()->numerify('#####'),
            'regimen_fiscal' => '601',
            'uso_cfdi' => 'G03',
            'calle' => fake()->streetName(),
            'estado' => 'CDMX',
            'pais' => 'México',
        ];
    }
}
