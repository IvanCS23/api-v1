<?php

use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceAlreadyIssuedException;
use App\Exceptions\InvoiceIssuanceInProgressException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Scopes\CompanyScope;
use App\Services\Billing\IssueInvoiceService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Límite honesto de este archivo (punto 11 del encargo): la suite de
 * pruebas corre contra SQLite `:memory:` con una única conexión de
 * proceso (ver phpunit.xml) — no hay dos procesos/hilos reales
 * compitiendo por el mismo lockForUpdate() al mismo tiempo, así que
 * estas pruebas NO demuestran concurrencia real de dos peticiones HTTP
 * simultáneas. Lo que SÍ demuestran, con precisión, es la DECISIÓN que
 * toma IssueInvoiceService al encontrar bajo lock un estado que una
 * ejecución concurrente real habría dejado (`pending`,
 * `reconciliation_required`, o un resultado ya persistido) — exactamente
 * lo que vería la segunda transacción si la primera ya hizo commit antes
 * de que la segunda adquiriera el lock. Verificar la exclusión mutua
 * real bajo carga (dos conexiones simultáneas peleando por el mismo
 * lockForUpdate() en MySQL/MariaDB) requiere una prueba de integración
 * aparte contra ese motor — fuera del alcance de esta suite basada en
 * SQLite (ver reporte de entrega, sección de riesgos/limitaciones).
 */
beforeEach(function () {
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_CONCURRENCY',
    ]);
});

function issuedInvoiceForConcurrencyTest(Company $company): Invoice
{
    $invoice = Invoice::factory()->create(['company_id' => $company->id, 'status' => InvoiceStatus::Issued]);
    InvoiceItem::factory()->create(['company_id' => $company->id, 'invoice_id' => $invoice->id]);

    app(CurrentTenant::class)->set($company->id);

    return $invoice->fresh(['items']);
}

test('si otra ejecución ya dejó la reserva en pending, una nueva llamada lanza InvoiceIssuanceInProgressException sin llamar al PAC', function () {
    $company = Company::factory()->create();
    $invoice = issuedInvoiceForConcurrencyTest($company);

    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_idempotency_key' => "erp-invoice:{$company->id}:{$invoice->id}:v1",
        'pac_issue_status' => 'pending',
        'pac_issue_started_at' => now(),
        'pac_issue_attempts' => 1,
    ])->save();

    Http::fake();

    expect(fn () => app(IssueInvoiceService::class)->issue($invoice->fresh()))
        ->toThrow(InvoiceIssuanceInProgressException::class);

    Http::assertNothingSent();
    // El intento NO se incrementa: la segunda llamada nunca llega a
    // reservar, se detiene en la comprobación previa.
    expect($invoice->fresh()->pac_issue_attempts)->toBe(1);
});

test('si otra ejecución dejó reconciliation_required, una nueva llamada tampoco reintenta automáticamente: lanza InvoiceIssuanceInProgressException', function () {
    $company = Company::factory()->create();
    $invoice = issuedInvoiceForConcurrencyTest($company);

    $invoice->forceFill([
        'pac_provider' => 'facturapi',
        'pac_idempotency_key' => "erp-invoice:{$company->id}:{$invoice->id}:v1",
        'pac_issue_status' => 'reconciliation_required',
        'pac_reconciliation_required' => true,
        'pac_issue_attempts' => 1,
    ])->save();

    Http::fake();

    expect(fn () => app(IssueInvoiceService::class)->issue($invoice->fresh()))
        ->toThrow(InvoiceIssuanceInProgressException::class);

    Http::assertNothingSent();
});

test('una respuesta atrasada nunca sobrescribe una emisión ya finalizada: si otra ejecución ya persistió el resultado antes de nuestro commit, se lanza InvoiceAlreadyIssuedException', function () {
    $company = Company::factory()->create();
    $invoice = issuedInvoiceForConcurrencyTest($company);

    Http::fake(function () use ($invoice) {
        // Simula que otra ejecución (que ganó la carrera) ya completó y
        // persistió su propia emisión justo después de que nosotros
        // llamamos al PAC pero antes de que alcancemos nuestra
        // transacción de persistencia final.
        Invoice::withoutGlobalScope(CompanyScope::class)->whereKey($invoice->id)->update([
            'pac_provider' => 'facturapi',
            'pac_external_id' => 'inv_winner',
            'cfdi_uuid' => 'DDDDDDDD-1111-2222-3333-444444444444',
            'pac_issue_status' => 'succeeded',
        ]);

        return Http::response(['id' => 'inv_loser_late_response', 'status' => 'valid'], 200);
    });

    expect(fn () => app(IssueInvoiceService::class)->issue($invoice))
        ->toThrow(InvoiceAlreadyIssuedException::class);

    // El resultado persistido es el del "ganador", nunca el de la
    // respuesta atrasada de nuestra propia llamada.
    expect($invoice->fresh()->pac_external_id)->toBe('inv_winner');
});

test('el lock de la reserva se libera antes de la llamada HTTP: la reserva ya está comprometida (commit) cuando el PAC responde', function () {
    $company = Company::factory()->create();
    $invoice = issuedInvoiceForConcurrencyTest($company);

    // Baseline, no un 0 fijo: RefreshDatabase envuelve cada prueba en su
    // propia transacción externa (para revertirla al final sin
    // remigrar), así que el nivel de transacción nunca es realmente 0
    // dentro de una prueba — lo relevante es que la llamada HTTP ocurra
    // exactamente al mismo nivel que antes de reservar, es decir, fuera
    // de cualquier transacción ADICIONAL abierta por IssueInvoiceService.
    $baselineTransactionLevel = DB::transactionLevel();

    $transactionLevelDuringCall = null;
    $pendingStateVisibleDuringCall = null;

    Http::fake(function () use (&$transactionLevelDuringCall, &$pendingStateVisibleDuringCall, $invoice) {
        $transactionLevelDuringCall = DB::transactionLevel();

        // Si el lock de la reserva siguiera abierto, esta lectura por
        // fuera de esa transacción igualmente vería el estado ya
        // comprometido (commit), porque no hay ninguna transacción
        // envolviendo la llamada HTTP.
        $pendingStateVisibleDuringCall = Invoice::withoutGlobalScope(CompanyScope::class)
            ->find($invoice->id)
            ->pac_issue_status;

        return Http::response(['id' => 'inv_lock_check', 'status' => 'valid'], 200);
    });

    app(IssueInvoiceService::class)->issue($invoice);

    expect($transactionLevelDuringCall)->toBe($baselineTransactionLevel)
        ->and($pendingStateVisibleDuringCall)->toBe('pending');
});
