<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employe>
 */
class EmployeFactory extends Factory
{
    protected $model = Employe::class;

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
            'email' => fake()->unique()->safeEmail(),
            'nombre' => fake()->firstName(),
            'apellido_paterno' => fake()->lastName(),
            'apellido_materno' => fake()->lastName(),
            'curp' => strtoupper(fake()->unique()->bothify(str_repeat('?', 18))),
            'rfc' => strtoupper(fake()->unique()->bothify('???######??')),
            'calle' => fake()->streetName(),
            'colonia' => 'Centro',
            'no_exterior' => (string) fake()->numberBetween(1, 999),
            'codigo_postal' => fake()->numerify('#####'),
            'localidad' => 'Localidad',
            'municipio' => 'Municipio',
            'estado' => 'CDMX',
            'grupo' => 'Grupo 1',
            'sucursal' => 'Matriz',
            'salario_diario' => fake()->randomFloat(2, 200, 1000),
            'contrato' => 'Indeterminado',
            'regimen_contratacion' => '02',
            'tipo_jornada' => '01',
            'periodidad_pago' => '05',
            'departamento' => 'Ventas',
            'puesto' => 'Vendedor',
            'no_empleado' => strtoupper(fake()->unique()->bothify('EMP#####')),
            'seguro_social' => fake()->unique()->numerify('###########'),
        ];
    }
}
