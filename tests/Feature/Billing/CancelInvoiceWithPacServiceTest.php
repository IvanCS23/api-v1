<?php

use App\Enums\CfdiCancellationMotive;
use App\Enums\InvoiceStatus;
use App\Exceptions\Billing\PacAuthenticationException;
use App\Exceptions\Billing\PacConflictException;
use App\Exceptions\Billing\PacRateLimitException;
use App\Exceptions\Billing\PacUnavailableException;
use App\Exceptions\Billing\PacUnexpectedEnvironmentException;
use App\Exceptions\Billing\PacUnexpectedResponseException;
use App\Exceptions\Billing\PacValidationException;
use App\Exceptions\InvoiceCannotBeCancelledException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Services\Billing\CancelInvoiceWithPacService;
use App\Services\Billing\InvoiceWorkflow;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PHASE_65_SECRET',
    ]);

    Http::preventStrayRequests();
});

function phase65CancelableInvoice(?Company $company = null, array $pacAttributes = []): Invoice
{
    $company ??= Company::factory()->create();
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
        'folio' => 'FAC-CANCEL-65',
        'client_name' => 'Snapshot cancelación',
        'client_rfc' => 'CANCELSECRET010101AAA',
        'client_calle' => 'Domicilio confidencial cancelación',
        'subtotal' => 100,
        'discount_total' => 5,
        'tax_total' => 15.20,
        'total' => 110.20,
        'currency' => 'MXN',
    ]);

    $invoice->forceFill(array_merge([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_cancel_phase65_'.$invoice->id,
        'cfdi_uuid' => 'fced601d-c4f6-4ce7-8f05-f3d38de530f9',
        'pac_status' => 'valid',
        'cancellation_status' => 'none',
        'stamped_at' => '2026-08-01 10:00:00',
        'pac_issue_status' => 'succeeded',
        'pac_reconciliation_required' => false,
        'cfdi_xml_path' => 'cfdi/company/invoice.xml',
        'cfdi_pdf_path' => 'cfdi/company/invoice.pdf',
        'cfdi_xml_sha256' => str_repeat('a', 64),
        'cfdi_pdf_sha256' => str_repeat('b', 64),
        'cfdi_xml_size' => 123,
        'cfdi_pdf_size' => 456,
        'cfdi_artifacts_status' => 'stored',
    ], $pacAttributes))->save();

    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh();
}

function fakePhase65Cancellation(Invoice $invoice, array $overrides = [], int $httpStatus = 200): void
{
    Http::fake([
        "*/invoices/{$invoice->pac_external_id}*" => Http::response(array_merge([
            'id' => $invoice->pac_external_id,
            'livemode' => false,
            'status' => 'canceled',
            'cancellation_status' => 'accepted',
            'uuid' => strtoupper((string) $invoice->cfdi_uuid),
        ], $overrides), $httpStatus),
    ]);
}

test('tenant correcto usa DELETE, Bearer TEST y query motive exacta sin body JSON', function () {
    $invoice = phase65CancelableInvoice();
    fakePhase65Cancellation($invoice);

    $result = app(CancelInvoiceWithPacService::class)->cancel(
        $invoice,
        CfdiCancellationMotive::ErrorsWithoutRelation,
    );

    Http::assertSentCount(1);
    Http::assertSent(function ($request) use ($invoice) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'DELETE'
            && parse_url($request->url(), PHP_URL_PATH) === "/v2/invoices/{$invoice->pac_external_id}"
            && $query === ['motive' => '02']
            && $request->body() === ''
            && $request->hasHeader('Authorization', 'Bearer sk_test_PHASE_65_SECRET');
    });

    expect($result->pac_status)->toBe('canceled')
        ->and($result->cancellation_status)->toBe('accepted');
});

test('DELETE ocurre fuera de una transacción adicional y el lock se toma después', function () {
    $invoice = phase65CancelableInvoice();
    $baselineTransactionLevel = DB::transactionLevel();
    $transactionLevelDuringHttp = null;

    Http::fake(function () use ($invoice, &$transactionLevelDuringHttp) {
        $transactionLevelDuringHttp = DB::transactionLevel();

        return Http::response([
            'id' => $invoice->pac_external_id,
            'livemode' => false,
            'status' => 'valid',
            'cancellation_status' => 'pending',
            'uuid' => $invoice->cfdi_uuid,
        ], 200);
    });

    app(CancelInvoiceWithPacService::class)->cancel(
        $invoice,
        CfdiCancellationMotive::ErrorsWithoutRelation,
    );

    expect($transactionLevelDuringHttp)->toBe($baselineTransactionLevel)
        ->and($invoice->fresh()->cancellation_status)->toBe('pending');
});

