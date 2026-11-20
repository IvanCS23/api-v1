<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemTax;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\Payments\InvoiceBalance;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fase 6.35 (4): emisión del REP con HTTP simulado (nunca el PAC real).
 */
beforeEach(function () {
    facturapiOrganizationsFromConfigKey();
    config([
        'services.facturapi.base_url' => 'https://example-pac.test/v2',
        'services.facturapi.test_key' => 'sk_test_PHASE_635',
    ]);

    $this->company = Company::factory()->create();
    $this->client = Client::factory()->create(['company_id' => $this->company->id, 'rfc' => 'EKU9003173C9', 'name' => 'ESCUELA KEMPER URGATE', 'regimen_fiscal' => '601', 'codigo_postal' => '26015']);
    $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => UserRole::Accountant]);
    $this->invoice = Invoice::factory()->create([
        'company_id' => $this->company->id,
        'client_id' => $this->client->id,
        'folio' => 'FAC-00000042',
        'status' => 'issued',
        'payment_method' => 'PPD',
        'payment_form' => '99',
        'currency' => 'MXN',
        'total' => '1160',
        'cfdi_uuid' => 'aaaaaaaa-1111-2222-3333-444444444444',
        'pac_external_id' => 'inv_ppd_1',
        'pac_environment' => 'test',
        'issued_at' => now()->subDays(5),
    ]);
    $item = InvoiceItem::factory()->create(['company_id' => $this->company->id, 'invoice_id' => $this->invoice->id, 'subtotal' => 1000, 'tax_total' => 160]);
    $tax = new InvoiceItemTax(['invoice_item_id' => $item->id, 'tax' => '002', 'kind' => 'traslado', 'factor' => 'tasa', 'rate' => '0.16', 'position' => 0, 'base' => 1000, 'amount' => 160, 'amount_rounded' => 160]);
    $tax->company_id = $this->company->id;
    $tax->save();
});

function p635Draft(object $test, string $amount, string $date = '2026-11-18 10:30:00'): Payment
{
    $payment = Payment::factory()->create([
        'company_id' => $test->company->id,
        'client_id' => $test->client->id,
        'folio' => 'PAG-0000000'.(Payment::withoutGlobalScopes()->count() + 1),
        'amount' => $amount,
        'payment_date' => $date,
        'payment_form' => '03',
        'operation_number' => 'SPEI-9',
    ]);
    PaymentAllocation::factory()->create(['company_id' => $test->company->id, 'payment_id' => $payment->id, 'invoice_id' => $test->invoice->id, 'amount' => $amount]);

    return $payment;
}

/** Facturapi simulado: payment-summary consistente con el saldo y POST /invoices válido. */
function p635FakePac(array $summary = [], array|int|null $create = null, array &$posted = []): void
{
    Http::fake(function (Request $request) use ($summary, $create, &$posted) {
        if ($request->method() === 'GET' && str_contains($request->url(), '/payment-summary')) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $amount = (float) $query['amount'];
            $issued = Payment::withoutGlobalScopes()->where('status', 'issued')->sum('amount');

            return Http::response(array_replace([
                'uuid' => 'AAAAAAAA-1111-2222-3333-444444444444',
                'series' => 'FAC',
                'folio_number' => 42,
                'installment' => Payment::withoutGlobalScopes()->where('status', 'issued')->count() + 1,
                'last_balance' => 1160 - $issued,
                'total' => 1160,
                'currency' => 'MXN',
                'amount' => $amount,
                'taxes' => [['base' => round($amount / 1.16, 6), 'type' => 'IVA', 'rate' => 0.16, 'factor' => 'Tasa', 'withholding' => false]],
            ], $summary));
        }

        if ($request->method() === 'POST' && str_ends_with($request->url(), '/invoices')) {
            $posted[] = $request->data();
            if (is_int($create)) {
                return Http::response(['message' => 'Rechazado por el SAT', 'code' => 'validation'], $create);
            }

            return Http::response($create ?? [
                'id' => 'rep_'.count($posted),
                'status' => 'valid',
                'uuid' => 'bbbbbbbb-0000-0000-0000-00000000000'.count($posted),
                'livemode' => false,
                'stamp' => ['date' => '2026-11-18T11:00:00'],
            ]);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/invoices?')) {
            return Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => 0, 'data' => []]);
        }

        if ($request->method() === 'GET' && str_ends_with($request->url(), '/xml')) {
            return Http::response('<cfdi:Comprobante TipoDeComprobante="P"/>', 200, ['Content-Type' => 'application/xml']);
        }

        return Http::response(['message' => 'ruta no simulada: '.$request->url()], 599);
    });
}

