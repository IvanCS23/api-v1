<?php

namespace App\Services\Billing;

use App\Contracts\Billing\PacProvider;
use App\Data\Billing\InvoiceArtifactsResult;
use App\Exceptions\Billing\CfdiArtifactMismatchException;
use App\Exceptions\Billing\CfdiArtifactMissingException;
use App\Exceptions\Billing\PacAuthenticationException;
use App\Exceptions\Billing\PacException;
use App\Exceptions\Billing\PacRateLimitException;
use App\Exceptions\Billing\PacValidationException;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Descarga (una sola vez, salvo `forceRefresh`), valida y almacena
 * privadamente el XML/PDF de un CFDI YA TIMBRADO (Fase 6.3) —
 * `pac_status=valid` + `cfdi_uuid` real, nunca de un borrador ni de una
 * emisión ambigua. Depende ÚNICAMENTE de `PacProvider` (contrato) y de
 * `Illuminate\Support\Facades\Storage` — nunca importa `FacturapiProvider`
 * ni conoce URLs/el facade `Http`/el Bearer token (mismo aislamiento que
 * el resto de los servicios de Billing).
 *
 * Disk elegido: `local` — ya es privado por defecto en este esqueleto de
 * Laravel (`config/filesystems.php`: root `storage/app/private`, sin
 * `visibility=public`, sin symlink en `links`) — se reutiliza en vez de
 * declarar un disk `cfdi` nuevo, porque ya cumple exactamente lo que
 * pide esta fase (ver reporte de entrega). Ruta relativa persistida:
 * `cfdi/{company_id}/{invoice_id}/{cfdi_uuid}.{xml,pdf}` — `company_id`
 * SIEMPRE viene de la Invoice ya validada contra `CurrentTenant`, nunca
 * de un dato del request.
 *
 * Consistencia DB/filesystem (§12 del encargo — documentado en vez de
 * asumido): NO existe una transacción distribuida real entre MySQL y el
 * filesystem local. La estrategia adoptada, en orden:
 *   1. Reserva corta (`cfdi_artifacts_status=pending`), cerrada ANTES de
 *      cualquier HTTP — mismo patrón que Stamp/Update (Fase 6.2.5/6.2.7).
 *   2. Descarga Y VALIDA ambos archivos (XML primero, luego PDF) EN
 *      MEMORIA, fuera de cualquier transacción — si el PDF falla después
 *      de que el XML ya se descargó, nunca se llegó a escribir nada en
 *      disco todavía, así que no hay nada que limpiar en el filesystem
 *      para ese caso (más seguro que escribir incrementalmente).
 *   3. Escribe ambos a rutas `.tmp`: si el segundo `put()` falla, borra
 *      el primero (limpieza explícita, ver §12 del encargo).
 *   4. Renombra (`Storage::move()`, atómico a nivel de SO en un disk
 *      local — un solo syscall `rename()`) de `.tmp` a la ruta final.
 *   5. Solo DESPUÉS de que ambos archivos existen en su ruta final,
 *      persiste la fila en una transacción de base de datos corta.
 *
 * Esto significa: si el paso 5 (DB) falla DESPUÉS de que los archivos ya
 * quedaron en su ruta final (paso 4), los archivos podrían quedar en
 * disco sin que la DB los refleje como `stored` — se intenta un borrado
 * de compensación best-effort en el `catch`, pero esto NO es una garantía
 * transaccional: un crash del proceso exactamente en esa ventana (no una
 * excepción de PHP capturable) podría dejar archivos huérfanos sin
 * marcar. Deliberado: el orden (archivos primero, DB después) evita el
 * peor escenario inverso — que la DB diga `stored` mientras el archivo
 * nunca llegó a escribirse.
 */
class DownloadInvoiceArtifactsService
{
    private const DISK = 'local';

    public function __construct(private readonly PacProvider $pacProvider) {}

