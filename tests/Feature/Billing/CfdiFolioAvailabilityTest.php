<?php

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Exceptions\Billing\PacFolioAlreadyUsedException;
use App\Exceptions\Billing\PacUnavailableException;
use App\Exceptions\Billing\PacUnexpectedResponseException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Billing\CfdiFolioAvailability;
use App\Services\Billing\CreatePacDraftInvoiceService;
use App\Services\Billing\IssueInvoiceToPacService;
use App\Support\Billing\InvoicePacActionExceptionResponder;
use App\Support\Billing\PacIdentifiers;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.28: antes de emitir se consulta GET /invoices?series=…&folio_number=…;
 * un comprobante ajeno a la factura con el mismo folio detiene todo sin
 * escribir nada. La emisión empresarial la hace antes de la transición ERP
 * a `issued` (la factura queda en `ready`, sin cambios); `/pac/issue`, antes
 * de crear o actualizar el borrador.
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

/** @return array{User, Invoice} */
function phase628FolioInvoice(InvoiceStatus $status = InvoiceStatus::Ready, string $folio = 'FAC-00000019'): array
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Admin]);
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'folio' => $folio,
        'status' => $status,
        'subtotal' => 100,
        'tax_total' => 0,
        'total' => 100,
    ]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id, 'subtotal' => 100, 'total' => 100]);
    app(CurrentTenant::class)->set($company->id);

    return [$user, $invoice->fresh()];
}

/**
 * PAC simulado: la búsqueda por folio responde $matches (o el HTTP status
 * dado); el resto es una emisión exitosa por borrador.
 *
 * @param  list<array<string, mixed>>|int  $matches
 */
function phase628FakePac(Invoice $invoice, array|int $matches): void
{
    $draftId = 'inv_dup_'.$invoice->id;

    Http::fake(function (Request $request) use ($matches, $draftId) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'GET' && str_contains($request->url(), 'folio_number=')) {
            return is_int($matches)
                ? Http::response(['message' => 'falla'], $matches)
                : Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => count($matches), 'data' => $matches]);
        }

        if ($request->method() === 'POST' && $path === "/v2/invoices/{$draftId}/stamp") {
            return Http::response([
                'id' => $draftId, 'status' => 'valid', 'livemode' => false,
                'uuid' => 'CCCCCCCC-3333-4444-8555-666666666666', 'stamp' => ['date' => '2026-10-30T10:00:00'],
                'series' => 'FAC', 'folio_number' => 19,
            ]);
        }

        return Http::response(['id' => $draftId, 'status' => 'draft', 'livemode' => false, 'is_ready_to_stamp' => true, 'total' => 100]);
    });
}

function phase628FolioQueries(): int
{
    return collect(Http::recorded())->filter(fn (array $pair) => str_contains($pair[0]->url(), 'folio_number='))->count();
}

function phase628Writes(): int
{
    return collect(Http::recorded())->filter(fn (array $pair) => in_array($pair[0]->method(), ['POST', 'PUT', 'DELETE'], true))->count();
}

/** @return array<string, mixed> */
function phase628Row(Invoice $invoice): array
{
    return (array) DB::table('invoices')->where('id', $invoice->id)->first();
}

const PHASE628_FOREIGN = ['id' => 'inv_x', 'external_id' => null, 'status' => 'valid'];

// ==================== Provider ====================