test('emite el REP: payload tipo P con serie PAG y los valores locales; guarda parcialidad, saldos e impuestos', function () {
    $posted = [];
    p635FakePac(posted: $posted);
    $payment = p635Draft($this, '580');

    $this->actingAs($this->user, 'api')->postJson("/api/payments/{$payment->id}/issue")
        ->assertOk()
        ->assertJsonPath('status', 'issued')
        ->assertJsonPath('cfdi_uuid', 'bbbbbbbb-0000-0000-0000-000000000001')
        ->assertJsonPath('allocations.0.installment', 1)
        ->assertJsonPath('allocations.0.previous_balance', '1160.000000')
        ->assertJsonPath('allocations.0.remaining_balance', '580.000000')
        ->assertJsonPath('allocations.0.taxes.0.base', '500.000000')
        ->assertJsonPath('allocations.0.taxes.0.amount', '80.000000');

    expect($posted)->toHaveCount(1);
    $body = $posted[0];
    $data = $body['complements'][0]['data'][0];
    expect($body['type'])->toBe('P')
        ->and($body['series'])->toBe('PAG')
        ->and($body['folio_number'])->toBe(1)
        ->and($body['external_id'])->toBe("erp-payment:{$this->company->id}:{$payment->id}:v1")
        ->and($body['idempotency_key'])->toBe($body['external_id'])
        ->and($body['customer'])->toBe(['legal_name' => 'ESCUELA KEMPER URGATE', 'tax_id' => 'EKU9003173C9', 'tax_system' => '601', 'address' => ['zip' => '26015']])
        ->and($body['complements'][0]['type'])->toBe('pago')
        ->and($data['payment_form'])->toBe('03')
        ->and($data['date'])->toBe('2026-11-18T10:30:00')
        ->and($data['numOperacion'])->toBe('SPEI-9')
        ->and($data['related_documents'][0])->toMatchArray([
            'uuid' => 'aaaaaaaa-1111-2222-3333-444444444444',
            'amount' => 580,
            'installment' => 1,
            'last_balance' => 1160,
            'taxability' => '02',
            'series' => 'FAC',
            'folio_number' => 42,
            'taxes' => [['base' => 500, 'type' => 'IVA', 'rate' => 0.16]],
        ]);

    $fresh = Payment::withoutGlobalScopes()->find($payment->id);
    expect($fresh->pac_external_id)->toBe('rep_1')
        ->and($fresh->pac_issue_status)->toBe('issued')
        ->and($fresh->pac_environment)->toBe('test')
        ->and($fresh->client_rfc)->toBe('EKU9003173C9')
        ->and((new InvoiceBalance)->fiscal($this->invoice))->toBe('580.000000')
        ->and(AuditLog::where('action', 'payment_issued')->first()->metadata['documents'][0]['payment_summary']['installment'])->toBe(1);
});

test('segundo REP de la misma factura: parcialidad 2 y saldo anterior 580', function () {
    $posted = [];
    p635FakePac(posted: $posted);
    $first = p635Draft($this, '580');
    $this->actingAs($this->user, 'api')->postJson("/api/payments/{$first->id}/issue")->assertOk();
    $second = p635Draft($this, '300');

    $this->actingAs($this->user, 'api')->postJson("/api/payments/{$second->id}/issue")
        ->assertOk()
        ->assertJsonPath('allocations.0.installment', 2)
        ->assertJsonPath('allocations.0.previous_balance', '580.000000')
        ->assertJsonPath('allocations.0.remaining_balance', '280.000000');

    expect($posted[1]['folio_number'])->toBe(2)
        ->and($posted[1]['complements'][0]['data'][0]['related_documents'][0]['last_balance'])->toEqual(580);
});

test('D3: si payment-summary no coincide no se emite', function (array $summary, string $field) {
    $posted = [];
    p635FakePac($summary, posted: $posted);
    $payment = p635Draft($this, '580');

    $this->actingAs($this->user, 'api')->postJson("/api/payments/{$payment->id}/issue")
        ->assertStatus(422)
        ->assertJsonPath('code', 'PAYMENT_SUMMARY_MISMATCH')
        ->assertJsonPath('differences.0.field', $field)
        ->assertJsonPath('differences.0.invoice', 'FAC-00000042');

    $fresh = Payment::withoutGlobalScopes()->find($payment->id);
    expect($posted)->toBe([])
        ->and($fresh->status->value)->toBe('draft')
        ->and($fresh->pac_issue_status)->toBe('failed')
        ->and($fresh->pac_last_error)->toContain('PaymentSummaryMismatchException');
})->with([
    'parcialidad' => [['installment' => 2], 'installment'],
    'saldo anterior' => [['last_balance' => 1000], 'last_balance'],
    'base del impuesto' => [['taxes' => [['base' => 499.99, 'type' => 'IVA', 'rate' => 0.16]]], 'taxes.IVA|traslado|Tasa|0.160000.base'],
    'grupos de impuestos' => [['taxes' => []], 'taxes'],
    'uuid' => [['uuid' => 'cccccccc-1111-2222-3333-444444444444'], 'uuid'],
]);