test('sin tenant y con Invoice ajena falla cerrado antes del HTTP', function (string $scenario) {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $invoice = phase65CancelableInvoice($companyB);
    Http::fake();

    if ($scenario === 'none') {
        app(CurrentTenant::class)->clear();
    } else {
        app(CurrentTenant::class)->set($companyA->id);
    }

    expect(fn () => app(CancelInvoiceWithPacService::class)->cancel(
        $invoice,
        CfdiCancellationMotive::ErrorsWithoutRelation,
    ))->toThrow(ModelNotFoundException::class);

    Http::assertNothingSent();
})->with(['none', 'foreign']);

test('precondiciones locales rechazan identidad o pac_status incompatibles sin HTTP', function (array $attributes) {
    $invoice = phase65CancelableInvoice(pacAttributes: $attributes);
    Http::fake();

    expect(fn () => app(CancelInvoiceWithPacService::class)->cancel(
        $invoice,
        CfdiCancellationMotive::ErrorsWithoutRelation,
    ))->toThrow(InvoiceCannotBeCancelledException::class);

    Http::assertNothingSent();
})->with([
    'sin pac_external_id' => [['pac_external_id' => null]],
    'sin cfdi_uuid' => [['cfdi_uuid' => null]],
    'pac pending' => [['pac_status' => 'pending']],
    'pac canceled' => [['pac_status' => 'canceled', 'cancellation_status' => 'accepted']],
]);

test('motivo 01 exige UUID válido y diferente al CFDI actual antes del HTTP', function (?string $substitutionUuid) {
    $invoice = phase65CancelableInvoice();
    Http::fake();

    expect(fn () => app(CancelInvoiceWithPacService::class)->cancel(
        $invoice,
        CfdiCancellationMotive::ErrorsWithRelation,
        $substitutionUuid,
    ))->toThrow(InvoiceCannotBeCancelledException::class);

    Http::assertNothingSent();
})->with([
    'ausente' => [null],
    'vacío' => [''],
    'inválido' => ['no-es-uuid'],
    'igual al actual' => ['FCED601D-C4F6-4CE7-8F05-F3D38DE530F9'],
]);

test('motivo 01 envía substitution y motivos 02 03 04 nunca la envían', function (CfdiCancellationMotive $motive, bool $expectsSubstitution) {
    $invoice = phase65CancelableInvoice();
    $substitution = '12345678-1234-1234-1234-123456789abc';
    fakePhase65Cancellation($invoice);

    app(CancelInvoiceWithPacService::class)->cancel($invoice, $motive, $substitution);

    Http::assertSent(function ($request) use ($motive, $substitution, $expectsSubstitution) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['motive'] ?? null) === $motive->value
            && ($expectsSubstitution
                ? ($query['substitution'] ?? null) === $substitution
                : ! array_key_exists('substitution', $query));
    });
})->with([
    '01' => [CfdiCancellationMotive::ErrorsWithRelation, true],
    '02' => [CfdiCancellationMotive::ErrorsWithoutRelation, false],
    '03' => [CfdiCancellationMotive::OperationNotPerformed, false],
    '04' => [CfdiCancellationMotive::GlobalInvoiceRelatedOperation, false],
]);

test('política por cancellation_status persiste estados finales y ambiguos correctamente', function (string $status, string $cancellationStatus, bool $required) {
    $invoice = phase65CancelableInvoice();
    fakePhase65Cancellation($invoice, [
        'status' => $status,
        'cancellation_status' => $cancellationStatus,
    ]);

    $result = app(CancelInvoiceWithPacService::class)->cancel(
        $invoice,
        CfdiCancellationMotive::OperationNotPerformed,
    );

    expect($result->pac_status)->toBe($status)
        ->and($result->cancellation_status)->toBe($cancellationStatus)
        ->and($result->pac_reconciliation_required)->toBe($required)
        ->and($result->last_pac_sync_at)->not->toBeNull()
        ->and($result->pac_response['livemode'])->toBeFalse()
        ->and($result->pac_last_error)->toBeNull()
        ->and($result->status)->toBe(InvoiceStatus::Issued);
})->with([
    'accepted' => ['canceled', 'accepted', false],
    'pending' => ['valid', 'pending', true],
    'verifying' => ['valid', 'verifying', true],
    'rejected' => ['valid', 'rejected', false],
    'expired' => ['valid', 'expired', false],
]);

