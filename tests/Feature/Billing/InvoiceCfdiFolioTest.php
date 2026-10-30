<?php

use App\Data\Billing\PacInvoiceRequest;
use App\Exceptions\Billing\InvoiceFiscalSnapshotIncompleteException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Billing\InvoiceNumberGenerator;
use App\Services\Billing\InvoicePacReadinessService;
use App\Support\Billing\InvoiceFolio;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.28: el CFDI lleva la serie y el folio del ERP (FAC-00000019 →
 * Serie "FAC", Folio 19) en el borrador y en su actualización.
 */
beforeEach(function () {
    facturapiOrganizationsFromConfigKey();
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_FOLIO_628',
    ]);
    Http::preventStrayRequests();
});

function phase628Invoice(array $overrides = []): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(array_merge(['company_id' => $company->id], $overrides));
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);
    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh(['items']);
}

function phase628DraftRequest(Invoice $invoice): PacInvoiceRequest
{
    return new PacInvoiceRequest(
        invoice: $invoice,
        idempotencyKey: "erp-invoice-draft:{$invoice->company_id}:{$invoice->id}:v1",
        externalId: "company-{$invoice->company_id}-invoice-{$invoice->id}-draft",
    );
}

/** @return array<string, mixed> */
function phase628DraftBody(): array
{
    return ['id' => 'inv_draft_628', 'status' => 'draft', 'livemode' => false, 'is_ready_to_stamp' => true];
}

test('InvoiceFolio separa la serie y el número del folio interno', function (string $folio, string $series, int $number, string $label) {
    $parsed = InvoiceFolio::parse($folio);

    expect($parsed->series)->toBe($series)
        ->and($parsed->number)->toBe($number)
        ->and($parsed->cfdiLabel())->toBe($label);
})->with([
    'folio normal' => ['FAC-00000019', 'FAC', 19, 'FAC-19'],
    'primer folio' => ['FAC-00000001', 'FAC', 1, 'FAC-1'],
    'más de 8 dígitos' => ['FAC-123456789', 'FAC', 123456789, 'FAC-123456789'],
]);

test('InvoiceFolio rechaza folios de los que no sale un CFDI', function (?string $folio) {
    expect(InvoiceFolio::parse($folio))->toBeNull();
})->with([
    'nulo' => [null],
    'vacío' => [''],
    'cero' => ['FAC-00000000'],
    'otro prefijo' => ['FAT-00000019'],
    'sin relleno' => ['FAC-19'],
    'texto' => ['FAC-CANCEL-65'],
    'minúsculas' => ['fac-00000019'],
    'excede un entero de 32 bits' => ['FAC-2147483648'],
    'espacios' => [' FAC-00000019'],
]);

test('InvoiceFolio::label arma la forma visible y tolera datos parciales', function () {
    expect(InvoiceFolio::label('F', 10))->toBe('F-10')
        ->and(InvoiceFolio::label(null, 10))->toBe('10')
        ->and(InvoiceFolio::label('FAC', null))->toBeNull();
});

test('el generador sigue produciendo folios que InvoiceFolio entiende', function () {
    $company = Company::factory()->create();
    $folio = app(InvoiceNumberGenerator::class)->next($company->id);

    expect($folio)->toBe('FAC-00000001')
        ->and(InvoiceFolio::parse($folio)->number)->toBe(1);
});

test('el borrador se crea con la serie y el folio del ERP', function () {
    $invoice = phase628Invoice(['folio' => 'FAC-00000019']);
    Http::fake(['*' => Http::response(phase628DraftBody(), 200)]);

    facturapiProviderFromConfig()->createDraftInvoice(phase628DraftRequest($invoice));

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->data()['series'] === 'FAC'
        && $request->data()['folio_number'] === 19
        && $request->data()['status'] === 'draft');
});

test('la actualización del borrador (PUT) también envía la serie y el folio', function () {
    $invoice = phase628Invoice(['folio' => 'FAC-00000020']);
    Http::fake(['*' => Http::response(phase628DraftBody(), 200)]);

    facturapiProviderFromConfig()->updateDraftInvoice('inv_draft_628', phase628DraftRequest($invoice));

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request->data()['series'] === 'FAC'
        && $request->data()['folio_number'] === 20);
});

test('un reintento envía exactamente el mismo payload (misma llave, mismo folio)', function () {
    $invoice = phase628Invoice(['folio' => 'FAC-00000021']);
    Http::fake(['*' => Http::response(phase628DraftBody(), 200)]);
    $provider = facturapiProviderFromConfig();

    $provider->createDraftInvoice(phase628DraftRequest($invoice));
    $provider->createDraftInvoice(phase628DraftRequest($invoice->fresh(['items'])));

    $bodies = collect(Http::recorded())->map(fn (array $pair) => $pair[0]->data())->values();

    expect($bodies)->toHaveCount(2)
        ->and($bodies[0])->toBe($bodies[1])
        ->and($bodies[0]['folio_number'])->toBe(21);
});

test('con un folio inválido el provider se detiene antes de cualquier HTTP', function () {
    $invoice = phase628Invoice(['folio' => 'FAC-CANCEL-65']);
    Http::fake();

    expect(fn () => facturapiProviderFromConfig()->createDraftInvoice(phase628DraftRequest($invoice)))
        ->toThrow(InvoiceFiscalSnapshotIncompleteException::class, 'INVOICE_FOLIO_INVALID');

    Http::assertNothingSent();
});

test('el readiness reporta INVOICE_FOLIO_INVALID y no bloquea un folio válido', function () {
    $invalid = phase628Invoice(['folio' => 'FAC-CANCEL-65']);
    $valid = phase628Invoice(['folio' => 'FAC-00000019']);

    $invalidErrors = collect(app(InvoicePacReadinessService::class)->evaluate($invalid)['errors']);
    $validCodes = collect(app(InvoicePacReadinessService::class)->evaluate($valid)['errors'])->pluck('code');

    expect($invalidErrors->firstWhere('code', 'INVOICE_FOLIO_INVALID')['field'])->toBe('folio')
        ->and($validCodes)->not->toContain('INVOICE_FOLIO_INVALID');
});
