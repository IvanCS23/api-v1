<?php

use App\Enums\InvoicePacEventType;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PHASE_610_SECRET',
    ]);

    Http::preventStrayRequests();
    Storage::fake('local');
});

/** @return array{0: Company, 1: User, 2: Invoice} */
function phase610Fixture(array $overrides = [], UserRole $role = UserRole::Admin): array
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => $role]);
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
        'folio' => 'FAC-00000610',
    ]);
    $invoice->forceFill(array_merge([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_phase_610_'.$invoice->id,
        'cfdi_uuid' => '96013e83-154b-4153-8e61-c38b8966e560',
        'pac_status' => 'valid',
        'cancellation_status' => 'none',
        'pac_issue_status' => 'succeeded',
        'pac_reconciliation_required' => false,
        'stamped_at' => now(),
    ], $overrides))->save();

    app(CurrentTenant::class)->set($company->id);

    return [$company, $user, $invoice->fresh()];
}

function phase610CfdiXml(string $uuid): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4">'
        .'<cfdi:Complemento><tfd:TimbreFiscalDigital '
        .'xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" UUID="'.$uuid.'"/>'
        .'</cfdi:Complemento></cfdi:Comprobante>';
}

function phase610Pdf(): string
{
    return "%PDF-1.7\nprivate phase 610 bytes\n%%EOF";
}

function phase610ReceiptXml(string $uuid): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<Acuse xmlns="http://cancelacfd.sat.gob.mx"><Folios><UUID>'.$uuid.'</UUID></Folios></Acuse>';
}

function phase610ReceiptPdf(): string
{
    return "%PDF-1.7\nprivate receipt phase 610\n%%EOF";
}

function phase610WriteStoredCfdi(Invoice $invoice): void
{
    $xml = phase610CfdiXml((string) $invoice->cfdi_uuid);
    $pdf = phase610Pdf();
    $base = "cfdi/{$invoice->company_id}/{$invoice->id}/{$invoice->cfdi_uuid}";

    $invoice->forceFill([
        'cfdi_artifacts_status' => 'stored',
        'cfdi_xml_path' => $base.'.xml',
        'cfdi_pdf_path' => $base.'.pdf',
        'cfdi_xml_sha256' => hash('sha256', $xml),
        'cfdi_pdf_sha256' => hash('sha256', $pdf),
        'cfdi_xml_size' => strlen($xml),
        'cfdi_pdf_size' => strlen($pdf),
        'cfdi_artifacts_downloaded_at' => now(),
    ])->save();

    Storage::disk('local')->put($base.'.xml', $xml);
    Storage::disk('local')->put($base.'.pdf', $pdf);
}

function phase610WriteStoredReceipt(Invoice $invoice): void
{
    $xml = phase610ReceiptXml((string) $invoice->cfdi_uuid);
    $pdf = phase610ReceiptPdf();
    $base = "cancellation-receipts/{$invoice->company_id}/{$invoice->id}/{$invoice->cfdi_uuid}";

    $invoice->forceFill([
        'cancellation_receipt_status' => 'stored',
        'cancellation_receipt_xml_path' => $base.'.xml',
        'cancellation_receipt_pdf_path' => $base.'.pdf',
        'cancellation_receipt_xml_sha256' => hash('sha256', $xml),
        'cancellation_receipt_pdf_sha256' => hash('sha256', $pdf),
        'cancellation_receipt_xml_size' => strlen($xml),
        'cancellation_receipt_pdf_size' => strlen($pdf),
        'cancellation_receipt_downloaded_at' => now(),
    ])->save();

    Storage::disk('local')->put($base.'.xml', $xml);
    Storage::disk('local')->put($base.'.pdf', $pdf);
}

