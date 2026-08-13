<?php

use App\Enums\InvoicePacEventType;
use App\Enums\InvoiceStatus;
use App\Exceptions\Billing\InvoiceNotReadyForPacException;
use App\Exceptions\Billing\PacReconciliationRequiredException;
use App\Exceptions\Billing\PacResourceCanceledException;
use App\Exceptions\Billing\PacUnavailableException;
use App\Exceptions\Billing\PacUnexpectedResponseException;
use App\Exceptions\Billing\PacValidationException;
use App\Exceptions\InvoiceCannotBeIssuedException;
use App\Exceptions\InvoiceDraftNotReadyToStampException;
use App\Exceptions\InvoiceIssuanceInProgressException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Billing\IssueInvoiceToPacService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PHASE_611_SECRET',
    ]);

    Http::preventStrayRequests();
    Storage::fake('local');
});

function phase611Invoice(array $overrides = []): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create(array_merge([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
        'subtotal' => 100,
        'tax_total' => 0,
        'total' => 100,
    ], $overrides));
    InvoiceItem::factory()->create([
        'company_id' => $company->id,
        'invoice_id' => $invoice->id,
    ]);
    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh(['items']);
}

/** @return array<string, mixed> */
function phase611DraftBody(Invoice $invoice, bool $ready = true, string $status = 'draft'): array
{
    return [
        'id' => $invoice->pac_draft_external_id ?? 'inv_draft_phase611_'.$invoice->id,
        'status' => $status,
        'livemode' => false,
        'is_ready_to_stamp' => $status === 'draft' ? $ready : null,
        'created_at' => '2026-08-12T12:00:00Z',
        'total' => 100,
    ];
}

/** @return array<string, mixed> */
function phase611StampBody(Invoice $invoice, array $overrides = []): array
{
    return array_merge([
        'id' => $invoice->pac_draft_external_id ?? 'inv_draft_phase611_'.$invoice->id,
        'status' => 'valid',
        'livemode' => false,
        'uuid' => 'AAAAAAAA-1111-4222-8333-444444444444',
        'stamp' => ['date' => '2026-08-12T12:05:00Z'],
    ], $overrides);
}

test('solo Invoice local issued es elegible y readiness bloquea antes de HTTP', function (string $scenario) {
    $invoice = $scenario === 'state'
        ? phase611Invoice(['status' => InvoiceStatus::Ready])
        : phase611Invoice(['payment_form' => null]);

    $expected = $scenario === 'state'
        ? InvoiceCannotBeIssuedException::class
        : InvoiceNotReadyForPacException::class;

    expect(fn () => app(IssueInvoiceToPacService::class)->issue($invoice))
        ->toThrow($expected);
    Http::assertNothingSent();
})->with(['state', 'readiness']);

test('readiness conserva códigos y campos seguros sin valores fiscales', function () {
    $invoice = phase611Invoice(['payment_form' => null, 'client_rfc' => '']);

    try {
        app(IssueInvoiceToPacService::class)->issue($invoice);
        test()->fail('Se esperaba InvoiceNotReadyForPacException.');
    } catch (InvoiceNotReadyForPacException $error) {
        expect($error->errors)->toContain(
            ['code' => 'INVOICE_CLIENT_RFC_MISSING', 'field' => 'client_rfc'],
            ['code' => 'INVOICE_PAYMENT_FORM_MISSING', 'field' => 'payment_form'],
        )->and(json_encode($error->errors))->not->toContain('sk_test_PHASE_611_SECRET', 'Authorization');
    }

    Http::assertNothingSent();
});

