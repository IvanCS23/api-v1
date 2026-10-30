<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Billing\InvoiceFolio;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6.28: la consulta de facturas encuentra una factura por su folio
 * CFDI escrito como "19", "FAC-19", "FAC 19" o "FAC19", además del folio
 * interno; y la API expone el folio CFDI y si coincide con el del ERP.
 */
/** @return array{Company, User} */
function phase628SearchUser(): array
{
    $company = Company::factory()->create();

    return [$company, User::factory()->create(['company_id' => $company->id])];
}

function phase628InvoiceWithCfdi(Company $company, string $folio, ?string $series, ?int $number): Invoice
{
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'folio' => $folio]);
    DB::table('invoices')->where('id', $invoice->id)->update(['cfdi_series' => $series, 'cfdi_folio_number' => $number]);

    return $invoice;
}

/** @return list<string> */
function phase628SearchFolios(User $user, string $search): array
{
    return collect(test()->actingAs($user, 'api')->getJson('/api/invoices?search='.urlencode($search))->assertOk()->json('data'))
        ->pluck('folio')
        ->sort()
        ->values()
        ->all();
}

test('InvoiceFolio::parseSearch entiende las formas escritas de un folio CFDI', function (string $search, ?array $expected) {
    expect(InvoiceFolio::parseSearch($search))->toBe($expected);
})->with([
    'sólo número' => ['19', ['series' => null, 'number' => 19]],
    'con guion' => ['FAC-19', ['series' => 'FAC', 'number' => 19]],
    'con espacio' => ['FAC 19', ['series' => 'FAC', 'number' => 19]],
    'pegado' => ['FAC19', ['series' => 'FAC', 'number' => 19]],
    'minúsculas y ceros' => ['fac-0019', ['series' => 'FAC', 'number' => 19]],
    'espacios alrededor' => ['  F-10 ', ['series' => 'F', 'number' => 10]],
    'RFC' => ['AAA010101AAA', null],
    'texto' => ['Aurora', null],
    'cero' => ['FAC-0', null],
    'folio interno completo' => ['FAC-00000019', ['series' => 'FAC', 'number' => 19]],
]);

test('encuentra la factura por su folio CFDI timbrado en las cuatro formas', function (string $search) {
    [$company, $user] = phase628SearchUser();
    // El folio interno no contiene "19": sólo el folio CFDI puede encontrarla.
    phase628InvoiceWithCfdi($company, 'FAC-00000500', 'FAC', 19);
    phase628InvoiceWithCfdi($company, 'FAC-00000501', 'FAC', 20);

    expect(phase628SearchFolios($user, $search))->toBe(['FAC-00000500']);
})->with(['FAC-19', 'FAC 19', 'FAC19', 'fac-19']);

test('sólo el número encuentra el folio CFDI de cualquier serie y el folio interno que lo contiene', function () {
    [$company, $user] = phase628SearchUser();
    phase628InvoiceWithCfdi($company, 'FAC-00000500', 'FAC', 19);
    phase628InvoiceWithCfdi($company, 'FAC-00000501', 'F', 19);
    phase628InvoiceWithCfdi($company, 'FAC-00000019', 'F', 10);
    phase628InvoiceWithCfdi($company, 'FAC-00000502', 'FAC', 502);

    expect(phase628SearchFolios($user, '19'))->toBe(['FAC-00000019', 'FAC-00000500', 'FAC-00000501']);
});

test('la serie se respeta: "F-10" encuentra la factura anterior a la 6.28 y no otras', function () {
    [$company, $user] = phase628SearchUser();
    phase628InvoiceWithCfdi($company, 'FAC-00000019', 'F', 10);
    phase628InvoiceWithCfdi($company, 'FAC-00000010', 'FAC', 10);

    expect(phase628SearchFolios($user, 'F-10'))->toBe(['FAC-00000019'])
        ->and(phase628SearchFolios($user, 'FAC 10'))->toBe(['FAC-00000010']);
});

test('una factura aún sin timbrar se encuentra por el folio CFDI que tendrá', function () {
    [$company, $user] = phase628SearchUser();
    phase628InvoiceWithCfdi($company, 'FAC-00000021', null, null);

    expect(phase628SearchFolios($user, 'FAC 21'))->toBe(['FAC-00000021'])
        ->and(phase628SearchFolios($user, 'FAC21'))->toBe(['FAC-00000021']);
});

test('el folio interno se sigue encontrando como antes', function () {
    [$company, $user] = phase628SearchUser();
    phase628InvoiceWithCfdi($company, 'FAC-00000019', 'F', 10);

    expect(phase628SearchFolios($user, 'FAC-00000019'))->toBe(['FAC-00000019'])
        ->and(phase628SearchFolios($user, '00019'))->toBe(['FAC-00000019']);
});

test('la búsqueda por folio CFDI nunca cruza empresas', function () {
    [$company, $user] = phase628SearchUser();
    [$other] = phase628SearchUser();
    phase628InvoiceWithCfdi($other, 'FAC-00000500', 'FAC', 19);

    expect(phase628SearchFolios($user, 'FAC-19'))->toBe([]);
});

test('la API expone el folio CFDI y si coincide con el del ERP', function () {
    [$company, $user] = phase628SearchUser();
    $legacy = phase628InvoiceWithCfdi($company, 'FAC-00000019', 'F', 10);
    $current = phase628InvoiceWithCfdi($company, 'FAC-00000020', 'FAC', 20);
    $pending = phase628InvoiceWithCfdi($company, 'FAC-00000021', null, null);

    $this->actingAs($user, 'api')->getJson("/api/invoices/{$legacy->id}")
        ->assertOk()
        ->assertJsonPath('cfdi_folio', 'F10')
        ->assertJsonPath('cfdi_folio_matches_erp', false);

    $this->actingAs($user, 'api')->getJson("/api/invoices/{$current->id}/billing")
        ->assertOk()
        ->assertJsonPath('cfdi.series', 'FAC')
        ->assertJsonPath('cfdi.folio_number', 20)
        ->assertJsonPath('cfdi.folio', 'FAC20')
        ->assertJsonPath('cfdi.folio_matches_erp', true);

    $this->actingAs($user, 'api')->getJson("/api/invoices/{$pending->id}/billing")
        ->assertOk()
        ->assertJsonPath('cfdi.folio', null)
        ->assertJsonPath('cfdi.folio_matches_erp', null);
});