test('acciones PAC requieren autenticación, tenant y abilities fiscales', function (string $endpoint, string $method) {
    [, $admin, $invoice] = phase610Fixture();
    $foreignCompany = Company::factory()->create();
    $foreign = User::factory()->create(['company_id' => $foreignCompany->id, 'role' => UserRole::Admin]);
    $employee = User::factory()->create(['company_id' => $invoice->company_id, 'role' => UserRole::Employee]);
    app(CurrentTenant::class)->clear();

    $payload = match ($endpoint) {
        'cancel' => ['motive' => '02', 'confirm' => true],
        'issue' => ['confirm' => true],
        default => [],
    };
    $uri = "/api/invoices/{$invoice->id}/pac/{$endpoint}";

    $this->json($method, $uri, $payload)->assertUnauthorized();
    $this->actingAs($foreign, 'api')->json($method, $uri, $payload)->assertNotFound();
    $this->actingAs($employee, 'api')->json($method, $uri, $payload)->assertForbidden();

    Http::assertNothingSent();
    expect($admin->role)->toBe(UserRole::Admin);
})->with([
    ['reconcile', 'POST'],
    ['artifacts', 'POST'],
    ['cancel', 'POST'],
    ['cancellation-receipt', 'POST'],
    ['issue', 'POST'],
]);

test('Policy denegada se respeta antes de llamar al PAC', function () {
    [, $user, $invoice] = phase610Fixture();
    app(CurrentTenant::class)->clear();
    Gate::before(fn (): bool => false);

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/pac/reconcile")
        ->assertForbidden();

    Http::assertNothingSent();
});

test('accountant puede gestionar PAC mientras sales y employee quedan bloqueados', function (UserRole $role, int $status) {
    [, $user, $invoice] = phase610Fixture([
        'pac_external_id' => null,
        'cfdi_uuid' => null,
        'pac_issue_status' => null,
    ], $role);
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/pac/reconcile")
        ->assertStatus($status);

    Http::assertNothingSent();
})->with([
    'accountant autorizado alcanza dominio' => [UserRole::Accountant, 409],
    'sales bloqueado' => [UserRole::Sales, 403],
    'employee bloqueado' => [UserRole::Employee, 403],
]);

test('reconcile devuelve BillingResource actualizado, timeline e idempotencia sin duplicar eventos', function () {
    [, $user, $invoice] = phase610Fixture([
        'pac_issue_status' => 'reconciliation_required',
        'pac_reconciliation_required' => true,
    ]);
    Http::fake(['*' => Http::response([
        'id' => $invoice->pac_external_id,
        'livemode' => false,
        'status' => 'valid',
        'uuid' => strtoupper((string) $invoice->cfdi_uuid),
        'stamp' => ['date' => now()->toIso8601String()],
    ])]);
    app(CurrentTenant::class)->clear();

    $uri = "/api/invoices/{$invoice->id}/pac/reconcile";
    $first = $this->actingAs($user, 'api')->postJson($uri)
        ->assertOk()
        ->assertJsonPath('pac.status', 'valid')
        ->assertJsonPath('pac.issue_status', 'succeeded')
        ->assertJsonPath('pac.reconciliation_required', true)
        ->assertJsonPath('timeline.0.type', 'reconciled');
    $this->actingAs($user, 'api')->postJson($uri)->assertOk();

    app(CurrentTenant::class)->set($invoice->company_id);
    expect($invoice->pacEvents()->where('event_type', InvoicePacEventType::Reconciled->value)->count())->toBe(1)
        ->and($first->getContent())->not->toContain('pac_response', 'sk_test_PHASE_610_SECRET', 'Authorization');
    Http::assertSentCount(2);
});

test('reconcile mapea PAC unavailable y respuesta inesperada sin filtrar detalles', function (int $status, string $code) {
    [, $user, $invoice] = phase610Fixture(['pac_reconciliation_required' => true]);
    Http::fake(['*' => Http::response([
        'message' => 'Bearer sk_test_PHASE_610_SECRET raw remote detail',
    ], $status)]);
    app(CurrentTenant::class)->clear();

    $response = $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/pac/reconcile")
        ->assertStatus($status >= 500 ? 503 : 502)
        ->assertJsonPath('code', $code);

    expect($response->getContent())->not->toContain(
        'sk_test_PHASE_610_SECRET', 'Bearer', 'raw remote detail', 'pac_response',
    );
    Http::assertSentCount(1);
})->with([
    'unavailable' => [503, 'PAC_UNAVAILABLE'],
    'unexpected' => [418, 'PAC_UNEXPECTED_RESPONSE'],
]);