    public function download(Invoice $invoice, bool $forceRefresh = false): InvoiceArtifactsResult
    {
        $current = $this->requireCurrentTenantInvoice($invoice);

        $this->assertIsStampedCfdi($current);

        if (! $forceRefresh && $this->hasStoredArtifacts($current)) {
            return $this->returnExistingOrReconcile($current);
        }

        $reserved = $this->reserve($current);

        $startedAt = microtime(true);

        try {
            $xml = $this->pacProvider->downloadXml($reserved->pac_external_id);
            $this->assertValidXml($reserved, $xml);

            $pdf = $this->pacProvider->downloadPdf($reserved->pac_external_id);
            $this->assertValidPdf($reserved, $pdf);
        } catch (Throwable $e) {
            $this->recordFailure($reserved, $e);
            $this->logAttempt($reserved, null, $this->elapsedMs($startedAt), $e);

            throw $e;
        }

        $paths = $this->paths($reserved);

        try {
            $this->writeAtomically($paths, $xml, $pdf);
        } catch (Throwable $e) {
            $this->recordFailure($reserved, $e);
            $this->logAttempt($reserved, null, $this->elapsedMs($startedAt), $e);

            throw $e;
        }

        try {
            $updated = $this->persistSuccess($reserved, $paths, $xml, $pdf);
        } catch (Throwable $e) {
            // Compensación best-effort: los archivos ya están en su ruta
            // final pero la DB no llegó a reflejarlo — ver docblock de
            // la clase para el límite honesto de esta garantía.
            $this->deleteQuietly($paths['xmlFinal']);
            $this->deleteQuietly($paths['pdfFinal']);
            $this->recordFailure($reserved, $e);
            $this->logAttempt($reserved, null, $this->elapsedMs($startedAt), $e);

            throw $e;
        }

        $result = $this->toResult($updated, $xml, $pdf);

        $this->logAttempt($updated, $result, $this->elapsedMs($startedAt), null);

        return $result;
    }

