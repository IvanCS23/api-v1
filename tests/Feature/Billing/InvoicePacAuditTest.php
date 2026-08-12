<?php

use App\Enums\InvoicePacEventType;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoicePacEvent;
use App\Services\Billing\InvoicePacAuditService;
use App\Support\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config(['services.facturapi.test_key' => 'sk_test_AUDIT_SECRET_KEY']);
});

function invoiceForPacAudit(array $overrides = []): Invoice
{
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->create([
        'company_id' => $company->id,
        'status' => InvoiceStatus::Issued,
    ]);
    $invoice->forceFill(array_merge([
        'pac_provider' => 'facturapi',
        'pac_external_id' => 'inv_audit_'.$invoice->id,
        'cfdi_uuid' => '96013e83-154b-4153-8e61-c38b8966e560',
        'pac_status' => 'valid',
        'cancellation_status' => 'none',
        'pac_issue_status' => 'succeeded',
    ], $overrides))->save();
    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh();
}

test('migracion crea tabla y tres indices tenant sin modificar invoices', function () {
    expect(Artisan::call('migrate', ['--pretend' => true]))->toBe(0)
        ->and(Schema::hasColumns('invoice_pac_events', [
            'id', 'company_id', 'invoice_id', 'event_type', 'pac_provider',
            'pac_external_id', 'cfdi_uuid', 'pac_status', 'cancellation_status',
            'pac_issue_status', 'pac_code', 'occurred_at', 'context', 'created_at',
        ]))->toBeTrue();

    $indexes = collect(Schema::getIndexes('invoice_pac_events'))->pluck('name');
    expect($indexes)->toContain(
        'erp_invoice_pac_events_company_invoice_index',
        'erp_invoice_pac_events_company_type_index',
        'erp_invoice_pac_events_company_occurred_index',
    );
});

test('append captura snapshot enum tenant timestamps y no altera Invoice', function () {
    $invoice = invoiceForPacAudit();
    $before = $invoice->getRawOriginal();

    $event = app(InvoicePacAuditService::class)->append(
        $invoice,
        InvoicePacEventType::IssueSucceeded,
        ['attempt' => 1, 'elapsed_ms' => 240],
        'pac_ok',
    );

    expect($event->company_id)->toBe($invoice->company_id)
        ->and($event->invoice_id)->toBe($invoice->id)
        ->and($event->event_type)->toBe(InvoicePacEventType::IssueSucceeded)
        ->and($event->pac_provider)->toBe('facturapi')
        ->and($event->pac_external_id)->toBe($invoice->pac_external_id)
        ->and($event->cfdi_uuid)->toBe($invoice->cfdi_uuid)
        ->and($event->pac_status)->toBe('valid')
        ->and($event->cancellation_status)->toBe('none')
        ->and($event->pac_issue_status)->toBe('succeeded')
        ->and($event->pac_code)->toBe('pac_ok')
        ->and($event->occurred_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($event->created_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($event->context)->toBe(['attempt' => 1, 'elapsed_ms' => 240])
        ->and($invoice->fresh()->getRawOriginal())->toBe($before);
});

test('context se cifra y queda oculto de serializacion', function () {
    $invoice = invoiceForPacAudit();
    $event = app(InvoicePacAuditService::class)->append($invoice, InvoicePacEventType::DraftCreated, [
        'attempt' => 7,
        'note' => 'metadata operativa confidencial',
    ]);

    $raw = DB::table('invoice_pac_events')->where('id', $event->id)->value('context');
    expect($raw)->not->toContain('metadata operativa confidencial')
        ->and($event->fresh()->context['note'])->toBe('metadata operativa confidencial')
        ->and($event->toArray())->not->toHaveKey('context');
});

test('sanitiza secretos Authorization documentos y UUID completos en valores', function () {
    $invoice = invoiceForPacAudit();
    $event = app(InvoicePacAuditService::class)->append($invoice, InvoicePacEventType::ArtifactsFailed, [
        'note' => 'Bearer sk_test_AUDIT_SECRET_KEY UUID 96013e83-154b-4153-8e61-c38b8966e560',
        'document_preview' => '<Acuse><Rfc>SECRETO</Rfc></Acuse>',
        'file_preview' => '%PDF-1.7 secreto',
    ]);

    $serialized = json_encode($event->context);
    expect($serialized)->not->toContain('sk_test_AUDIT_SECRET_KEY')
        ->not->toContain('Bearer')
        ->not->toContain('96013e83-154b-4153-8e61-c38b8966e560')
        ->not->toContain('<Acuse')
        ->not->toContain('%PDF-')
        ->and($event->context['note'])->toContain('96013e83...e560')
        ->and($event->context['document_preview'])->toBe('[redacted_document_content]')
        ->and($event->context['file_preview'])->toBe('[redacted_document_content]');
});

test('rechaza keys de raw response payload credenciales y datos fiscales', function (string $key) {
    $invoice = invoiceForPacAudit();

    expect(fn () => app(InvoicePacAuditService::class)->append(
        $invoice,
        InvoicePacEventType::IssueFailed,
        [$key => 'contenido prohibido'],
    ))->toThrow(InvalidArgumentException::class);

    expect(InvoicePacEvent::withoutGlobalScopes()->count())->toBe(0);
})->with(['Authorization', 'api_key', 'raw_response', 'payload', 'xml', 'pdf', 'client_rfc', 'domicilio']);

test('appendSafely no propaga fallo de auditoria y emite incidente sanitizado', function () {
    Log::spy();
    $invoice = invoiceForPacAudit();

    $result = app(InvoicePacAuditService::class)->appendSafely(
        $invoice,
        InvoicePacEventType::IssueFailed,
        ['raw_response' => 'no permitido'],
    );

    expect($result)->toBeNull();
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $event, array $context): bool => $event === 'billing.invoice.pac_audit_append_failed'
            && $context['invoice_id'] === $invoice->id
            && $context['event_type'] === 'issue_failed'
            && ! array_key_exists('raw_response', $context))
        ->once();
});