test('reconcile mapea fallo de conexión como PAC unavailable', function () {
    [, $user, $invoice] = phase610Fixture(['pac_reconciliation_required' => true]);
    Http::fake(fn () => throw new ConnectionException('Bearer sk_test_PHASE_610_SECRET connection secret'));
    app(CurrentTenant::class)->clear();

    $response = $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/pac/reconcile")
        ->assertServiceUnavailable()
        ->assertJsonPath('code', 'PAC_UNAVAILABLE');

    expect($response->getContent())->not->toContain('sk_test_PHASE_610_SECRET', 'Bearer', 'connection secret');
});

test('artifacts stored es idempotente, no hace HTTP y devuelve solo snapshot', function () {
    [, $user, $invoice] = phase610Fixture();
    phase610WriteStoredCfdi($invoice);
    app(CurrentTenant::class)->clear();

    $content = $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/pac/artifacts")
        ->assertOk()
        ->assertJsonPath('cfdi.artifacts.status', 'stored')
        ->assertJsonPath('cfdi.artifacts.xml_available', true)
        ->assertJsonPath('cfdi.artifacts.pdf_available', true)
        ->getContent();

    expect($content)->not->toContain('<cfdi:', '%PDF-', 'cfdi_xml_path', 'sha256', 'storage/app');
    Http::assertNothingSent();
});

test('artifacts faltantes se descargan y almacenan mediante el Service existente', function () {
    [, $user, $invoice] = phase610Fixture();
    Http::fake([
        "*/invoices/{$invoice->pac_external_id}/xml" => Http::response(phase610CfdiXml((string) $invoice->cfdi_uuid)),
        "*/invoices/{$invoice->pac_external_id}/pdf" => Http::response(phase610Pdf()),
    ]);
    app(CurrentTenant::class)->clear();

    $response = $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/pac/artifacts")
        ->assertOk()
        ->assertJsonPath('cfdi.artifacts.status', 'stored')
        ->assertJsonPath('timeline.0.type', 'artifacts_stored');

    expect($response->getContent())->not->toContain('<cfdi:', '%PDF-', 'storage/app')
        ->and($invoice->fresh()->cfdi_artifacts_status)->toBe('stored');
    Http::assertSentCount(2);
});

test('artifacts con integridad local rota o error PAC usan errores estables', function (string $scenario, int $status, string $code) {
    [, $user, $invoice] = phase610Fixture();

    if ($scenario === 'integrity') {
        phase610WriteStoredCfdi($invoice);
        Storage::disk('local')->delete((string) $invoice->fresh()->cfdi_xml_path);
    } else {
        Http::fake(['*' => Http::response(['message' => '<xml>secret</xml>'], 503)]);
    }

    app(CurrentTenant::class)->clear();
    $response = $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/pac/artifacts")
        ->assertStatus($status)
        ->assertJsonPath('code', $code);

    expect($response->getContent())->not->toContain('<xml>', 'cfdi_xml_path', 'storage/app');
})->with([
    'integrity' => ['integrity', 409, 'CFDI_ARTIFACT_INTEGRITY_FAILURE'],
    'pac' => ['pac', 503, 'PAC_UNAVAILABLE'],
]);

test('cancel exige confirmación, motivo válido y UUID de sustitución estructuralmente seguro', function (array $payload) {
    [, $user, $invoice] = phase610Fixture();
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/pac/cancel", $payload)
        ->assertUnprocessable();

    Http::assertNothingSent();
})->with([
    'confirm ausente' => [['motive' => '02']],
    'confirm false' => [['motive' => '02', 'confirm' => false]],
    'motivo inválido' => [['motive' => '99', 'confirm' => true]],
    '01 sin sustitución' => [['motive' => '01', 'confirm' => true]],
    '01 UUID inválido' => [['motive' => '01', 'substitution_uuid' => 'not-a-uuid', 'confirm' => true]],
    '02 con sustitución' => [[
        'motive' => '02',
        'substitution_uuid' => '11111111-2222-4333-8444-555555555555',
        'confirm' => true,
    ]],
    'identidad prohibida' => [['motive' => '02', 'confirm' => true, 'company_id' => 999]],
]);