test('un rechazo del PAC deja el pago en borrador (failed) y responde 422', function () {
    $posted = [];
    p635FakePac(create: 400, posted: $posted);
    $payment = p635Draft($this, '580');

    $this->actingAs($this->user, 'api')->postJson("/api/payments/{$payment->id}/issue")->assertStatus(422);

    $fresh = Payment::withoutGlobalScopes()->find($payment->id);
    expect($fresh->status->value)->toBe('draft')
        ->and($fresh->pac_issue_status)->toBe('failed')
        ->and($fresh->cfdi_uuid)->toBeNull()
        ->and(PaymentAllocation::withoutGlobalScopes()->first()->installment)->toBeNull();
});

test('respuesta ambigua: queda unknown y el reintento busca por external_id antes de reenviar', function () {
    $payment = p635Draft($this, '580');
    $retry = false;
    $calls = [];
    Http::fake(function (Request $request) use (&$retry, &$calls, $payment) {
        if ($retry) {
            $calls[] = $request->method().' '.$request->url();

            return Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => 1, 'data' => [[
                'id' => 'rep_found', 'status' => 'valid', 'uuid' => 'dddddddd-0000-0000-0000-000000000001', 'livemode' => false,
                'external_id' => "erp-payment:{$payment->company_id}:{$payment->id}:v1",
            ]]]);
        }
        if (str_contains($request->url(), '/payment-summary')) {
            return Http::response(['uuid' => 'aaaaaaaa-1111-2222-3333-444444444444', 'installment' => 1, 'last_balance' => 1160, 'total' => 1160, 'currency' => 'MXN', 'taxes' => [['base' => 500, 'type' => 'IVA', 'rate' => 0.16]]]);
        }
        throw new ConnectionException('timeout');
    });

    $this->actingAs($this->user, 'api')->postJson("/api/payments/{$payment->id}/issue")->assertStatus(503);
    expect(Payment::withoutGlobalScopes()->find($payment->id)->pac_issue_status)->toBe('unknown');

    $retry = true;

    $this->actingAs($this->user, 'api')->postJson("/api/payments/{$payment->id}/issue")
        ->assertOk()->assertJsonPath('status', 'issued')->assertJsonPath('cfdi_uuid', 'dddddddd-0000-0000-0000-000000000001');

    expect($calls)->toHaveCount(1)
        ->and($calls[0])->toContain('GET https://example-pac.test/v2/invoices?external_id=');
});

test('no listo: factura sin identificador del PAC (sin llamar al PAC)', function () {
    Http::fake();
    $this->invoice->forceFill(['pac_external_id' => null])->save();
    $payment = p635Draft($this, '580');

    $this->actingAs($this->user, 'api')->postJson("/api/payments/{$payment->id}/issue")
        ->assertStatus(422)->assertJsonPath('code', 'PAYMENT_NOT_READY')
        ->assertJsonPath('errors.0', 'La factura FAC-00000042 no tiene identificador del PAC.');

    Http::assertNothingSent();
});

test('un pago ya emitido no se vuelve a emitir; una emisión en curso responde 409', function () {
    Http::fake();
    $issued = Payment::factory()->issued()->create(['company_id' => $this->company->id, 'client_id' => $this->client->id]);
    $this->actingAs($this->user, 'api')->postJson("/api/payments/{$issued->id}/issue")->assertOk()->assertJsonPath('status', 'issued');

    $payment = p635Draft($this, '580');
    $payment->forceFill(['pac_issue_status' => 'in_progress'])->save();
    $this->actingAs($this->user, 'api')->postJson("/api/payments/{$payment->id}/issue")
        ->assertStatus(409)->assertJsonPath('code', 'PAYMENT_ISSUANCE_IN_PROGRESS');

    Http::assertNothingSent();
});

test('sólo Admin y Contador emiten; XML del REP se descarga del PAC', function () {
    p635FakePac();
    $payment = p635Draft($this, '580');
    $sales = User::factory()->create(['company_id' => $this->company->id, 'role' => UserRole::Sales]);

    $this->actingAs($sales, 'api')->postJson("/api/payments/{$payment->id}/issue")->assertForbidden();
    $this->actingAs($this->user, 'api')->getJson("/api/payments/{$payment->id}/cfdi/xml")->assertNotFound()->assertJsonPath('code', 'PAYMENT_NOT_ISSUED');

    $this->actingAs($this->user, 'api')->postJson("/api/payments/{$payment->id}/issue")->assertOk();
    $response = $this->actingAs($this->user, 'api')->get("/api/payments/{$payment->id}/cfdi/xml")->assertOk();

    expect($response->getContent())->toContain('TipoDeComprobante="P"')
        ->and($response->headers->get('Content-Disposition'))->toContain("{$payment->folio}.xml");
});
