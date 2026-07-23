<?php

use App\Models\Company;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Auditoría: JsonResource::withoutWrapping() — impacto real
|--------------------------------------------------------------------------
|
| Resources encontrados en app/Http/Resources/ (grep completo): CompanyResource,
| UserResource, ClientResource, ProductResource, EmployeResource. Ninguno más.
|
| CompanyResource y UserResource NUNCA se devuelven directamente desde un
| controller — siempre se instancian a mano dentro de un array propio
| (`response()->json(['user' => new UserResource($user)])` en
| AuthController/UserController, o `['data' => UserResource::collection(...),
| 'meta' => [...]]` en UserController::index). withoutWrapping() solo afecta
| el mecanismo automático de Illuminate\Http\Resources\Json\ResourceResponse,
| que se activa cuando Laravel llama a ->toResponse() sobre un Resource/
| ResourceCollection devuelto DIRECTAMENTE como valor de retorno de una ruta.
| Como CompanyResource/UserResource jamás se retornan así (siempre van
| envueltos en un array plano pasado a response()->json()), el toggle no
| tiene ningún efecto sobre ellos — se serializan vía jsonSerialize()/
| toArray() directo, sin pasar por la lógica de wrap().
|
| Los únicos Resources afectados por el toggle son los 3 nuevos de Etapa D
| (Client/Product/Employe), que sí se retornan directamente desde los
| controllers. Este test prueba ambas afirmaciones con peticiones reales.
*/

test('withoutWrapping no afecta el contrato de /api/login ni /api/user (UserResource nunca se retorna directo)', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'email' => 'wrap-audit@example.com']);

    $login = $this->postJson('/api/login', [
        'email' => 'wrap-audit@example.com',
        'password' => 'password',
    ]);

    $login->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'company']]);
    // Prueba negativa explícita: NO debe existir envoltura {"data": {...}}.
    expect($login->json('data'))->toBeNull();

    $token = $login->json('token');

    $me = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/user');
    $me->assertOk()->assertJsonStructure(['user' => ['id', 'email']]);
    expect($me->json('data'))->toBeNull();
});

test('ClientResource sí queda sin envoltura al devolverse directo desde el controller (comportamiento esperado)', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user, 'api')->postJson('/api/clients', [
        'name' => 'Cliente sin wrapping',
        'email' => 'sin-wrapping@example.com',
        'rfc' => 'SWR010101AAA',
        'codigo_postal' => '12345',
        'regimen_fiscal' => '601',
        'uso_cfdi' => 'G03',
    ]);

    $response->assertCreated();
    // El propio id debe estar en el nivel superior, no bajo "data".
    expect($response->json('id'))->not->toBeNull()
        ->and($response->json('data'))->toBeNull();
});