test('cancel motivo 01 envía sustitución y nunca acepta identidad fiscal desde cliente', function () {
    [, $user, $invoice] = phase610Fixture();
    $substitution = '11111111-2222-4333-8444-555555555555';
    Http::fake(['*' => Http::response([
        'id' => $invoice->pac_external_id,
        'livemode' => false,
        'status' => 'canceled',
        'cancellation_status' => 'accepted',
        'uuid' => $invoice->cfdi_uuid,
    ])]);
    app(CurrentTenant::class)->clear();

    $response = $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/pac/cancel", [
            'motive' => '01',
            'substitution_uuid' => $substitution,
            'confirm' => true,
        ])
        ->assertOk()
        ->assertJsonPath('pac.status', 'canceled')
        ->assertJsonPath('pac.cancellation_status', 'accepted')
        ->assertJsonPath('timeline.0.type', 'cancellation_requested')
        ->assertJsonPath('timeline.1.type', 'cancellation_accepted');

    Http::assertSent(function ($request) use ($invoice, $substitution): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'DELETE'
            && str_contains($request->url(), (string) $invoice->pac_external_id)
            && $query === ['motive' => '01', 'substitution' => $substitution];
    });
    expect($response->getContent())->not->toContain($substitution, 'pac_response');
});

test('cancel soporta motivos y estados remotos definidos por el Service', function (string $motive, string $remoteStatus, string $cancellationStatus) {
    [, $user, $invoice] = phase610Fixture();
    Http::fake(['*' => Http::response([
        'id' => $invoice->pac_external_id,
        'livemode' => false,
        'status' => $remoteStatus,
        'cancellation_status' => $cancellationStatus,
        'uuid' => $invoice->cfdi_uuid,
    ])]);
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/pac/cancel", [
            'motive' => $motive,
            'confirm' => true,
        ])
        ->assertOk()
        ->assertJsonPath('pac.cancellation_status', $cancellationStatus);

    Http::assertSentCount(1);
})->with([
    '02 accepted' => ['02', 'canceled', 'accepted'],
    '03 pending' => ['03', 'valid', 'pending'],
    '04 verifying' => ['04', 'valid', 'verifying'],
    '02 rejected' => ['02', 'valid', 'rejected'],
    '03 expired' => ['03', 'valid', 'expired'],
]);

test('cancel ya aceptada o en curso no repite DELETE', function (string $status) {
    [, $user, $invoice] = phase610Fixture([
        'pac_status' => $status === 'accepted' ? 'canceled' : 'valid',
        'cancellation_status' => $status,
    ]);
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/pac/cancel", ['motive' => '02', 'confirm' => true])
        ->assertConflict()
        ->assertJsonPath('code', 'INVOICE_CANNOT_BE_CANCELLED');

    Http::assertNothingSent();
})->with(['accepted', 'pending', 'verifying']);

test('cancel mapea errores PAC sin respuesta cruda', function () {
    [, $user, $invoice] = phase610Fixture();
    Http::fake(['*' => Http::response([
        'message' => 'Authorization Bearer sk_test_PHASE_610_SECRET remote secret',
    ], 422)]);
    app(CurrentTenant::class)->clear();

    $response = $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/pac/cancel", ['motive' => '02', 'confirm' => true])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'El proveedor fiscal rechazó los datos de la operación.',
            'code' => 'PAC_VALIDATION_FAILED',
        ]);

    expect($response->getContent())->not->toContain('Authorization', 'sk_test_PHASE_610_SECRET', 'remote secret');
});