    /**
     * Idempotencia (§13): ya hay artifacts `stored` y no se pidió
     * `forceRefresh` — comprueba que los archivos SIGAN existiendo (y que
     * su hash coincida con el registrado) antes de devolverlos como
     * válidos; nunca responde "stored" en silencio si ya no están.
     */
    private function returnExistingOrReconcile(Invoice $invoice): InvoiceArtifactsResult
    {
        $disk = Storage::disk(self::DISK);

        $xmlOk = $invoice->cfdi_xml_path !== null
            && $disk->exists($invoice->cfdi_xml_path)
            && hash('sha256', $disk->get($invoice->cfdi_xml_path)) === $invoice->cfdi_xml_sha256;

        $pdfOk = $invoice->cfdi_pdf_path !== null
            && $disk->exists($invoice->cfdi_pdf_path)
            && hash('sha256', $disk->get($invoice->cfdi_pdf_path)) === $invoice->cfdi_pdf_sha256;

        if ($xmlOk && $pdfOk) {
            return new InvoiceArtifactsResult(
                xmlPath: $invoice->cfdi_xml_path,
                pdfPath: $invoice->cfdi_pdf_path,
                xmlHash: $invoice->cfdi_xml_sha256,
                pdfHash: $invoice->cfdi_pdf_sha256,
                xmlSize: (int) $invoice->cfdi_xml_size,
                pdfSize: (int) $invoice->cfdi_pdf_size,
                downloadedAt: $invoice->cfdi_artifacts_downloaded_at ?? CarbonImmutable::now(),
            );
        }

        $missingPath = ! $xmlOk ? (string) $invoice->cfdi_xml_path : (string) $invoice->cfdi_pdf_path;

        DB::transaction(function () use ($invoice): void {
            $locked = Invoice::withoutGlobalScope(CompanyScope::class)
                ->whereKey($invoice->getKey())
                ->where('company_id', $invoice->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->forceFill([
                'cfdi_artifacts_status' => 'reconciliation_required',
                'cfdi_artifacts_last_error' => 'Archivo local faltante o con hash distinto al registrado; requiere revisión manual.',
            ])->save();
        });

        Log::warning('billing.invoice.cfdi_artifact_missing', [
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
        ]);

        throw new CfdiArtifactMissingException($invoice->id, $missingPath);
    }

    private function hasStoredArtifacts(Invoice $invoice): bool
    {
        return $invoice->cfdi_artifacts_status === 'stored'
            && $invoice->cfdi_xml_path !== null
            && $invoice->cfdi_pdf_path !== null;
    }

    /**
     * Transacción corta: fija `cfdi_artifacts_status=pending`, cerrada
     * ANTES de tocar la red — mismo patrón que
     * StampPacDraftInvoiceService::reserve() (Fase 6.2.5).
     */
    private function reserve(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice): Invoice {
            $locked = Invoice::withoutGlobalScope(CompanyScope::class)
                ->whereKey($invoice->getKey())
                ->where('company_id', $invoice->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->cfdi_artifacts_status === 'pending') {
                throw new RuntimeException(sprintf(
                    'La factura [%d] ya tiene una descarga de artifacts en curso; no se inicia una segunda.',
                    $locked->id,
                ));
            }

            $locked->forceFill(['cfdi_artifacts_status' => 'pending'])->save();

            return $locked;
        });
    }

    /**
     * @return array{xmlTmp: string, xmlFinal: string, pdfTmp: string, pdfFinal: string}
     */
    private function paths(Invoice $invoice): array
    {
        $base = sprintf('cfdi/%d/%d/%s', $invoice->company_id, $invoice->id, $invoice->cfdi_uuid);

        return [
            'xmlFinal' => "{$base}.xml",
            'xmlTmp' => "{$base}.xml.tmp",
            'pdfFinal' => "{$base}.pdf",
            'pdfTmp' => "{$base}.pdf.tmp",
        ];
    }

    /**
     * El disk `local` de este proyecto tiene `'throw' => false`
     * (`config/filesystems.php`) — Flysystem NO lanza excepción ante un
     * fallo de escritura, `put()`/`move()` simplemente devuelven `false`.
     * Por eso cada paso se verifica explícitamente por valor de retorno,
     * nunca solo por try/catch (que no vería nada que capturar).
     *
     * @param  array{xmlTmp: string, xmlFinal: string, pdfTmp: string, pdfFinal: string}  $paths
     */
    private function writeAtomically(array $paths, string $xml, string $pdf): void
    {
        $disk = Storage::disk(self::DISK);

        if (! $disk->put($paths['xmlTmp'], $xml)) {
            throw new RuntimeException('No se pudo escribir el XML temporal en el almacenamiento privado.');
        }

        if (! $disk->put($paths['pdfTmp'], $pdf)) {
            $this->deleteQuietly($paths['xmlTmp']);

            throw new RuntimeException('No se pudo escribir el PDF temporal en el almacenamiento privado.');
        }

        $moved = $disk->move($paths['xmlTmp'], $paths['xmlFinal'])
            && $disk->move($paths['pdfTmp'], $paths['pdfFinal']);

        if (! $moved) {
            $this->deleteQuietly($paths['xmlTmp']);
            $this->deleteQuietly($paths['pdfTmp']);
            $this->deleteQuietly($paths['xmlFinal']);
            $this->deleteQuietly($paths['pdfFinal']);

            throw new RuntimeException('No se pudieron mover los artifacts temporales a su ruta final.');
        }
    }

    private function deleteQuietly(string $path): void
    {
        try {
            Storage::disk(self::DISK)->delete($path);
        } catch (Throwable) {
            // Best-effort — nunca enmascara la excepción original con un
            // fallo de limpieza.
        }
    }

    /**
     * @param  array{xmlTmp: string, xmlFinal: string, pdfTmp: string, pdfFinal: string}  $paths
     */
    private function persistSuccess(Invoice $reserved, array $paths, string $xml, string $pdf): Invoice
    {
        $xmlHash = hash('sha256', $xml);
        $pdfHash = hash('sha256', $pdf);

        return DB::transaction(function () use ($reserved, $paths, $xml, $pdf, $xmlHash, $pdfHash): Invoice {
            $locked = Invoice::withoutGlobalScope(CompanyScope::class)
                ->whereKey($reserved->getKey())
                ->where('company_id', $reserved->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->forceFill([
                'cfdi_xml_path' => $paths['xmlFinal'],
                'cfdi_pdf_path' => $paths['pdfFinal'],
                'cfdi_xml_sha256' => $xmlHash,
                'cfdi_pdf_sha256' => $pdfHash,
                'cfdi_xml_size' => strlen($xml),
                'cfdi_pdf_size' => strlen($pdf),
                'cfdi_artifacts_downloaded_at' => now(),
                'cfdi_artifacts_last_error' => null,
                'cfdi_artifacts_status' => 'stored',
            ])->save();

            return $locked;
        });
    }

    private function toResult(Invoice $invoice, string $xml, string $pdf): InvoiceArtifactsResult
    {
        return new InvoiceArtifactsResult(
            xmlPath: $invoice->cfdi_xml_path,
            pdfPath: $invoice->cfdi_pdf_path,
            xmlHash: $invoice->cfdi_xml_sha256,
            pdfHash: $invoice->cfdi_pdf_sha256,
            xmlSize: strlen($xml),
            pdfSize: strlen($pdf),
            downloadedAt: $invoice->cfdi_artifacts_downloaded_at,
        );
    }

    /**
     * No vacío, "parece" XML, parsea de forma segura (sin resolver
     * entidades externas — ver docblock de clase §9 del encargo: XXE) y
     * su `TimbreFiscalDigital/@UUID` coincide (insensible a mayúsculas)
     * con `Invoice::cfdi_uuid`. Nunca reserializa ni modifica `$xml` — el
     * string original es exactamente lo que se persiste después.
     */
    private function assertValidXml(Invoice $invoice, string $xml): void
    {
        if (trim($xml) === '') {
            throw new RuntimeException(sprintf(
                'La factura [%d]: el XML descargado del PAC está vacío.',
                $invoice->id,
            ));
        }

        if (! str_starts_with(ltrim($xml), '<')) {
            throw new RuntimeException(sprintf(
                'La factura [%d]: el contenido descargado no parece XML.',
                $invoice->id,
            ));
        }

        libxml_use_internal_errors(true);

        $dom = new DOMDocument();
        // Defensa XXE explícita: nunca resolver ni sustituir entidades
        // (externas o no), y LIBXML_NONET bloquea cualquier acceso de
        // red durante el parseo — incluso aunque libxml2 moderno ya
        // deshabilita la carga de entidades externas por default, esto
        // deja la intención explícita en el código, no implícita en la
        // versión de libxml2 del entorno.
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;

        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        $parseErrors = libxml_get_errors();
        libxml_clear_errors();

        if (! $loaded || $parseErrors !== []) {
            throw new RuntimeException(sprintf(
                'La factura [%d]: el XML descargado del PAC está mal formado.',
                $invoice->id,
            ));
        }

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//*[local-name()="TimbreFiscalDigital"]/@UUID');

        if ($nodes === false || $nodes->length === 0) {
            throw new RuntimeException(sprintf(
                'La factura [%d]: el XML descargado no contiene TimbreFiscalDigital/UUID.',
                $invoice->id,
            ));
        }

        $foundUuid = (string) $nodes->item(0)->nodeValue;

        if (strcasecmp($foundUuid, (string) $invoice->cfdi_uuid) !== 0) {
            throw new CfdiArtifactMismatchException($invoice->id, (string) $invoice->cfdi_uuid, $foundUuid);
        }
    }

    /**
     * No vacío, encabezado mágico `%PDF-` (detecta de paso una respuesta
     * JSON/HTML inesperada del PAC, que nunca empieza así). Nunca
     * intenta renderizar/modificar el PDF.
     */
    private function assertValidPdf(Invoice $invoice, string $pdf): void
    {
        if ($pdf === '') {
            throw new RuntimeException(sprintf(
                'La factura [%d]: el PDF descargado del PAC está vacío.',
                $invoice->id,
            ));
        }

        if (! str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException(sprintf(
                'La factura [%d]: el contenido descargado no tiene el encabezado %%PDF- esperado.',
                $invoice->id,
            ));
        }
    }

    /**
     * Clasifica el fallo (definitivo vs. ambiguo, mismo criterio que
     * StampPacDraftInvoiceService::isDefinitiveFailure()) y persiste
     * `cfdi_artifacts_status`/`cfdi_artifacts_last_error` en su propia
     * transacción corta. `cfdi_artifacts_last_error` nunca contiene el
     * XML/PDF crudo ni la API key — solo el mensaje/código ya saneado
     * por las excepciones de `App\Exceptions\Billing`.
     */
    private function recordFailure(Invoice $invoice, Throwable $e): void
    {
        $status = $this->isDefinitiveFailure($e) ? 'failed' : 'reconciliation_required';

        DB::transaction(function () use ($invoice, $status, $e): void {
            $locked = Invoice::withoutGlobalScope(CompanyScope::class)
                ->whereKey($invoice->getKey())
                ->where('company_id', $invoice->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->forceFill([
                'cfdi_artifacts_status' => $status,
                'cfdi_artifacts_last_error' => $this->sanitizeErrorMessage($e),
            ])->save();
        });
    }

    private function isDefinitiveFailure(Throwable $e): bool
    {
        return $e instanceof PacValidationException
            || $e instanceof PacAuthenticationException
            || $e instanceof PacRateLimitException
            || $e instanceof CfdiArtifactMismatchException;
    }

    private function sanitizeErrorMessage(Throwable $e): string
    {
        $code = $e instanceof PacException ? ($e->pacCode ?? (string) $e->httpStatus) : null;
        $prefix = $code !== null && $code !== '' ? "[{$code}] " : '';

        return mb_substr($prefix.$e->getMessage(), 0, 500);
    }

    private function logAttempt(Invoice $invoice, ?InvoiceArtifactsResult $result, int $elapsedMs, ?Throwable $error): void
    {
        Log::info('billing.invoice.cfdi_artifacts_attempt', [
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'cfdi_artifacts_status' => $invoice->cfdi_artifacts_status,
            'xml_size' => $result?->xmlSize,
            'pdf_size' => $result?->pdfSize,
            'elapsed_ms' => $elapsedMs,
            'pac_error_code' => $error instanceof PacException ? $error->pacCode : null,
        ]);
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function assertIsStampedCfdi(Invoice $invoice): void
    {
        if ($invoice->pac_external_id === null || $invoice->cfdi_uuid === null || $invoice->pac_status !== 'valid') {
            throw new RuntimeException(sprintf(
                'La factura [%d] no es un CFDI timbrado válido (pac_external_id: %s, cfdi_uuid: %s, pac_status: %s); no se pueden descargar artifacts.',
                $invoice->id,
                $invoice->pac_external_id ?? 'null',
                $invoice->cfdi_uuid ?? 'null',
                $invoice->pac_status ?? 'null',
            ));
        }
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
            throw (new ModelNotFoundException())->setModel(Invoice::class, [$invoice->getKey()]);
        }

        return $fresh;
    }
}