test('sin draft crea, sincroniza y timbra cuando ready true', function () {
    $invoice = phase611Invoice();
    $draftId = 'inv_draft_phase611_'.$invoice->id;

    Http::fake(function (Request $request) use ($invoice, $draftId) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        return match ([$request->method(), $path]) {
            ['POST', '/v2/invoices'] => Http::response(phase611DraftBody($invoice)),
            ['GET', "/v2/invoices/{$draftId}"] => Http::response(array_merge(
                phase611DraftBody($invoice),
                ['id' => $draftId],
            )),
            ['POST', "/v2/invoices/{$draftId}/stamp"] => Http::response(array_merge(
                phase611StampBody($invoice),
                ['id' => $draftId],
            )),
            default => Http::response([], 599),
        };
    });

    $result = app(IssueInvoiceToPacService::class)->issue($invoice);

    expect($result->pac_external_id)->toBe($draftId)
        ->and($result->cfdi_uuid)->toBe('AAAAAAAA-1111-4222-8333-444444444444')
        ->and($result->pac_status)->toBe('valid')
        ->and($result->pac_issue_status)->toBe('succeeded')
        ->and($result->cfdi_artifacts_status)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and($result->pacEvents()->pluck('event_type')->all())->toBe([
            InvoicePacEventType::DraftCreated,
            InvoicePacEventType::DraftSynced,
            InvoicePacEventType::StampAttempted,
            InvoicePacEventType::StampSucceeded,
        ]);

    $requests = Http::recorded();
    expect($requests)->toHaveCount(3)
        ->and($requests[0][0]->method())->toBe('POST')
        ->and($requests[1][0]->method())->toBe('GET')
        ->and($requests[2][0]->method())->toBe('POST')
        ->and($requests[2][0]->url())->toEndWith("/invoices/{$draftId}/stamp");
});

test('draft creado no ready se sincroniza, actualiza y solo entonces timbra', function () {
    $invoice = phase611Invoice();
    $draftId = 'inv_draft_phase611_'.$invoice->id;
    $getCount = 0;

    Http::fake(function (Request $request) use ($invoice, $draftId, &$getCount) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'POST' && $path === '/v2/invoices') {
            return Http::response(array_merge(phase611DraftBody($invoice, false), ['id' => $draftId]));
        }

        if ($request->method() === 'GET' && $path === "/v2/invoices/{$draftId}") {
            $getCount++;

            return Http::response(array_merge(phase611DraftBody($invoice, $getCount > 1), ['id' => $draftId]));
        }

        if ($request->method() === 'PUT' && $path === "/v2/invoices/{$draftId}") {
            return Http::response(array_merge(phase611DraftBody($invoice, true), ['id' => $draftId]));
        }

        if ($request->method() === 'POST' && $path === "/v2/invoices/{$draftId}/stamp") {
            return Http::response(array_merge(phase611StampBody($invoice), ['id' => $draftId]));
        }

        return Http::response([], 599);
    });

    $result = app(IssueInvoiceToPacService::class)->issue($invoice);
    $methods = Http::recorded()
        ->map(fn (array $record): string => $record[0]->method())
        ->all();

    expect($result->pac_issue_status)->toBe('succeeded')
        ->and($methods)->toBe(['POST', 'GET', 'PUT', 'GET', 'POST'])
        ->and($result->pacEvents()->pluck('event_type')->all())->toBe([
            InvoicePacEventType::DraftCreated,
            InvoicePacEventType::DraftSynced,
            InvoicePacEventType::DraftUpdated,
            InvoicePacEventType::DraftSynced,
            InvoicePacEventType::StampAttempted,
            InvoicePacEventType::StampSucceeded,
        ]);
});

test('update que sigue not ready nunca llama stamp', function () {
    $invoice = phase611Invoice();
    $draftId = 'inv_draft_phase611_'.$invoice->id;

    Http::fake(function (Request $request) use ($invoice, $draftId) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        return match ($request->method()) {
            'POST' => Http::response(array_merge(phase611DraftBody($invoice, false), ['id' => $draftId])),
            'GET', 'PUT' => Http::response(array_merge(phase611DraftBody($invoice, false), ['id' => $draftId])),
            default => Http::response([], 599),
        };
    });

    expect(fn () => app(IssueInvoiceToPacService::class)->issue($invoice))
        ->toThrow(InvoiceDraftNotReadyToStampException::class);

    Http::assertSentCount(3);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/stamp'));
});