test('receipt retry exitoso almacena bytes pero devuelve únicamente snapshot y timeline', function () {
    [, $user, $invoice] = phase610Fixture([
        'pac_status' => 'canceled',
        'cancellation_status' => 'accepted',
        'cancellation_receipt_status' => 'reconciliation_required',
    ]);
    Http::fake([
        "*/invoices/{$invoice->pac_external_id}/cancellation_receipt/xml" => Http::response(
            phase610ReceiptXml((string) $invoice->cfdi_uuid),
        ),
        "*/invoices/{$invoice->pac_external_id}/cancellation_receipt/pdf" => Http::response(phase610ReceiptPdf()),
    ]);
    app(CurrentTenant::class)->clear();

    $response = $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/pac/cancellation-receipt")
        ->assertOk()
        ->assertJsonPath('cancellation_receipt.status', 'stored')
        ->assertJsonPath('cancellation_receipt.available', true)
        ->assertJsonPath('timeline.0.type', 'cancellation_receipt_attempted')
        ->assertJsonPath('timeline.1.type', 'cancellation_receipt_stored');

    expect($response->getContent())->not->toContain('<Acuse', '%PDF-', 'cancellation_receipt_xml_path', 'sha256');
    Http::assertSentCount(2);
});

test('receipt stored es idempotente y hace cero HTTP', function () {
    [, $user, $invoice] = phase610Fixture([
        'pac_status' => 'canceled',
        'cancellation_status' => 'accepted',
    ]);
    phase610WriteStoredReceipt($invoice);
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/pac/cancellation-receipt")
        ->assertOk()
        ->assertJsonPath('cancellation_receipt.status', 'stored');

    Http::assertNothingSent();
});

test('receipt no aceptado, unavailable y UUID mismatch tienen errores controlados', function (string $scenario, int $status, string $code) {
    $overrides = [
        'pac_status' => 'canceled',
        'cancellation_status' => $scenario === 'not_accepted' ? 'pending' : 'accepted',
        'cancellation_receipt_status' => 'reconciliation_required',
    ];
    [, $user, $invoice] = phase610Fixture($overrides);

    if ($scenario === 'unavailable') {
        Http::fake(['*' => Http::response([
            'code' => 'invoice_cancellation_receipt_unavailable',
            'message' => 'private remote detail',
        ], 404)]);
    } elseif ($scenario === 'mismatch') {
        Http::fake([
            "*/invoices/{$invoice->pac_external_id}/cancellation_receipt/xml" => Http::response(
                phase610ReceiptXml('CF5138A2-1111-4222-8333-444444442E90'),
            ),
        ]);
    }

    app(CurrentTenant::class)->clear();
    $response = $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/pac/cancellation-receipt")
        ->assertStatus($status)
        ->assertJsonPath('code', $code);

    expect($response->getContent())->not->toContain(
        '<Acuse', 'CF5138A2-1111-4222-8333-444444442E90', 'private remote detail',
    );

    if ($scenario === 'mismatch') {
        app(CurrentTenant::class)->set($invoice->company_id);
        expect($invoice->pacEvents()
            ->where('event_type', InvoicePacEventType::CancellationReceiptIdentityMismatch->value)
            ->exists())->toBeTrue()
            ->and(Storage::disk('local')->allFiles())->toBe([]);
        Http::assertSentCount(1);
    }
})->with([
    'not accepted' => ['not_accepted', 409, 'PAC_ACTION_CONFLICT'],
    'unavailable' => ['unavailable', 409, 'CANCELLATION_RECEIPT_UNAVAILABLE'],
    'mismatch' => ['mismatch', 409, 'CANCELLATION_RECEIPT_UUID_MISMATCH'],
]);

test('throttle PAC limita a diez acciones por usuario y factura', function () {
    [, $user, $invoice] = phase610Fixture([
        'pac_external_id' => null,
        'cfdi_uuid' => null,
        'pac_issue_status' => null,
    ]);
    app(CurrentTenant::class)->clear();
    $uri = "/api/invoices/{$invoice->id}/pac/reconcile";

    foreach (range(1, 10) as $attempt) {
        $this->actingAs($user, 'api')->postJson($uri)->assertConflict();
    }

    $this->actingAs($user, 'api')->postJson($uri)->assertTooManyRequests();
    Http::assertNothingSent();
});

test('controller PAC es agnóstico al proveedor y no construye HTTP ni payloads fiscales', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/InvoicePacActionController.php'));

    expect($controller)->not->toContain(
        'FacturapiProvider', 'Illuminate\\Support\\Facades\\Http', 'pac_response',
        'withToken(', 'Http::', 'Storage::', 'DB::',
    );
});

