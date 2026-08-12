<?php

namespace App\Services\Billing;

use App\Enums\InvoicePacEventType;
use App\Models\Invoice;
use App\Models\InvoicePacEvent;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenant\CurrentTenant;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Escritura central append-only de la bitacora fiscal PAC.
 *
 * append() es estricto: valida tenant y contexto. Las integraciones con
 * efectos remotos usan appendSafely(): un fallo de auditoria se registra
 * pero nunca provoca reintentar una operacion PAC ya ejecutada.
 */
class InvoicePacAuditService
{
    private const MAX_DEPTH = 3;

    private const MAX_ITEMS = 50;

    public function append(
        Invoice $invoice,
        InvoicePacEventType $type,
        array $context = [],
        ?string $pacCode = null,
    ): InvoicePacEvent {
        $current = $this->requireCurrentTenantInvoice($invoice);
        $occurredAt = now();

        $event = new InvoicePacEvent;
        $event->forceFill([
            'company_id' => $current->company_id,
            'invoice_id' => $current->id,
            'event_type' => $type,
            'pac_provider' => $this->safeShortString($current->pac_provider, 30),
            'pac_external_id' => $this->safeString($current->pac_external_id, 255),
            'cfdi_uuid' => $this->safeString($current->cfdi_uuid, 36),
            'pac_status' => $this->safeShortString($current->pac_status, 30),
            'cancellation_status' => $this->safeShortString($current->cancellation_status, 30),
            'pac_issue_status' => $this->safeShortString($current->pac_issue_status, 30),
            'pac_code' => $this->safePacCode($pacCode),
            'occurred_at' => $occurredAt,
            'context' => $context === [] ? null : $this->sanitizeContext($context),
            'created_at' => $occurredAt,
        ]);
        $event->save();

        return $event;
    }

    public function appendSafely(
        Invoice $invoice,
        InvoicePacEventType $type,
        array $context = [],
        ?string $pacCode = null,
    ): ?InvoicePacEvent {
        try {
            return $this->append($invoice, $type, $context, $pacCode);
        } catch (Throwable $e) {
            try {
                Log::error('billing.invoice.pac_audit_append_failed', [
                    'invoice_id' => $invoice->id,
                    'company_id' => $invoice->company_id,
                    'event_type' => $type->value,
                    'error_class' => $e::class,
                ]);
            } catch (Throwable) {
                // La auditoria es fail-safe frente a operaciones PAC.
            }

            return null;
        }
    }

    public function maskIdentifier(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strlen($value) <= 12
            ? '[masked]'
            : mb_substr($value, 0, 8).'...'.mb_substr($value, -4);
    }

    /** @return array<string, mixed> */
    private function sanitizeContext(array $context, int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('El context de auditoria excede la profundidad permitida.');
        }

        if (count($context) > self::MAX_ITEMS) {
            throw new InvalidArgumentException('El context de auditoria contiene demasiados elementos.');
        }

        $sanitized = [];

        foreach ($context as $key => $value) {
            $key = (string) $key;
            $this->assertSafeContextKey($key);
            $sanitized[$key] = $this->sanitizeValue($value, $key, $depth);
        }

        return $sanitized;
    }

    private function sanitizeValue(mixed $value, string $key, int $depth): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if (is_array($value)) {
            return $this->sanitizeContext($value, $depth + 1);
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("Tipo no permitido en context de auditoria: {$key}.");
        }

        $value = $this->redactSecrets($value);

        if (str_starts_with(ltrim($value), '<') || str_starts_with($value, '%PDF-')) {
            return '[redacted_document_content]';
        }

        $value = preg_replace_callback(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            fn (array $match): string => $this->maskIdentifier($match[0]) ?? '[masked]',
            $value,
        ) ?? '[redacted]';

        return mb_substr($value, 0, 500);
    }

    private function assertSafeContextKey(string $key): void
    {
        $normalized = strtolower($key);
        $allowedDocumentMetadata = preg_match('/(xml|pdf)_(size|sha256)$/', $normalized) === 1;

        if (! $allowedDocumentMetadata && preg_match(
            '/(^|_)(authorization|api_?key|token|secret|password|private_?key|certificate|raw_?response|response|payload|body|xml|pdf|rfc|address|domicilio|path)($|_)/',
            $normalized,
        ) === 1) {
            throw new InvalidArgumentException("Clave no permitida en context de auditoria: {$key}.");
        }
    }

    private function redactSecrets(string $value): string
    {
        $apiKey = (string) config('services.facturapi.test_key');

        if ($apiKey !== '') {
            $value = str_replace($apiKey, '[redacted]', $value);
        }

        $value = preg_replace('/\bBearer\s+[^\s]+/i', '[redacted]', $value) ?? '[redacted]';

        return preg_replace('/\bsk_(?:live|test)_[A-Za-z0-9._-]+\b/i', '[redacted]', $value) ?? '[redacted]';
    }

    private function safePacCode(?string $pacCode): ?string
    {
        return $pacCode !== null && preg_match('/^[a-z0-9_.-]{1,100}$/i', $pacCode) === 1
            ? $pacCode
            : null;
    }

    private function safeShortString(?string $value, int $length): ?string
    {
        if ($value === null || preg_match('/^[a-z0-9_.-]+$/i', $value) !== 1) {
            return null;
        }

        return mb_substr($value, 0, $length);
    }

    private function safeString(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
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