test('draft existente se reutiliza y sincroniza sin crear otro', function () {
    $invoice = phase611Invoice();
    $draftId = 'inv_existing_phase611_'.$invoice->id;
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_draft_external_id' => $draftId,
        'pac_draft_status' => 'draft',
        'pac_draft_ready_to_stamp' => false,
    ])->save();

    Http::fake(function (Request $request) use ($invoice, $draftId) {
        return str_ends_with($request->url(), '/stamp')
            ? Http::response(array_merge(phase611StampBody($invoice), ['id' => $draftId]))
            : Http::response(array_merge(phase611DraftBody($invoice, true), ['id' => $draftId]));
    });

    $result = app(IssueInvoiceToPacService::class)->issue($invoice->fresh());

    expect($result->pac_issue_status)->toBe('succeeded');
    Http::assertSentCount(3);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v2/invoices'));
});

test('remote valid se reconcilia sin update ni stamp', function () {
    $invoice = phase611Invoice();
    $draftId = 'inv_valid_phase611_'.$invoice->id;
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_draft_external_id' => $draftId,
        'pac_draft_status' => 'draft',
    ])->save();

    Http::fake(['*' => Http::response([
        'id' => $draftId,
        'status' => 'valid',
        'livemode' => false,
        'uuid' => 'BBBBBBBB-1111-4222-8333-444444444444',
        'stamp' => ['date' => '2026-08-12T13:00:00Z'],
    ])]);

    $result = app(IssueInvoiceToPacService::class)->issue($invoice->fresh());

    expect($result->cfdi_uuid)->toBe('BBBBBBBB-1111-4222-8333-444444444444')
        ->and($result->pac_issue_status)->toBe('succeeded');
    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
});

test('remote pending o canceled se detiene sin update ni stamp', function (string $status, string $exception) {
    $invoice = phase611Invoice();
    $draftId = 'inv_state_phase611_'.$invoice->id;
    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_draft_external_id' => $draftId,
        'pac_draft_status' => 'draft',
    ])->save();

    Http::fake(['*' => Http::response([
        'id' => $draftId,
        'status' => $status,
        'livemode' => false,
    ])]);

    expect(fn () => app(IssueInvoiceToPacService::class)->issue($invoice->fresh()))
        ->toThrow($exception);
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['PUT', 'POST'], true));
})->with([
    'pending' => ['pending', InvoiceIssuanceInProgressException::class],
    'canceled' => ['canceled', PacResourceCanceledException::class],
]);

test('ya timbrada o succeeded es idempotente y segunda llamada hace cero HTTP adicional', function (string $scenario) {
    $attributes = $scenario === 'uuid'
        ? [
            'pac_external_id' => 'inv_final_phase611',
            'cfdi_uuid' => 'CCCCCCCC-1111-4222-8333-444444444444',
            'pac_status' => 'valid',
            'pac_issue_status' => 'succeeded',
        ]
        : ['pac_issue_status' => 'succeeded'];
    $invoice = phase611Invoice();
    $invoice->forceFill($attributes)->save();

    $result = app(IssueInvoiceToPacService::class)->issue($invoice->fresh());

    expect($result->id)->toBe($invoice->id);
    Http::assertNothingSent();
})->with(['uuid', 'succeeded']);

test('pending o reconciliation required local bloquea sin HTTP', function (array $attributes, string $exception) {
    $invoice = phase611Invoice();
    $invoice->forceFill($attributes)->save();

    expect(fn () => app(IssueInvoiceToPacService::class)->issue($invoice->fresh()))
        ->toThrow($exception);
    Http::assertNothingSent();
})->with([
    'pending' => [['pac_issue_status' => 'pending'], InvoiceIssuanceInProgressException::class],
    'ambiguous' => [[
        'pac_issue_status' => 'reconciliation_required',
        'pac_reconciliation_required' => true,
    ], PacReconciliationRequiredException::class],
]);