test('issue exige confirmación y prohíbe identidad payload y snapshot fiscales', function (array $payload) {
    [, $user, $invoice] = phase610Fixture();
    InvoiceItem::factory()->create(['company_id' => $invoice->company_id, 'invoice_id' => $invoice->id]);
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/pac/issue", $payload)
        ->assertUnprocessable();

    Http::assertNothingSent();
})->with([
    'sin confirm' => [[]],
    'confirm false' => [['confirm' => false]],
    'company_id' => [['confirm' => true, 'company_id' => 999]],
    'pac id' => [['confirm' => true, 'pac_external_id' => 'foreign']],
    'draft id' => [['confirm' => true, 'pac_draft_external_id' => 'foreign']],
    'uuid' => [['confirm' => true, 'cfdi_uuid' => '11111111-2222-4333-8444-555555555555']],
    'status' => [['confirm' => true, 'status' => 'issued']],
    'payment form' => [['confirm' => true, 'payment_form' => '03']],
    'items' => [['confirm' => true, 'items' => []]],
    'customer' => [['confirm' => true, 'customer' => []]],
    'payload' => [['confirm' => true, 'payload' => []]],
]);

test('issue API E2E crea draft y timbra, responde BillingResource y segunda llamada hace cero HTTP', function () {
    [, $user, $invoice] = phase610Fixture([
        'pac_provider' => null,
        'pac_external_id' => null,
        'cfdi_uuid' => null,
        'pac_status' => null,
        'cancellation_status' => null,
        'pac_issue_status' => null,
    ]);
    InvoiceItem::factory()->create(['company_id' => $invoice->company_id, 'invoice_id' => $invoice->id]);
    $draftId = 'inv_issue_api_'.$invoice->id;

    Http::fake(function ($request) use ($invoice, $draftId) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'POST' && $path === '/v2/invoices') {
            return Http::response([
                'id' => $draftId,
                'status' => 'draft',
                'livemode' => false,
                'is_ready_to_stamp' => true,
                'total' => (float) $invoice->total,
            ]);
        }

        if ($request->method() === 'GET' && $path === "/v2/invoices/{$draftId}") {
            return Http::response([
                'id' => $draftId,
                'status' => 'draft',
                'livemode' => false,
                'is_ready_to_stamp' => true,
                'total' => (float) $invoice->total,
            ]);
        }

        if ($request->method() === 'POST' && $path === "/v2/invoices/{$draftId}/stamp") {
            return Http::response([
                'id' => $draftId,
                'status' => 'valid',
                'livemode' => false,
                'uuid' => 'DDDDDDDD-1111-4222-8333-444444444444',
                'stamp' => ['date' => '2026-08-12T14:00:00Z'],
            ]);
        }

        return Http::response([], 599);
    });
    app(CurrentTenant::class)->clear();
    $uri = "/api/invoices/{$invoice->id}/pac/issue";

    $first = $this->actingAs($user, 'api')
        ->postJson($uri, ['confirm' => true])
        ->assertOk()
        ->assertJsonPath('status', 'issued')
        ->assertJsonPath('pac.status', 'valid')
        ->assertJsonPath('pac.issue_status', 'succeeded')
        ->assertJsonPath('cfdi.uuid', 'DDDDDDDD-1111-4222-8333-444444444444')
        ->assertJsonPath('cfdi.artifacts.status', null)
        ->assertJsonPath('timeline.0.type', 'draft_created')
        ->assertJsonPath('timeline.3.type', 'stamp_succeeded');
    $sentAfterFirst = Http::recorded()->count();

    $second = $this->actingAs($user, 'api')
        ->postJson($uri, ['confirm' => true])
        ->assertOk()
        ->assertJsonPath('cfdi.uuid', 'DDDDDDDD-1111-4222-8333-444444444444');

    expect($sentAfterFirst)->toBe(3)
        ->and(Http::recorded())->toHaveCount(3)
        ->and($first->getContent())->not->toContain(
            $draftId, 'pac_draft_external_id', 'idempotency_key', 'pac_response',
            '<cfdi:', '%PDF-', 'sk_test_PHASE_610_SECRET', 'Authorization',
        )
        ->and($second->getContent())->not->toContain($draftId, 'pac_response');
});