test('relacion Invoice pacEvents es tenant scoped y no eager-load por default', function () {
    $invoice = invoiceForPacAudit();
    app(InvoicePacAuditService::class)->append($invoice, InvoicePacEventType::DraftCreated);
    app(InvoicePacAuditService::class)->append($invoice, InvoicePacEventType::DraftSynced);

    expect($invoice->fresh()->relationLoaded('pacEvents'))->toBeFalse()
        ->and($invoice->pacEvents()->count())->toBe(2)
        ->and($invoice->pacEvents()->pluck('event_type')->all())->toBe([
            InvoicePacEventType::DraftCreated,
            InvoicePacEventType::DraftSynced,
        ]);
});

test('multiples eventos del mismo tipo estan permitidos y nunca actualizan el anterior', function () {
    $invoice = invoiceForPacAudit();
    $first = app(InvoicePacAuditService::class)->append($invoice, InvoicePacEventType::Reconciled, ['attempt' => 1]);
    $original = $first->getRawOriginal();
    $second = app(InvoicePacAuditService::class)->append($invoice, InvoicePacEventType::Reconciled, ['attempt' => 2]);

    expect($second->id)->not->toBe($first->id)
        ->and(InvoicePacEvent::query()->count())->toBe(2)
        ->and($first->fresh()->getRawOriginal())->toEqual($original);
});

test('modelo bloquea update delete y mass assignment', function () {
    $invoice = invoiceForPacAudit();
    $event = app(InvoicePacAuditService::class)->append($invoice, InvoicePacEventType::IssueAttempted);

    expect(fn () => new InvoicePacEvent(['event_type' => InvoicePacEventType::IssueFailed->value]))
        ->toThrow(MassAssignmentException::class);

    expect(fn () => $event->forceFill(['pac_status' => 'tampered'])->save())
        ->toThrow(LogicException::class, 'inmutables')
        ->and(fn () => $event->delete())
        ->toThrow(LogicException::class, 'append-only')
        ->and($event->fresh()->pac_status)->toBe('valid');
});

test('cross tenant falla cerrado antes de crear evento', function () {
    $invoice = invoiceForPacAudit();
    $otherCompany = Company::factory()->create();
    app(CurrentTenant::class)->set($otherCompany->id);

    expect(fn () => app(InvoicePacAuditService::class)->append($invoice, InvoicePacEventType::IssueAttempted))
        ->toThrow(ModelNotFoundException::class);

    expect(InvoicePacEvent::withoutGlobalScopes()->count())->toBe(0);
});