test('stamp ambiguo marca reconciliation required y nunca reintenta stamp', function () {
    $invoice = phase611Invoice();
    $draftId = 'inv_ambiguous_phase611_'.$invoice->id;

    Http::fake(function (Request $request) use ($invoice, $draftId) {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/v2/invoices')) {
            return Http::response(array_merge(phase611DraftBody($invoice), ['id' => $draftId]));
        }

        if (str_ends_with($request->url(), '/stamp')) {
            return Http::response(['message' => 'private remote failure'], 503);
        }

        return Http::response(array_merge(phase611DraftBody($invoice), ['id' => $draftId]));
    });

    expect(fn () => app(IssueInvoiceToPacService::class)->issue($invoice))
        ->toThrow(PacReconciliationRequiredException::class);

    $fresh = $invoice->fresh();
    expect($fresh->pac_issue_status)->toBe('reconciliation_required')
        ->and($fresh->pac_reconciliation_required)->toBeTrue()
        ->and($fresh->cfdi_uuid)->toBeNull();
    Http::assertSentCount(3);
    expect(collect(Http::recorded())->filter(
        fn (array $record): bool => str_ends_with($record[0]->url(), '/stamp'),
    ))->toHaveCount(1);
});

test('fallos definitivos y de transporte se propagan con la clasificación existente', function (int $status, string $exception) {
    $invoice = phase611Invoice();

    Http::fake(['*' => Http::response([
        'message' => 'private raw remote detail',
        'code' => 'remote_code',
    ], $status)]);

    expect(fn () => app(IssueInvoiceToPacService::class)->issue($invoice))
        ->toThrow($exception);
})->with([
    'validation' => [422, PacValidationException::class],
    'unavailable al crear draft' => [503, PacUnavailableException::class],
    'unexpected' => [418, PacUnexpectedResponseException::class],
]);

test('la reserva pending existe antes del HTTP stamp y evita una segunda operación efectiva', function () {
    $invoice = phase611Invoice();
    $draftId = 'inv_reservation_phase611_'.$invoice->id;
    $statusDuringStamp = null;

    Http::fake(function (Request $request) use ($invoice, $draftId, &$statusDuringStamp) {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/v2/invoices')) {
            return Http::response(array_merge(phase611DraftBody($invoice), ['id' => $draftId]));
        }

        if (str_ends_with($request->url(), '/stamp')) {
            $statusDuringStamp = $invoice->fresh()->pac_issue_status;

            return Http::response(array_merge(phase611StampBody($invoice), ['id' => $draftId]));
        }

        return Http::response(array_merge(phase611DraftBody($invoice), ['id' => $draftId]));
    });

    $result = app(IssueInvoiceToPacService::class)->issue($invoice);
    $sentAfterFirst = count(Http::recorded());
    app(IssueInvoiceToPacService::class)->issue($result->fresh());

    expect($statusDuringStamp)->toBe('pending')
        ->and(count(Http::recorded()))->toBe($sentAfterFirst)
        ->and($result->pac_issue_attempts)->toBe(1)
        ->and($result->pacEvents()->count())->toBe(4);
});

test('tenant ajeno falla cerrado antes de readiness o HTTP', function () {
    $invoice = phase611Invoice();
    app(CurrentTenant::class)->set(Company::factory()->create()->id);

    expect(fn () => app(IssueInvoiceToPacService::class)->issue($invoice))
        ->toThrow(ModelNotFoundException::class);
    Http::assertNothingSent();
});

test('orquestador es PAC agnóstico y no implementa transporte locks payloads ni artifacts', function () {
    $source = file_get_contents(app_path('Services/Billing/IssueInvoiceToPacService.php'));

    expect($source)->not->toContain(
        'FacturapiProvider', 'PacProvider', 'Http::', 'DB::', 'lockForUpdate',
        'PacInvoiceRequest', 'Storage::', 'downloadXml', 'downloadPdf',
    );
});