test('issue API permite admin y accountant pero no sales ni employee', function (UserRole $role, int $status) {
    [, $user, $invoice] = phase610Fixture([
        'pac_issue_status' => 'succeeded',
        'pac_external_id' => 'inv_already_'.$role->value,
        'cfdi_uuid' => 'EEEEEEEE-1111-4222-8333-444444444444',
        'pac_status' => 'valid',
    ], $role);
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/pac/issue", ['confirm' => true])
        ->assertStatus($status);

    Http::assertNothingSent();
})->with([
    'admin' => [UserRole::Admin, 200],
    'accountant' => [UserRole::Accountant, 200],
    'sales' => [UserRole::Sales, 403],
    'employee' => [UserRole::Employee, 403],
]);

test('issue API mapea estado local y readiness a errores estables sin HTTP', function (string $scenario, int $status, string $code) {
    $overrides = [
        'pac_provider' => null,
        'pac_external_id' => null,
        'cfdi_uuid' => null,
        'pac_status' => null,
        'pac_issue_status' => null,
    ];

    if ($scenario === 'state') {
        $overrides['status'] = InvoiceStatus::Ready;
    } elseif ($scenario === 'readiness') {
        $overrides['payment_form'] = null;
    } elseif ($scenario === 'pending') {
        $overrides['pac_issue_status'] = 'pending';
    } elseif ($scenario === 'ambiguous') {
        $overrides['pac_issue_status'] = 'reconciliation_required';
        $overrides['pac_reconciliation_required'] = true;
    } elseif ($scenario === 'canceled') {
        $overrides['pac_external_id'] = 'inv_canceled_existing';
        $overrides['pac_status'] = 'canceled';
    }

    [, $user, $invoice] = phase610Fixture($overrides);
    InvoiceItem::factory()->create(['company_id' => $invoice->company_id, 'invoice_id' => $invoice->id]);
    app(CurrentTenant::class)->clear();

    $response = $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/pac/issue", ['confirm' => true])
        ->assertStatus($status)
        ->assertJsonPath('code', $code);

    if ($scenario === 'readiness') {
        $response->assertJsonPath('errors.0.code', 'INVOICE_PAYMENT_FORM_MISSING')
            ->assertJsonPath('errors.0.field', 'payment_form');
    }

    expect($response->getContent())->not->toContain('client_rfc', 'pac_response', 'sk_test_PHASE_610_SECRET');
    Http::assertNothingSent();
})->with([
    'state' => ['state', 409, 'INVOICE_NOT_ELIGIBLE_FOR_PAC'],
    'readiness' => ['readiness', 422, 'INVOICE_NOT_READY_FOR_PAC'],
    'pending' => ['pending', 409, 'PAC_ISSUANCE_IN_PROGRESS'],
    'ambiguous' => ['ambiguous', 409, 'PAC_RECONCILIATION_REQUIRED'],
    'canceled' => ['canceled', 409, 'PAC_RESOURCE_CANCELED'],
]);

test('issue API sanitiza errores del PAC', function (int $remoteStatus, int $status, string $code) {
    [, $user, $invoice] = phase610Fixture([
        'pac_provider' => null,
        'pac_external_id' => null,
        'cfdi_uuid' => null,
        'pac_status' => null,
        'pac_issue_status' => null,
    ]);
    InvoiceItem::factory()->create(['company_id' => $invoice->company_id, 'invoice_id' => $invoice->id]);
    Http::fake(['*' => Http::response([
        'message' => 'Authorization Bearer sk_test_PHASE_610_SECRET private raw response',
        'code' => 'remote_private_code',
    ], $remoteStatus)]);
    app(CurrentTenant::class)->clear();

    $response = $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/pac/issue", ['confirm' => true])
        ->assertStatus($status)
        ->assertJsonPath('code', $code);

    expect($response->getContent())->not->toContain(
        'Authorization', 'Bearer', 'sk_test_PHASE_610_SECRET',
        'private raw response', 'remote_private_code', 'pac_response',
    );
})->with([
    'validation' => [422, 422, 'PAC_VALIDATION_FAILED'],
    'authentication' => [401, 503, 'PAC_AUTHENTICATION_FAILED'],
    'rate limit' => [429, 429, 'PAC_RATE_LIMITED'],
    'unavailable' => [503, 503, 'PAC_UNAVAILABLE'],
    'unexpected' => [418, 502, 'PAC_UNEXPECTED_RESPONSE'],
]);

