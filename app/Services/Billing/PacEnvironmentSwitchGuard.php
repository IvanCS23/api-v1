<?php

namespace App\Services\Billing;

use App\Jobs\Billing\DownloadInvoiceArtifactsJob;
use App\Jobs\Billing\SendInvoiceEmailJob;
use App\Models\Invoice;
use App\Support\Billing\PacEnvironmentAccess;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6.31 — Qué impide cambiar el ambiente de una empresa.
 *
 * "Factura Test sin terminar": una factura de la empresa (sin las borradas)
 * con ambiente test, o sin ambiente pero con operaciones del PAC, en
 * cualquiera de estos estados. Después del cambio quedaría bloqueada
 * (PAC_ENVIRONMENT_MISMATCH) sin poder concluir:
 *
 * 1. sin_ambiente: operaciones del PAC sin ambiente (correr antes
 *    billing:backfill-pac-environment).
 * 2. emision_en_curso: pac_issue_status pending o reconciliation_required.
 * 3. revision_pendiente: pac_reconciliation_required.
 * 4. reserva_sin_borrador: llave de borrador sin id remoto.
 * 5. borrador_sin_timbrar: borrador remoto sin CFDI (ready con borrador,
 *    o issued con el timbrado rechazado).
 * 6. emitida_sin_cfdi: ERP issued sin CFDI.
 * 7. cancelacion_en_curso: solicitud in_flight o reconciliation_required,
 *    o cancellation_status pending o verifying.
 * 8. descarga_en_curso: XML/PDF o acuse en pending.
 *
 * Además, ningún job de la empresa (XML/PDF o correo) puede estar en la
 * cola: lo ejecutaría con el ambiente nuevo y fallaría.
 */
final class PacEnvironmentSwitchGuard
{
    public const UNFINISHED_STATES = [
        'sin_ambiente',
        'emision_en_curso',
        'revision_pendiente',
        'reserva_sin_borrador',
        'borrador_sin_timbrar',
        'emitida_sin_cfdi',
        'cancelacion_en_curso',
        'descarga_en_curso',
    ];

    private const JOB_CLASSES = [DownloadInvoiceArtifactsJob::class, SendInvoiceEmailJob::class];

    /**
     * Facturas Test sin terminar de la empresa del tenant actual.
     *
     * @return list<array{id: int, folio: string, states: list<string>}>
     */
    public function unfinishedTestInvoices(): array
    {
        $unfinished = [];

        Invoice::query()
            ->where(fn ($query) => $query->where('pac_environment', 'test')->orWhereNull('pac_environment'))
            ->orderBy('id')
            ->each(function (Invoice $invoice) use (&$unfinished): void {
                $states = $this->states($invoice);

                if ($states !== []) {
                    $unfinished[] = ['id' => (int) $invoice->id, 'folio' => (string) $invoice->folio, 'states' => $states];
                }
            });

        return $unfinished;
    }

    /** @return list<string> */
    public function states(Invoice $invoice): array
    {
        if (blank($invoice->pac_environment)) {
            return PacEnvironmentAccess::hasPacState($invoice) ? ['sin_ambiente'] : [];
        }

        $states = [];
        $hasCfdi = filled($invoice->pac_external_id) || filled($invoice->cfdi_uuid);

        if (in_array($invoice->pac_issue_status, ['pending', 'reconciliation_required'], true)) {
            $states[] = 'emision_en_curso';
        }

        if ((bool) $invoice->pac_reconciliation_required) {
            $states[] = 'revision_pendiente';
        }

        if (filled($invoice->pac_draft_idempotency_key) && blank($invoice->pac_draft_external_id) && ! $hasCfdi) {
            $states[] = 'reserva_sin_borrador';
        }

        if (filled($invoice->pac_draft_external_id) && ! $hasCfdi) {
            $states[] = 'borrador_sin_timbrar';
        }

        if ($invoice->status?->value === 'issued' && ! $hasCfdi) {
            $states[] = 'emitida_sin_cfdi';
        }

        if (in_array($invoice->pac_cancel_request_status, ['in_flight', 'reconciliation_required'], true)
            || in_array($invoice->cancellation_status, ['pending', 'verifying'], true)) {
            $states[] = 'cancelacion_en_curso';
        }

        if ($invoice->cfdi_artifacts_status === 'pending' || $invoice->cancellation_receipt_status === 'pending') {
            $states[] = 'descarga_en_curso';
        }

        return $states;
    }

    /** Facturas Live de la empresa del tenant actual, incluidas las borradas. */
    public function liveInvoiceCount(): int
    {
        return Invoice::withTrashed()->where('pac_environment', 'live')->count();
    }

    /**
     * Jobs de la empresa en la cola, o null si el driver de la cola no
     * permite revisarlo (sólo `database` y `sync`).
     */
    public function pendingJobs(int $companyId): ?int
    {
        $connection = (string) config('queue.default');
        $driver = config("queue.connections.{$connection}.driver");

        if ($driver === 'sync') {
            return 0;
        }

        if ($driver !== 'database') {
            return null;
        }

        $table = (string) config("queue.connections.{$connection}.table", 'jobs');
        $count = 0;

        DB::connection(config("queue.connections.{$connection}.connection"))
            ->table($table)
            ->orderBy('id')
            ->each(function (object $job) use ($companyId, &$count): void {
                $payload = json_decode((string) $job->payload, true);
                $command = is_array($payload) ? (string) ($payload['data']['command'] ?? '') : '';

                // El comando serializado trae `companyId` como entero.
                if (is_array($payload)
                    && in_array($payload['displayName'] ?? null, self::JOB_CLASSES, true)
                    && str_contains($command, 's:9:"companyId";i:'.$companyId.';')) {
                    $count++;
                }
            });

        return $count;
    }
}
