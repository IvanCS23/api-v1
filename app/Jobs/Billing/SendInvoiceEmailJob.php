<?php

namespace App\Jobs\Billing;

use App\Enums\InvoicePacEventType;
use App\Exceptions\Billing\InvoiceEmailException;
use App\Exceptions\Billing\PacConfigurationException;
use App\Models\Invoice;
use App\Models\InvoicePacEvent;
use App\Models\Scopes\CompanyScope;
use App\Services\Billing\SendInvoiceEmailService;
use App\Support\Billing\PacCredentialGuard;
use App\Support\Billing\PacEnvironment;
use App\Support\Billing\PacEnvironmentAccess;
use App\Support\Billing\PacGuardRejectionLog;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fase 6.30 — Envío automático del CFDI por correo tras el timbrado.
 *
 * Un solo intento: el envío no es idempotente y un reintento tras un
 * timeout podría duplicar el correo (queda email_uncertain y se resuelve a
 * mano). Nunca afecta la emisión: cualquier error queda en el historial y
 * en el log, y el job termina sin relanzarlo. Una factura que ya tiene un
 * envío automático (confirmado o incierto) no se vuelve a enviar.
 */
class SendInvoiceEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $companyId,
        public readonly int $invoiceId,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->companyId}:{$this->invoiceId}";
    }

    public function handle(SendInvoiceEmailService $email, CurrentTenant $tenant): void
    {
        $previousTenant = $tenant->id();
        $tenant->set($this->companyId);

        try {
            $invoice = Invoice::query()->find($this->invoiceId);

            if ($invoice === null || $this->alreadyAutoSent()) {
                return;
            }

            // Fase 6.31: el ambiente y el guard se revalidan al ejecutar; si no
            // corresponden, el job falla sin reintentar (y sin enviar).
            $rejection = $this->configurationRejection($invoice);

            if ($rejection !== null) {
                PacGuardRejectionLog::record($rejection, [
                    'company_id' => $this->companyId,
                    'invoice_id' => $this->invoiceId,
                    'action' => 'email_auto_send',
                ]);
                $this->fail($rejection);

                return;
            }

            if ($email->defaultRecipients($invoice) === []) {
                $email->recordSkipped($invoice, 'no_recipients');

                return;
            }

            if ($email->sentInLastDay($this->companyId) >= $email->dailyLimit()) {
                $email->recordSkipped($invoice, 'daily_limit');

                return;
            }

            try {
                $email->send($invoice, null, null, SendInvoiceEmailService::TRIGGER_AUTO);
            } catch (InvoiceEmailException $e) {
                // Incierto: el servicio ya registró email_uncertain. Lo demás (p. ej.
                // la factura dejó de ser enviable) no llegó al PAC: se omite.
                $e->errorCode === 'INVOICE_EMAIL_UNCERTAIN'
                    ? $this->logFailure($e)
                    : $email->recordSkipped($invoice, strtolower($e->errorCode));
            } catch (Throwable $e) {
                // El servicio ya registró email_failed.
                $this->logFailure($e);
            }
        } finally {
            $previousTenant === null ? $tenant->clear() : $tenant->set($previousTenant);
        }
    }

    private function configurationRejection(Invoice $invoice): ?PacConfigurationException
    {
        $companyEnvironment = PacEnvironment::forCompany($this->companyId);

        if (PacEnvironmentAccess::isBlocked(PacEnvironmentAccess::status($invoice, $companyEnvironment))) {
            return PacEnvironmentAccess::exceptionFor($invoice, $companyEnvironment);
        }

        $violation = PacCredentialGuard::forInvoice($invoice)->violation();

        return $violation === null ? null : new PacConfigurationException($violation);
    }

    private function logFailure(Throwable $e): void
    {
        Log::warning('billing.invoice.email_auto_failed', [
            'invoice_id' => $this->invoiceId,
            'company_id' => $this->companyId,
            'reason' => $e::class,
        ]);
    }

    private function alreadyAutoSent(): bool
    {
        return InvoicePacEvent::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $this->companyId)
            ->where('invoice_id', $this->invoiceId)
            ->whereIn('event_type', [InvoicePacEventType::EmailSent->value, InvoicePacEventType::EmailUncertain->value, InvoicePacEventType::EmailSkipped->value])
            ->get(['context'])
            ->contains(fn (InvoicePacEvent $event): bool => ($event->context['trigger'] ?? null) === SendInvoiceEmailService::TRIGGER_AUTO);
    }
}
