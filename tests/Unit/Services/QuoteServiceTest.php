<?php

use App\Enums\QuoteStatus;
use App\Exceptions\QuoteNotEditableException;
use App\Models\Client;
use App\Models\Company;
use App\Models\Quote;
use App\Models\User;
use App\Services\Sales\QuoteService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// tests/Pest.php solo extiende TestCase en Feature: aquí se declara a mano.
uses(TestCase::class, RefreshDatabase::class);

/*
 * Sin propiedades en $this para que el analizador del editor (Intelephense)
 * resuelva los tipos: cada prueba crea su empresa y obtiene el servicio con
 * quoteServiceForCompany().
 */
function quoteServiceForCompany(Company $company): QuoteService
{
    app(CurrentTenant::class)->set($company->id);

    return app(QuoteService::class);
}

afterEach(function () {
    app(CurrentTenant::class)->clear();
});

test('create deja la cotización en Draft con folio y created_by asignados', function () {
    $company = Company::factory()->create();
    $service = quoteServiceForCompany($company);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $author = User::factory()->create(['company_id' => $company->id]);

    $quote = $service->create(['client_id' => $client->id, 'notes' => 'Prueba'], $author);

    expect($quote->status)->toBe(QuoteStatus::Draft)
        ->and($quote->folio)->toBe('COT-00000001')
        ->and($quote->created_by)->toBe($author->id)
        ->and($quote->company_id)->toBe($company->id)
        ->and($quote->relationLoaded('items'))->toBeTrue();
});

test('create ignora status y folio que vengan en los datos', function () {
    $company = Company::factory()->create();
    $service = quoteServiceForCompany($company);
    $client = Client::factory()->create(['company_id' => $company->id]);
    $author = User::factory()->create(['company_id' => $company->id]);

    $quote = $service->create([
        'client_id' => $client->id,
        'status' => QuoteStatus::Approved,
        'folio' => 'COT-99999999',
    ], $author);

    expect($quote->status)->toBe(QuoteStatus::Draft)
        ->and($quote->folio)->toBe('COT-00000001');
});

test('update edita una cotización en Draft o Sent', function (QuoteStatus $status) {
    $company = Company::factory()->create();
    $service = quoteServiceForCompany($company);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'status' => $status]);

    $updated = $service->update($quote, ['notes' => 'Editada']);

    expect($updated->notes)->toBe('Editada')
        ->and($quote->fresh()->notes)->toBe('Editada');
})->with([
    'draft' => [QuoteStatus::Draft],
    'sent' => [QuoteStatus::Sent],
]);

test('update en Approved lanza QuoteNotEditableException y no guarda', function () {
    $company = Company::factory()->create();
    $service = quoteServiceForCompany($company);
    $quote = Quote::factory()->create([
        'company_id' => $company->id,
        'status' => QuoteStatus::Approved,
        'notes' => 'Original',
    ]);

    expect(fn () => $service->update($quote, ['notes' => 'Editada']))
        ->toThrow(QuoteNotEditableException::class, 'Esta cotización ya no se puede editar en su estado actual.');

    expect($quote->fresh()->notes)->toBe('Original');
});

test('get con un id inexistente lanza ModelNotFoundException', function () {
    $service = quoteServiceForCompany(Company::factory()->create());

    $service->get(999999);
})->throws(ModelNotFoundException::class);

test('get no devuelve cotizaciones de otra empresa', function () {
    $service = quoteServiceForCompany(Company::factory()->create());
    $other = Quote::factory()->create(['company_id' => Company::factory()->create()->id]);

    $service->get($other->id);
})->throws(ModelNotFoundException::class);

test('all devuelve las cotizaciones de la empresa ordenadas por latest', function () {
    $company = Company::factory()->create();
    $service = quoteServiceForCompany($company);
    $old = Quote::factory()->create(['company_id' => $company->id, 'created_at' => now()->subDays(2)]);
    $new = Quote::factory()->create(['company_id' => $company->id, 'created_at' => now()]);
    $mid = Quote::factory()->create(['company_id' => $company->id, 'created_at' => now()->subDay()]);
    Quote::factory()->create(['company_id' => Company::factory()->create()->id]);

    expect($service->all()->pluck('id')->all())->toBe([$new->id, $mid->id, $old->id]);
});

test('paginated respeta page y perPage', function () {
    $company = Company::factory()->create();
    $service = quoteServiceForCompany($company);
    foreach (range(1, 5) as $i) {
        Quote::factory()->create(['company_id' => $company->id, 'created_at' => now()->subMinutes($i)]);
    }

    $page = $service->paginated(page: 2, perPage: 2);

    expect($page->currentPage())->toBe(2)
        ->and($page->perPage())->toBe(2)
        ->and($page->total())->toBe(5)
        ->and($page->items())->toHaveCount(2);
});

test('paginated limita perPage a 100 y normaliza valores inválidos', function () {
    $service = quoteServiceForCompany(Company::factory()->create());

    expect($service->paginated(perPage: 500)->perPage())->toBe(100)
        ->and($service->paginated(perPage: 0)->perPage())->toBe(1)
        ->and($service->paginated(page: 0)->currentPage())->toBe(1)
        ->and($service->paginated()->perPage())->toBe(QuoteService::DEFAULT_PER_PAGE);
});