test('el provider consulta por serie y folio exactos y normaliza las coincidencias', function () {
    [, $invoice] = phase628FolioInvoice();
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

// ==================== CfdiFolioAvailability ====================

test('sin coincidencias, o sólo con las de esta misma factura, el folio está disponible', function (string $case) {
    [, $invoice] = phase628FolioInvoice();
    phase628FakePac($invoice, $case === 'ninguna' ? [] : [
        ['id' => 'inv_own_draft', 'external_id' => PacIdentifiers::draftExternalId($invoice->company_id, $invoice->id), 'status' => 'draft'],
        ['id' => 'inv_own', 'external_id' => PacIdentifiers::externalId($invoice->company_id, $invoice->id), 'status' => 'canceled'],
    ]);

    app(CfdiFolioAvailability::class)->assertAvailable($invoice);

    expect(phase628FolioQueries())->toBe(1);
})->with(['ninguna', 'propias']);

test('un comprobante ajeno con el mismo folio lo hace no disponible', function (array $match) {
    [, $invoice] = phase628FolioInvoice();
    phase628FakePac($invoice, [$match]);

    expect(fn () => app(CfdiFolioAvailability::class)->assertAvailable($invoice))
        ->toThrow(PacFolioAlreadyUsedException::class, 'FAC-19');
})->with([
    'timbrado por fuera' => [PHASE628_FOREIGN],
    'cancelado de otro sistema' => [['id' => 'inv_y', 'external_id' => 'otro-sistema', 'status' => 'canceled']],
    'borrador de otra factura' => [['id' => 'inv_z', 'external_id' => 'company-1-invoice-999-draft', 'status' => 'draft']],
]);

test('con billing.cfdi_folio_check apagado no se consulta', function () {
    config(['billing.cfdi_folio_check' => false]);
    [, $invoice] = phase628FolioInvoice();
    phase628FakePac($invoice, [PHASE628_FOREIGN]);

    app(CfdiFolioAvailability::class)->assertAvailable($invoice);

    expect(phase628FolioQueries())->toBe(0);
});

test('los servicios de borrador ya no consultan el folio: lo hacen los orquestadores', function () {
    [, $invoice] = phase628FolioInvoice(InvoiceStatus::Issued);
    phase628FakePac($invoice, [PHASE628_FOREIGN]);

    app(CreatePacDraftInvoiceService::class)->createOrSync($invoice);

    expect(phase628FolioQueries())->toBe(0);
});

// ==================== Emisión empresarial (operations/issue) ====================

test('un folio ya usado deja la factura en ready, sin ningún cambio y sin escribir en el PAC', function () {
    [$user, $invoice] = phase628FolioInvoice(InvoiceStatus::Ready);
    phase628FakePac($invoice, [PHASE628_FOREIGN]);
    $before = phase628Row($invoice);
    $eventsBefore = DB::table('invoice_pac_events')->where('invoice_id', $invoice->id)->count();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/issue", ['confirm' => true])
        ->assertStatus(409)
        ->assertJsonPath('code', 'PAC_FOLIO_ALREADY_USED')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'FAC-19'));

    $after = phase628Row($invoice);

    expect($after)->toBe($before)
        ->and($after['status'])->toBe(InvoiceStatus::Ready->value)
        ->and($after['issued_at'])->toBeNull()
        ->and($after['pac_draft_idempotency_key'])->toBeNull()
        ->and(DB::table('invoice_pac_events')->where('invoice_id', $invoice->id)->count())->toBe($eventsBefore)
        ->and(phase628FolioQueries())->toBe(1)
        ->and(phase628Writes())->toBe(0);
});

test('si la consulta del folio falla, la factura también queda en ready sin cambios', function () {
    [$user, $invoice] = phase628FolioInvoice(InvoiceStatus::Ready);
    phase628FakePac($invoice, 503);
    $before = phase628Row($invoice);

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/issue", ['confirm' => true])
        ->assertStatus(503)
        ->assertJsonPath('code', 'PAC_UNAVAILABLE');

    expect(phase628Row($invoice))->toBe($before)
        ->and(phase628Writes())->toBe(0);
});

test('con el folio libre la emisión empresarial timbra y consulta el folio una sola vez', function () {
    [$user, $invoice] = phase628FolioInvoice(InvoiceStatus::Ready);
    phase628FakePac($invoice, []);

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/operations/issue", ['confirm' => true])
        ->assertOk();

    $fresh = $invoice->fresh();
    expect($fresh->status)->toBe(InvoiceStatus::Issued)
        ->and($fresh->pac_status)->toBe('valid')
        ->and($fresh->cfdi_series)->toBe('FAC')
        ->and($fresh->cfdi_folio_number)->toBe(19)
        ->and(phase628FolioQueries())->toBe(1);
});

// ==================== /pac/issue (IssueInvoiceToPacService directo) ====================

test('usado directo, IssueInvoiceToPacService consulta antes de crear el borrador y no escribe nada', function () {
    [, $invoice] = phase628FolioInvoice(InvoiceStatus::Issued);
    phase628FakePac($invoice, [PHASE628_FOREIGN]);

    expect(fn () => app(IssueInvoiceToPacService::class)->issue($invoice))
        ->toThrow(PacFolioAlreadyUsedException::class);

    expect(phase628Writes())->toBe(0)
        ->and($invoice->fresh()->pac_draft_idempotency_key)->toBeNull();
});

test('usado directo con una falla de la consulta, no se crea el borrador', function () {
    [, $invoice] = phase628FolioInvoice(InvoiceStatus::Issued);
    phase628FakePac($invoice, 503);

    expect(fn () => app(IssueInvoiceToPacService::class)->issue($invoice))
        ->toThrow(PacUnavailableException::class);

    expect(phase628Writes())->toBe(0);
});

// ==================== Respuesta HTTP ====================

test('la API responde 409 PAC_FOLIO_ALREADY_USED con el folio y sin detalles internos', function () {
    $response = app(InvoicePacActionExceptionResponder::class)->respond(new PacFolioAlreadyUsedException(7, 'FAC-19', 1));

    expect($response->status())->toBe(409)
        ->and($response->getData(true)['code'])->toBe('PAC_FOLIO_ALREADY_USED')
        ->and($response->getData(true)['message'])->toContain('FAC-19')
        ->and($response->getData(true)['message'])->not->toContain('[7]');
});