test('respuesta accepted incoherente o cancellation_status desconocido exige reconciliación', function (string $status, ?string $cancellationStatus) {
    $invoice = phase65CancelableInvoice();
    fakePhase65Cancellation($invoice, [
        'status' => $status,
        'cancellation_status' => $cancellationStatus,
    ]);

    $result = app(CancelInvoiceWithPacService::class)->cancel(
        $invoice,
        CfdiCancellationMotive::ErrorsWithoutRelation,
    );

    expect($result->pac_reconciliation_required)->toBeTrue();
})->with([
    'accepted pero valid' => ['valid', 'accepted'],
    'none tras solicitar' => ['valid', 'none'],
    'desconocido' => ['valid', 'future_status'],
    'ausente' => ['valid', null],
]);

test('identidad remota distinta y livemode true se rechazan sin corromper identidad local', function (array $overrides, string $exceptionClass) {
    $invoice = phase65CancelableInvoice();
    fakePhase65Cancellation($invoice, $overrides);

    expect(fn () => app(CancelInvoiceWithPacService::class)->cancel(
        $invoice,
        CfdiCancellationMotive::ErrorsWithoutRelation,
    ))->toThrow($exceptionClass);

    $fresh = $invoice->fresh();
    expect($fresh->pac_external_id)->toBe($invoice->pac_external_id)
        ->and($fresh->cfdi_uuid)->toBe($invoice->cfdi_uuid)
        ->and($fresh->pac_reconciliation_required)->toBeTrue();
})->with([
    'remote id' => [['id' => 'inv_wrong'], PacUnexpectedResponseException::class],
    'remote UUID' => [['uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'], PacUnexpectedResponseException::class],
    'LIVE' => [['livemode' => true], PacUnexpectedEnvironmentException::class],
]);

test('errores HTTP se mapean y solo los ambiguos marcan reconciliación requerida', function (int $status, string $exceptionClass, bool $required) {
    $invoice = phase65CancelableInvoice();
    fakePhase65Cancellation($invoice, ['message' => 'Error PAC', 'code' => 'cancel_error'], $status);

    expect(fn () => app(CancelInvoiceWithPacService::class)->cancel(
        $invoice,
        CfdiCancellationMotive::ErrorsWithoutRelation,
    ))->toThrow($exceptionClass);

    $fresh = $invoice->fresh();
    expect($fresh->pac_reconciliation_required)->toBe($required)
        ->and($fresh->pac_status)->toBe('valid')
        ->and($fresh->cancellation_status)->toBe('none')
        ->and($fresh->pac_last_error)->not->toContain('sk_test_PHASE_65_SECRET');
})->with([
    '400' => [400, PacValidationException::class, false],
    '401' => [401, PacAuthenticationException::class, false],
    '409' => [409, PacConflictException::class, true],
    '429' => [429, PacRateLimitException::class, false],
    '500' => [500, PacUnavailableException::class, true],
]);

test('respuesta 200 inesperada es ambigua y conserva metadata fiscal previa', function () {
    $invoice = phase65CancelableInvoice();
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    expect(fn () => app(CancelInvoiceWithPacService::class)->cancel(
        $invoice,
        CfdiCancellationMotive::ErrorsWithoutRelation,
    ))->toThrow(PacUnexpectedResponseException::class);

    expect($invoice->fresh()->pac_reconciliation_required)->toBeTrue()
        ->and($invoice->fresh()->pac_status)->toBe('valid')
        ->and($invoice->fresh()->cfdi_uuid)->toBe($invoice->cfdi_uuid);
});

test('no modifica UUID stamp artifacts folio totals snapshots ni Invoice.status y no ejecuta otras operaciones PAC', function () {
    $invoice = phase65CancelableInvoice();
    $before = $invoice->only([
        'company_id', 'folio', 'status', 'cfdi_uuid',
        'cfdi_xml_path', 'cfdi_pdf_path', 'cfdi_xml_sha256', 'cfdi_pdf_sha256',
        'cfdi_xml_size', 'cfdi_pdf_size', 'cfdi_artifacts_status', 'subtotal',
        'discount_total', 'tax_total', 'total', 'currency', 'client_name',
        'client_rfc', 'client_calle', 'sale_id', 'created_by',
    ]);
    fakePhase65Cancellation($invoice);

    app(CancelInvoiceWithPacService::class)->cancel(
        $invoice,
        CfdiCancellationMotive::ErrorsWithoutRelation,
    );

    $fresh = $invoice->fresh();
    expect($fresh->only(array_keys($before)))->toBe($before)
        ->and($fresh->stamped_at->equalTo($invoice->stamped_at))->toBeTrue();
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'DELETE');
    Http::assertNotSent(fn ($request) => $request->method() !== 'DELETE'
        || str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/xml')
        || str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/pdf')
        || str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/stamp'));
});

