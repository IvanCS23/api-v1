<?php

use App\Enums\QuoteStatus;
use App\Models\Company;
use App\Models\Quote;
use App\Models\User;

test('send transiciona Draft a Sent', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'status' => QuoteStatus::Draft]);

    $response = $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/send");

    $response->assertOk()->assertJsonPath('status', 'sent');
});

test('approve transiciona Sent a Approved y fija approved_at', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'status' => QuoteStatus::Sent]);

    expect($quote->fresh()->approved_at)->toBeNull();

    $response = $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/approve");

    $response->assertOk()->assertJsonPath('status', 'approved');
    expect($quote->fresh()->approved_at)->not->toBeNull();
});

test('reject transiciona Draft o Sent a Rejected', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    $draft = Quote::factory()->create(['company_id' => $company->id, 'status' => QuoteStatus::Draft]);
    $this->actingAs($user, 'api')->postJson("/api/quotes/{$draft->id}/reject")->assertOk()->assertJsonPath('status', 'rejected');

    $sent = Quote::factory()->create(['company_id' => $company->id, 'status' => QuoteStatus::Sent]);
    $this->actingAs($user, 'api')->postJson("/api/quotes/{$sent->id}/reject")->assertOk()->assertJsonPath('status', 'rejected');
});

test('expire transiciona Sent a Expired', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'status' => QuoteStatus::Sent]);

    $response = $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/expire");

    $response->assertOk()->assertJsonPath('status', 'expired');
});

test('una cotización aprobada no puede rechazarse', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'status' => QuoteStatus::Approved]);

    $response = $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/reject");

    $response->assertStatus(422);
    expect($quote->fresh()->status)->toBe(QuoteStatus::Approved);
});

test('dos llamadas consecutivas a approve no generan efectos dobles y approved_at no cambia en el reintento fallido', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'status' => QuoteStatus::Sent]);

    $first = $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/approve");
    $first->assertOk()->assertJsonPath('status', 'approved');
    $approvedAtAfterFirst = $quote->fresh()->approved_at;
    expect($approvedAtAfterFirst)->not->toBeNull();

    $second = $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/approve");
    $second->assertStatus(422);

    expect($quote->fresh()->status)->toBe(QuoteStatus::Approved)
        ->and($quote->fresh()->approved_at->equalTo($approvedAtAfterFirst))->toBeTrue();
});

test('transiciones inválidas de Quote son rechazadas con un error controlado', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    // Draft no puede expirar ni aprobarse directamente (falta pasar por Sent).
    $draft = Quote::factory()->create(['company_id' => $company->id, 'status' => QuoteStatus::Draft]);
    $this->actingAs($user, 'api')->postJson("/api/quotes/{$draft->id}/expire")->assertStatus(422);
    $this->actingAs($user, 'api')->postJson("/api/quotes/{$draft->id}/approve")->assertStatus(422);

    // Approved/Rejected/Expired/Converted son terminales para este workflow.
    foreach ([QuoteStatus::Approved, QuoteStatus::Rejected, QuoteStatus::Expired, QuoteStatus::Converted] as $status) {
        $quote = Quote::factory()->create(['company_id' => $company->id, 'status' => $status]);
        $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/send")->assertStatus(422);
        $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/approve")->assertStatus(422);
        $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/reject")->assertStatus(422);
        $this->actingAs($user, 'api')->postJson("/api/quotes/{$quote->id}/expire")->assertStatus(422);
    }
});

test('Converted solo se alcanza a través de QuoteToSaleConverter, nunca vía QuoteWorkflow', function () {
    $workflow = app(\App\Services\Sales\QuoteWorkflow::class);

    // QuoteWorkflow no expone ningún método para llegar a Converted:
    // ni send(), approve(), reject() ni expire() lo permiten como destino.
    expect(method_exists($workflow, 'convert'))->toBeFalse();

    $company = Company::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id, 'status' => QuoteStatus::Approved]);

    // approve() exige venir de Sent; una Approved ya no puede "reintentarse"
    // hacia Converted a través del workflow bajo ningún método existente.
    expect(fn () => $workflow->approve($quote))
        ->toThrow(\App\Exceptions\WorkflowTransitionException::class);
});

test('el workflow no puede bloquear ni modificar una Quote de otra empresa aunque la instancia llegue con un company_id manipulado', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $companyA->id, 'status' => QuoteStatus::Draft]);

    // Misma idea que el equivalente en SaleWorkflowTest: instancia con el
    // id real de la empresa A pero company_id forzado a la empresa B.
    $tamperedQuote = Quote::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->find($quote->id);
    $tamperedQuote->company_id = $companyB->id;

    $workflow = app(\App\Services\Sales\QuoteWorkflow::class);

    expect(fn () => $workflow->send($tamperedQuote))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    expect($quote->fresh()->status)->toBe(QuoteStatus::Draft);
});
