<?php

use App\Enums\InvoiceStatus;
use App\Exceptions\Billing\PacFolioAlreadyUsedException;
use App\Exceptions\Billing\PacUnavailableException;
use App\Exceptions\Billing\PacUnexpectedResponseException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Billing\CreatePacDraftInvoiceService;
use App\Services\Billing\UpdatePacDraftInvoiceService;
use App\Support\Billing\InvoicePacActionExceptionResponder;
use App\Support\Billing\PacIdentifiers;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.28: antes de mandar la serie y el folio en un borrador se consulta
 * GET /invoices?series=…&folio_number=…; un comprobante ajeno a la factura
 * con el mismo folio detiene todo sin escribir nada.
 */
beforeEach(function () {
    facturapiOrganizationsFromConfigKey();
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PHASE_628_DUPLICATE',
        'billing.cfdi_folio_check' => true,
    ]);
    Http::preventStrayRequests();
});

function phase628IssuedInvoice(string $folio = 'FAC-00000019', array $overrides = []): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(array_merge(['company_id' => $company->id, 'folio' => $folio, 'status' => InvoiceStatus::Issued], $overrides));
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);
    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh(['items']);
}

/**
 * PAC simulado: la búsqueda por folio responde $matches; el resto, un
 * borrador listo.
 *
 * @param  list<array<string, mixed>>|int  $matches  coincidencias, o un HTTP status de error
 */
function phase628FakePac(Invoice $invoice, array|int $matches): void
{
    $draftId = 'inv_dup_'.$invoice->id;

    Http::fake(function (Request $request) use ($matches, $draftId) {
        if ($request->method() === 'GET' && str_contains($request->url(), 'folio_number=')) {
            return is_int($matches)
                ? Http::response(['message' => 'falla'], $matches)
                : Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => count($matches), 'data' => $matches]);
        }

        return Http::response(['id' => $draftId, 'status' => 'draft', 'livemode' => false, 'is_ready_to_stamp' => true]);
    });
}

function phase628FolioQueries(): int
{
    return collect(Http::recorded())->filter(fn (array $pair) => str_contains($pair[0]->url(), 'folio_number='))->count();
}

function phase628Writes(): int
{
    return collect(Http::recorded())->filter(fn (array $pair) => in_array($pair[0]->method(), ['POST', 'PUT'], true))->count();
}

test('el provider consulta por serie y folio exactos y normaliza las coincidencias', function () {
    $invoice = phase628IssuedInvoice();
    phase628FakePac($invoice, [
        ['id' => 'inv_a', 'external_id' => 'otro-sistema-1', 'status' => 'valid'],
        ['id' => 'inv_b', 'status' => 'draft'],
    ]);

    $found = facturapiProviderFromConfig()->findInvoicesBySeriesFolio('FAC', 19);

    expect($found)->toBe([
        ['id' => 'inv_a', 'external_id' => 'otro-sistema-1', 'status' => 'valid'],
        ['id' => 'inv_b', 'external_id' => null, 'status' => 'draft'],
    ]);
    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && $request['series'] === 'FAC'
        && (int) $request['folio_number'] === 19);
});

test('una respuesta de búsqueda sin data es inesperada', function () {
    Http::fake(['*' => Http::response(['page' => 1])]);

    expect(fn () => facturapiProviderFromConfig()->findInvoicesBySeriesFolio('FAC', 19))
        ->toThrow(PacUnexpectedResponseException::class);
});

test('sin coincidencias, el borrador se crea normalmente', function () {
    $invoice = phase628IssuedInvoice();
    phase628FakePac($invoice, []);

    $updated = app(CreatePacDraftInvoiceService::class)->createOrSync($invoice);

    expect($updated->pac_draft_external_id)->toBe('inv_dup_'.$invoice->id)
        ->and(phase628FolioQueries())->toBe(1)
        ->and(phase628Writes())->toBe(1);
});