test('accepted pending y verifying son idempotentes: nunca repiten DELETE', function (string $pacStatus, string $cancellationStatus) {
    $invoice = phase65CancelableInvoice(pacAttributes: [
        'pac_status' => $pacStatus,
        'cancellation_status' => $cancellationStatus,
    ]);
    Http::fake();

    expect(fn () => app(CancelInvoiceWithPacService::class)->cancel(
        $invoice,
        CfdiCancellationMotive::ErrorsWithoutRelation,
    ))->toThrow(InvoiceCannotBeCancelledException::class);

    Http::assertNothingSent();
})->with([
    'accepted' => ['canceled', 'accepted'],
    'pending' => ['valid', 'pending'],
    'verifying' => ['valid', 'verifying'],
]);

test('rejected y expired son finales y permiten una nueva solicitud explícita', function (string $cancellationStatus) {
    $invoice = phase65CancelableInvoice(pacAttributes: ['cancellation_status' => $cancellationStatus]);
    fakePhase65Cancellation($invoice, ['status' => 'valid', 'cancellation_status' => 'pending']);

    $result = app(CancelInvoiceWithPacService::class)->cancel(
        $invoice,
        CfdiCancellationMotive::ErrorsWithoutRelation,
    );

    Http::assertSentCount(1);
    expect($result->cancellation_status)->toBe('pending');
})->with(['rejected', 'expired']);

test('respuesta concurrente atrasada no sobrescribe una cancelación ya resuelta', function () {
    $invoice = phase65CancelableInvoice();

    Http::fake(function () use ($invoice) {
        Invoice::withoutGlobalScope(CompanyScope::class)
            ->whereKey($invoice->id)
            ->update([
                'pac_status' => 'canceled',
                'cancellation_status' => 'accepted',
                'pac_reconciliation_required' => false,
            ]);

        return Http::response([
            'id' => $invoice->pac_external_id,
            'livemode' => false,
            'status' => 'valid',
            'cancellation_status' => 'pending',
            'uuid' => $invoice->cfdi_uuid,
        ], 200);
    });

    $result = app(CancelInvoiceWithPacService::class)->cancel(
        $invoice,
        CfdiCancellationMotive::ErrorsWithoutRelation,
    );

    expect($result->pac_status)->toBe('canceled')
        ->and($result->cancellation_status)->toBe('accepted')
        ->and($result->pac_reconciliation_required)->toBeFalse();
});

test('logs de cancelación están sanitizados', function () {
    Log::spy();
    $invoice = phase65CancelableInvoice();
    $substitution = '12345678-1234-1234-1234-123456789abc';
    fakePhase65Cancellation($invoice);

    app(CancelInvoiceWithPacService::class)->cancel(
        $invoice,
        CfdiCancellationMotive::ErrorsWithRelation,
        $substitution,
    );

    Log::shouldHaveReceived('info')->withArgs(function (string $event, array $context) use ($invoice, $substitution) {
        $serialized = json_encode($context);

        expect($event)->toBe('billing.invoice.pac_cancellation')
            ->and($serialized)->not->toContain('sk_test_PHASE_65_SECRET')
            ->and($serialized)->not->toContain($invoice->pac_external_id)
            ->and($serialized)->not->toContain($invoice->cfdi_uuid)
            ->and($serialized)->not->toContain($substitution)
            ->and($serialized)->not->toContain('CANCELSECRET010101AAA')
            ->and($context)->not->toHaveKey('pac_response');

        return true;
    });
});

test('InvoiceWorkflow delega la cancelación fiscal y conserva status interno', function () {
    $invoice = phase65CancelableInvoice();
    fakePhase65Cancellation($invoice);

    $result = app(InvoiceWorkflow::class)->cancelWithPac(
        $invoice,
        CfdiCancellationMotive::OperationNotPerformed,
    );

    expect($result->cancellation_status)->toBe('accepted')
        ->and($result->status)->toBe(InvoiceStatus::Issued);
    Http::assertSentCount(1);
});
