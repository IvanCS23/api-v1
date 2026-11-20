<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\PaymentReceiptDeadline;
use Carbon\CarbonImmutable;

/**
 * Fase 6.35 (D9): plazo del REP = quinto día natural del mes siguiente al
 * del pago (regla 2.7.1.32 RMF 2026). Aviso, no bloqueo.
 */
test('el plazo es el día 5 del mes siguiente al del pago', function (string $paid, string $deadline) {
    expect(PaymentReceiptDeadline::for(CarbonImmutable::parse($paid))->toDateString())->toBe($deadline);
})->with([
    'a mitad de mes' => ['2026-11-18 10:30:00', '2026-12-05'],
    'primer día' => ['2026-11-01 00:00:00', '2026-12-05'],
    'último día' => ['2026-11-30 23:59:59', '2026-12-05'],
    'diciembre cruza el año' => ['2026-12-31 12:00:00', '2027-01-05'],
    'enero 31 no se desborda' => ['2027-01-31 12:00:00', '2027-02-05'],
]);

test('estado del plazo de un borrador: a tiempo, por vencer y vencido; nada si ya tiene REP', function () {
    $draft = Payment::factory()->make(['payment_date' => '2026-11-18 10:30:00']);
    $issued = Payment::factory()->issued()->make(['payment_date' => '2026-11-18 10:30:00']);

    expect(PaymentReceiptDeadline::status($draft, CarbonImmutable::parse('2026-12-01')))->toBe('on_time')
        ->and(PaymentReceiptDeadline::status($draft, CarbonImmutable::parse('2026-12-02')))->toBe('due_soon')
        ->and(PaymentReceiptDeadline::status($draft, CarbonImmutable::parse('2026-12-05')))->toBe('due_soon')
        ->and(PaymentReceiptDeadline::status($draft, CarbonImmutable::parse('2026-12-06')))->toBe('overdue')
        ->and(PaymentReceiptDeadline::status($issued, CarbonImmutable::parse('2026-12-06')))->toBeNull()
        ->and(PaymentReceiptDeadline::isLate($draft, CarbonImmutable::parse('2026-12-05')))->toBeFalse()
        ->and(PaymentReceiptDeadline::isLate($draft, CarbonImmutable::parse('2026-12-06')))->toBeTrue();
});

test('la API expone el plazo y su estado (aviso, sin bloquear)', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 18:00:00', 'UTC'));
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => UserRole::Accountant]);
    $payment = Payment::factory()->create(['company_id' => $company->id, 'payment_date' => '2026-11-18 10:30:00']);

    $this->actingAs($user, 'api')->getJson("/api/payments/{$payment->id}")
        ->assertOk()
        ->assertJsonPath('rep_deadline', '2026-12-05')
        ->assertJsonPath('rep_deadline_status', 'overdue');
});