test('las coincidencias de esta misma factura (borrador o emisión directa) no cuentan', function () {
    $invoice = phase628IssuedInvoice();
    phase628FakePac($invoice, [
        ['id' => 'inv_own_draft', 'external_id' => PacIdentifiers::draftExternalId($invoice->company_id, $invoice->id), 'status' => 'draft'],
        ['id' => 'inv_own', 'external_id' => PacIdentifiers::externalId($invoice->company_id, $invoice->id), 'status' => 'canceled'],
    ]);

    $updated = app(CreatePacDraftInvoiceService::class)->createOrSync($invoice);

    expect($updated->pac_draft_external_id)->not->toBeNull();
});

test('un comprobante ajeno con el mismo folio detiene la creación sin escribir nada', function (array $match) {
    $invoice = phase628IssuedInvoice();
    phase628FakePac($invoice, [$match]);

    expect(fn () => app(CreatePacDraftInvoiceService::class)->createOrSync($invoice))
        ->toThrow(PacFolioAlreadyUsedException::class, 'FAC-19');

    $fresh = $invoice->fresh();
    expect(phase628Writes())->toBe(0)
        ->and($fresh->pac_draft_idempotency_key)->toBeNull()
        ->and($fresh->pac_draft_external_id)->toBeNull()
        ->and($fresh->pac_provider)->toBeNull();
})->with([
    'timbrado por fuera' => [['id' => 'inv_x', 'external_id' => null, 'status' => 'valid']],
    'cancelado de otro sistema' => [['id' => 'inv_y', 'external_id' => 'otro-sistema', 'status' => 'canceled']],
    'borrador de otra factura' => [['id' => 'inv_z', 'external_id' => 'company-1-invoice-999-draft', 'status' => 'draft']],
]);

test('si la consulta falla, no se crea el borrador ni se reserva la llave', function () {
    $invoice = phase628IssuedInvoice();
    phase628FakePac($invoice, 503);

    expect(fn () => app(CreatePacDraftInvoiceService::class)->createOrSync($invoice))
        ->toThrow(PacUnavailableException::class);

    expect(phase628Writes())->toBe(0)
        ->and($invoice->fresh()->pac_draft_idempotency_key)->toBeNull();
});

test('la actualización del borrador también consulta y no hace el PUT ante un duplicado', function () {
    $invoice = phase628IssuedInvoice();
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_draft_external_id' => 'inv_dup_'.$invoice->id,
        'pac_draft_idempotency_key' => PacIdentifiers::draftIdempotencyKey($invoice->company_id, $invoice->id),
        'pac_draft_status' => 'draft',
    ])->save();
    phase628FakePac($invoice, [['id' => 'inv_x', 'external_id' => null, 'status' => 'valid']]);

    expect(fn () => app(UpdatePacDraftInvoiceService::class)->update($invoice->fresh()))
        ->toThrow(PacFolioAlreadyUsedException::class);

    expect(collect(Http::recorded())->filter(fn (array $pair) => $pair[0]->method() === 'PUT'))->toHaveCount(0);
});

test('con billing.cfdi_folio_check apagado no se consulta', function () {
    config(['billing.cfdi_folio_check' => false]);
    $invoice = phase628IssuedInvoice();
    phase628FakePac($invoice, [['id' => 'inv_x', 'external_id' => null, 'status' => 'valid']]);

    app(CreatePacDraftInvoiceService::class)->createOrSync($invoice);

    expect(phase628FolioQueries())->toBe(0);
});

test('la API responde 409 PAC_FOLIO_ALREADY_USED con el folio y sin detalles internos', function () {
    $response = app(InvoicePacActionExceptionResponder::class)->respond(new PacFolioAlreadyUsedException(7, 'FAC-19', 1));

    expect($response->status())->toBe(409)
        ->and($response->getData(true)['code'])->toBe('PAC_FOLIO_ALREADY_USED')
        ->and($response->getData(true)['message'])->toContain('FAC-19')
        ->and($response->getData(true)['message'])->not->toContain('[7]');
});
