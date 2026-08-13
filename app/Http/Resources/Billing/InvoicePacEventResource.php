<?php

namespace App\Http\Resources\Billing;

use App\Enums\InvoicePacEventType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Segunda barrera de exposición para context: cada tipo tiene lista blanca.
 * Un tipo futuro no mapeado obtiene context vacío por defecto.
 */
class InvoicePacEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->event_type->value,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'pac_status' => $this->pac_status,
            'cancellation_status' => $this->cancellation_status,
            'issue_status' => $this->pac_issue_status,
            'pac_code' => $this->safePacCode($this->pac_code),
            'context' => $this->publicContext(),
        ];
    }

    /** @return array<string, mixed> */
    private function publicContext(): array
    {
        $allowed = match ($this->event_type) {
            InvoicePacEventType::DraftCreated,
            InvoicePacEventType::DraftSynced => [
                'pac_draft_external_id_masked', 'pac_draft_status',
                'is_ready_to_stamp', 'remote_total', 'elapsed_ms',
            ],
            InvoicePacEventType::DraftUpdated => [
                'pac_draft_external_id_masked', 'pac_draft_status',
                'is_ready_to_stamp', 'local_total', 'remote_total',
                'totals_match', 'elapsed_ms',
            ],
            InvoicePacEventType::IssueAttempted => ['attempt'],
            InvoicePacEventType::IssueSucceeded => ['attempt', 'elapsed_ms', 'stamped_at'],
            InvoicePacEventType::IssueFailed => ['attempt', 'elapsed_ms', 'reason'],
            InvoicePacEventType::StampAttempted => [
                'attempt', 'pac_draft_external_id_masked', 'is_ready_to_stamp',
            ],
            InvoicePacEventType::StampSucceeded => [
                'attempt', 'elapsed_ms', 'stamped_at', 'result_status',
            ],
            InvoicePacEventType::StampFailed => ['attempt', 'elapsed_ms', 'reason'],
            InvoicePacEventType::Reconciled => ['elapsed_ms', 'changed_fields'],
            InvoicePacEventType::ReconciliationRequired => [
                'attempt', 'elapsed_ms', 'reason', 'motive',
                'pac_external_id_masked', 'artifact_status',
                'cancellation_receipt_status',
            ],
            InvoicePacEventType::ArtifactsStored => [
                'xml_size', 'pdf_size', 'downloaded_at', 'elapsed_ms',
            ],
            InvoicePacEventType::ArtifactsFailed => [
                'artifact_status', 'elapsed_ms', 'reason',
            ],
            InvoicePacEventType::CancellationRequested => ['motive'],
            InvoicePacEventType::CancellationPending,
            InvoicePacEventType::CancellationAccepted,
            InvoicePacEventType::CancellationRejected,
            InvoicePacEventType::CancellationExpired => [
                'motive', 'elapsed_ms', 'result_status', 'cancellation_status',
            ],
            InvoicePacEventType::CancellationReceiptAttempted => [
                'cancellation_receipt_status',
            ],
            InvoicePacEventType::CancellationReceiptStored => [
                'xml_size', 'pdf_size', 'downloaded_at', 'elapsed_ms',
            ],
            InvoicePacEventType::CancellationReceiptUnavailable => [
                'elapsed_ms', 'cancellation_receipt_status',
            ],
            InvoicePacEventType::CancellationReceiptIdentityMismatch => [
                'receipt_uuid_count', 'expected_uuid_masked',
                'pac_external_id_masked', 'elapsed_ms',
                'cancellation_receipt_status',
            ],
            default => [],
        };

        $context = is_array($this->context) ? $this->context : [];
        $public = [];

        foreach ($allowed as $key) {
            if (! array_key_exists($key, $context)) {
                continue;
            }

            $value = $this->safeContextValue($key, $context[$key]);

            if ($value !== null) {
                $public[$key] = $value;
            }
        }

        return $public;
    }

    private function safeContextValue(string $key, mixed $value): mixed
    {
        if ($key === 'changed_fields') {
            if (! is_array($value)) {
                return null;
            }

            return array_values(array_intersect($value, [
                'pac_status', 'cancellation_status', 'cfdi_uuid',
                'pac_external_id', 'pac_issue_status',
                'pac_reconciliation_required',
            ]));
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        if (str_contains($value, '<')
            || stripos($value, '%PDF-') !== false
            || preg_match('/\bAuthorization\b/i', $value) === 1) {
            return null;
        }

        $apiKey = (string) config('services.facturapi.test_key');

        if ($apiKey !== '') {
            $value = str_replace($apiKey, '[redacted]', $value);
        }

        $value = preg_replace('/\bBearer\s+[^\s]+/i', '[redacted]', $value) ?? '[redacted]';
        $value = preg_replace('/\bsk_(?:live|test)_[A-Za-z0-9._-]+\b/i', '[redacted]', $value) ?? '[redacted]';
        $value = preg_replace_callback(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            fn (array $match): string => mb_substr($match[0], 0, 8).'...'.mb_substr($match[0], -4),
            $value,
        ) ?? '[redacted]';

        return mb_substr($value, 0, 200);
    }

    private function safePacCode(?string $code): ?string
    {
        return $code !== null && preg_match('/^[A-Z0-9_.-]{1,100}$/i', $code) === 1
            ? $code
            : null;
    }
}
