<?php

namespace App\Services\Billing;

use App\Contracts\Billing\PacProviderResolver;
use App\Enums\InvoicePacEventType;
use App\Exceptions\Billing\InvoiceEmailException;
use App\Exceptions\Billing\PacEmailNotSentException;
use App\Exceptions\Billing\PacException;
use App\Exceptions\Billing\PacUnavailableException;
use App\Exceptions\Billing\PacUnexpectedResponseException;
use App\Models\Invoice;
use App\Models\InvoicePacEvent;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Support\Billing\InvoiceEmailRecipients;
use App\Support\Billing\PacCredentialGuard;
use App\Support\Billing\PacEnvironmentAccess;
use App\Support\Billing\PacOrganizationAccess;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fase 6.30 — Envía el CFDI (XML y PDF) por correo con Facturapi.
 *
 * - Sólo con CFDI vigente (InvoiceBusinessCapabilitiesService::
 *   canSendEmailByState) y de la organización de la empresa. Uno cancelado
 *   responde INVOICE_EMAIL_CFDI_CANCELED (ver canSendEmailByState).
 * - Destinatarios: los indicados o, si no hay, los del cliente en nuestra
 *   base (InvoiceEmailRecipients); entre 1 y 5.
 * - Tope diario por empresa contado en la BD (envíos confirmados e
 *   inciertos de las últimas 24 h), para manuales y automáticos.
 * - Cada intento queda en el historial: email_sent, email_failed (el PAC
 *   respondió que no) o email_uncertain (timeout/5xx: no se sabe). Nunca
 *   se guarda el contenido; los correos sólo en el contexto cifrado y
 *   enmascarados en el log.
 *
 * Quién puede enviar y editar la lista lo decide el controlador (Policy);
 * el envío automático no tiene usuario.
 */
class SendInvoiceEmailService
{
    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_AUTO = 'auto';

    public function __construct(
        private readonly PacProviderResolver $pacProviders,
        private readonly InvoiceBusinessCapabilitiesService $capabilities,
        private readonly InvoicePacAuditService $audit,
    ) {}

    /**
     * @param  list<string>|null  $recipients  null = los del cliente
     * @return list<string> los destinatarios a los que se envió
     */
    public function send(Invoice $invoice, ?array $recipients, ?User $actor, string $trigger = self::TRIGGER_MANUAL): array
    {
        $current = $this->requireCurrentTenantInvoice($invoice);

        PacOrganizationAccess::assertAccessible($current);
        // Fase 6.31: ni una factura de otro ambiente del PAC, y con una
        // configuración inválida (p. ej. el paro de emergencia) no se
        // registra ningún intento.
        PacEnvironmentAccess::assertAccessible($current);
        PacCredentialGuard::forInvoice($current)->assertConfigured();

        if (! $this->capabilities->canSendEmailByState($current)) {
            throw $current->pac_status === 'canceled'
                ? InvoiceEmailException::canceled()
                : InvoiceEmailException::notAllowed();
        }

        $final = $this->resolveRecipients($current, $recipients);
        $this->assertWithinDailyLimit((int) $current->company_id);

        $context = [
            'recipients' => $final,
            'recipient_count' => count($final),
            'trigger' => $trigger,
            'user_id' => $actor?->id,
        ];

        try {
            $this->pacProviders->forInvoice($current)->sendInvoiceEmail((string) $current->pac_external_id, $final);
        } catch (Throwable $e) {
            $uncertain = $e instanceof ConnectionException
                || $e instanceof PacUnavailableException
                || $e instanceof PacUnexpectedResponseException;

            $this->record($current, $uncertain ? InvoicePacEventType::EmailUncertain : InvoicePacEventType::EmailFailed, $context, $e);

            throw $uncertain ? InvoiceEmailException::uncertain($e) : $e;
        }

        $this->record($current, InvoicePacEventType::EmailSent, $context);

        return $final;
    }

    /**
     * Destinatarios por defecto de la factura (para el panel y el envío
     * automático).
     *
     * @return list<string>
     */
    public function defaultRecipients(Invoice $invoice): array
    {
        return array_slice(InvoiceEmailRecipients::defaultsFor($invoice), 0, InvoiceEmailRecipients::MAX);
    }

    /** Registra un envío automático que no se hizo (sin destinatarios, tope diario). */
    public function recordSkipped(Invoice $invoice, string $reason): void
    {
        $this->audit->appendSafely($invoice, InvoicePacEventType::EmailSkipped, [
            'trigger' => self::TRIGGER_AUTO,
            'reason' => $reason,
        ]);

        Log::info('billing.invoice.email_skipped', [
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'reason' => $reason,
        ]);
    }

    /** Envíos (confirmados e inciertos) de la empresa en las últimas 24 h. */
    public function sentInLastDay(int $companyId): int
    {
        return InvoicePacEvent::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->whereIn('event_type', [InvoicePacEventType::EmailSent->value, InvoicePacEventType::EmailUncertain->value])
            ->where('occurred_at', '>=', now()->subDay())
            ->count();
    }

    public function dailyLimit(): int
    {
        return max(1, (int) config('billing.email.company_daily_limit'));
    }

    /**
     * @param  list<string>|null  $recipients
     * @return list<string>
     */
    private function resolveRecipients(Invoice $invoice, ?array $recipients): array
    {
        if ($recipients === null) {
            $defaults = $this->defaultRecipients($invoice);

            if ($defaults === []) {
                throw InvoiceEmailException::noRecipients();
            }

            return $defaults;
        }

        $normalized = InvoiceEmailRecipients::normalize($recipients);

        if ($normalized === []) {
            throw InvoiceEmailException::noRecipients();
        }

        if (count($normalized) > InvoiceEmailRecipients::MAX
            || array_filter($normalized, fn (string $email): bool => ! InvoiceEmailRecipients::isValid($email)) !== []) {
            throw InvoiceEmailException::invalidRecipients(InvoiceEmailRecipients::MAX);
        }

        return $normalized;
    }

    private function assertWithinDailyLimit(int $companyId): void
    {
        $limit = $this->dailyLimit();

        if ($this->sentInLastDay($companyId) >= $limit) {
            throw InvoiceEmailException::dailyLimit($limit);
        }
    }

    /** @param  array<string, mixed>  $context */
    private function record(Invoice $invoice, InvoicePacEventType $type, array $context, ?Throwable $error = null): void
    {
        $pacCode = $error instanceof PacException ? ($error->pacCode ?? (string) $error->httpStatus) : null;

        $this->audit->appendSafely($invoice, $type, $error === null ? $context : [...$context, 'reason' => $error instanceof PacEmailNotSentException ? 'email_not_sent' : class_basename($error)], $pacCode);

        Log::log($type === InvoicePacEventType::EmailSent ? 'info' : 'warning', 'billing.invoice.'.$type->value, [
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'trigger' => $context['trigger'],
            'user_id' => $context['user_id'],
            'recipients' => array_map(InvoiceEmailRecipients::mask(...), $context['recipients']),
            'pac_code' => $pacCode,
        ]);
    }

    private function requireCurrentTenantInvoice(Invoice $invoice): Invoice
    {
        $tenantId = app(CurrentTenant::class)->id();

        $fresh = $tenantId !== null
            ? Invoice::withoutGlobalScope(CompanyScope::class)
                ->whereKey($invoice->getKey())
                ->where('company_id', $tenantId)
                ->first()
            : null;

        if ($fresh === null) {
            throw (new ModelNotFoundException)->setModel(Invoice::class, [$invoice->getKey()]);
        }

        return $fresh;
    }
}