test('issue API convierte stamp ambiguo en reconciliación requerida y no reintenta', function () {
    [, $user, $invoice] = phase610Fixture([
        'pac_provider' => null,
        'pac_external_id' => null,
        'cfdi_uuid' => null,
        'pac_status' => null,
        'pac_issue_status' => null,
    ]);
    InvoiceItem::factory()->create(['company_id' => $invoice->company_id, 'invoice_id' => $invoice->id]);
    $draftId = 'inv_ambiguous_api_'.$invoice->id;

    Http::fake(function ($request) use ($invoice, $draftId) {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/v2/invoices')) {
            return Http::response([
                'id' => $draftId,
                'status' => 'draft',
                'livemode' => false,
                'is_ready_to_stamp' => true,
                'total' => (float) $invoice->total,
            ]);
        }

        if (str_ends_with($request->url(), '/stamp')) {
            return Http::response(['message' => 'ambiguous private'], 503);
        }

        return Http::response([
            'id' => $draftId,
            'status' => 'draft',
            'livemode' => false,
            'is_ready_to_stamp' => true,
            'total' => (float) $invoice->total,
        ]);
    });
    app(CurrentTenant::class)->clear();

    $response = $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/pac/issue", ['confirm' => true])
        ->assertConflict()
        ->assertJsonPath('code', 'PAC_RECONCILIATION_REQUIRED');

    expect($response->getContent())->not->toContain('ambiguous private')
        ->and(Http::recorded()->filter(
            fn (array $record): bool => str_ends_with($record[0]->url(), '/stamp'),
        ))->toHaveCount(1);
});

test('issue API no timbra cuando update conserva draft no listo', function () {
    [, $user, $invoice] = phase610Fixture([
        'pac_provider' => null,
        'pac_external_id' => null,
        'cfdi_uuid' => null,
        'pac_status' => null,
        'pac_issue_status' => null,
    ]);
    InvoiceItem::factory()->create(['company_id' => $invoice->company_id, 'invoice_id' => $invoice->id]);
    $draftId = 'inv_not_ready_api_'.$invoice->id;

    Http::fake(fn () => Http::response([
        'id' => $draftId,
        'status' => 'draft',
        'livemode' => false,
        'is_ready_to_stamp' => false,
        'total' => (float) $invoice->total,
    ]));
    app(CurrentTenant::class)->clear();

    $this->actingAs($user, 'api')
        ->postJson("/api/invoices/{$invoice->id}/pac/issue", ['confirm' => true])
        ->assertConflict()
        ->assertExactJson([
            'message' => 'El borrador fiscal todavía no está listo para timbrarse.',
            'code' => 'PAC_DRAFT_NOT_READY',
        ]);

    Http::assertSentCount(3);
    Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/stamp'));
});

test('issue reutiliza throttle PAC de diez acciones por usuario y factura', function () {
    [, $user, $invoice] = phase610Fixture([
        'status' => InvoiceStatus::Ready,
        'pac_provider' => null,
        'pac_external_id' => null,
        'cfdi_uuid' => null,
        'pac_status' => null,
        'pac_issue_status' => null,
    ]);
    app(CurrentTenant::class)->clear();
    $uri = "/api/invoices/{$invoice->id}/pac/issue";

    foreach (range(1, 10) as $attempt) {
        $this->actingAs($user, 'api')->postJson($uri, ['confirm' => true])->assertConflict();
    }

    $this->actingAs($user, 'api')
        ->postJson($uri, ['confirm' => true])
        ->assertTooManyRequests();
    Http::assertNothingSent();
});
