<?php

namespace App\Services\Billing;

use App\Contracts\Billing\PacProvider;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenant\CurrentTenant;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Correlaciona identidades fiscales sin modificar DB, PAC ni artifacts.
 *
 * El resultado ya viene sanitizado: nunca expone XML, respuestas PAC crudas,
 * rutas de Storage ni UUID completos.
 */
class AuditCancellationIdentityService
{
    private const UUID_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    public function __construct(private readonly PacProvider $pacProvider) {}

    /**
     * @return array<string, mixed>
     */
    public function audit(Invoice $invoice): array
    {
        $current = $this->requireCurrentTenantInvoice($invoice);
        $this->assertAuditable($current);

        $remote = $this->pacProvider->retrieveInvoice((string) $current->pac_external_id);
        $receiptXml = $this->pacProvider->downloadCancellationReceiptXml((string) $current->pac_external_id);
        $receiptUuids = $this->extractReceiptUuids($receiptXml);
        $cfdi = $this->inspectStoredCfdi($current);

        $localUuid = (string) $current->cfdi_uuid;
        $remoteUuid = $this->validUuidOrNull($remote->uuid);
        $cfdiUuid = $cfdi['trusted_uuid'];
        $pacResponse = $this->inspectHistoricalResponse($current->pac_response, $localUuid);
        $pacDraftResponse = $this->inspectHistoricalResponse($current->pac_draft_response, $localUuid);

        $comparisons = [
            'local_equals_remote' => $this->equals($localUuid, $remoteUuid),
            'local_equals_receipt' => $this->equalsAny($localUuid, $receiptUuids),
            'remote_equals_receipt' => $this->equalsAny($remoteUuid, $receiptUuids),
            'local_equals_cfdi_xml' => $this->equals($localUuid, $cfdiUuid),
            'remote_equals_cfdi_xml' => $this->equals($remoteUuid, $cfdiUuid),
            'receipt_equals_cfdi_xml' => $this->equalsAny($cfdiUuid, $receiptUuids),
        ];

        return [
            'local' => [
                'uuid' => $this->maskUuid($localUuid),
                'pac_external_id' => $this->maskIdentifier((string) $current->pac_external_id),
            ],
            'remote' => [
                'id' => $this->maskIdentifier($remote->externalId),
                'id_matches_local' => hash_equals((string) $current->pac_external_id, $remote->externalId),
                'status' => $this->safeStatus($remote->status),
                'cancellation_status' => $this->safeStatus($remote->cancellationStatus),
                'uuid' => $this->maskNullableUuid($remoteUuid),
                'livemode' => $remote->rawResponse['livemode'] ?? null,
                'stamp_date' => $remote->stampedAt?->toIso8601String(),
            ],
            'receipt' => [
                'xpath' => '/*[local-name()="Acuse"]/*[local-name()="Folios"]/*[local-name()="UUID"]',
                'uuids' => array_map($this->maskUuid(...), $receiptUuids),
                'count' => count($receiptUuids),
            ],
            'cfdi_xml' => [
                'state' => $cfdi['state'],
                'hash_matches_metadata' => $cfdi['hash_matches_metadata'],
                'uuid' => $this->maskNullableUuid($cfdiUuid),
            ],
            'history' => [
                'pac_response' => $pacResponse,
                'pac_draft_response' => $pacDraftResponse,
                'note' => 'pac_response es el snapshot PAC actual y puede haber sido sobrescrito por cancelacion; no es un historial inmutable.',
            ],
            'comparisons' => $comparisons,
            'scenario' => $this->scenario($comparisons),
        ];
    }

    /** @return list<string> */
    private function extractReceiptUuids(string $xml): array
    {
        $dom = $this->loadXmlSafely($xml, 'acuse');

        if ($dom->documentElement?->localName !== 'Acuse') {
            throw new RuntimeException('El XML del acuse no tiene la raiz Acuse esperada.');
        }

        $nodes = (new DOMXPath($dom))->query(
            '/*[local-name()="Acuse"]/*[local-name()="Folios"]/*[local-name()="UUID"]',
        );

        if ($nodes === false || $nodes->length === 0) {
            throw new RuntimeException('El XML del acuse no contiene Acuse/Folios/UUID.');
        }

        $uuids = [];

        foreach ($nodes as $node) {
            $uuid = $this->validUuidOrNull(trim((string) $node->textContent));

            if ($uuid === null) {
                throw new RuntimeException('El acuse contiene un Acuse/Folios/UUID con formato invalido.');
            }

            if (! in_array(strtolower($uuid), array_map('strtolower', $uuids), true)) {
                $uuids[] = $uuid;
            }
        }

        return $uuids;
    }

    /**
     * @return array{state: string, hash_matches_metadata: ?bool, trusted_uuid: ?string}
     */
    private function inspectStoredCfdi(Invoice $invoice): array
    {
        $path = $invoice->cfdi_xml_path;

        if (! is_string($path) || $path === '') {
            return ['state' => 'path_missing', 'hash_matches_metadata' => null, 'trusted_uuid' => null];
        }

        if ($this->unsafeStoragePath($path)) {
            return ['state' => 'unsafe_path', 'hash_matches_metadata' => null, 'trusted_uuid' => null];
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            return ['state' => 'file_missing', 'hash_matches_metadata' => null, 'trusted_uuid' => null];
        }

        $xml = $disk->get($path);
        $expectedHash = $invoice->cfdi_xml_sha256;
        $hashMatches = is_string($expectedHash)
            && $expectedHash !== ''
            && hash_equals($expectedHash, hash('sha256', $xml));

        try {
            $dom = $this->loadXmlSafely($xml, 'CFDI original');
        } catch (RuntimeException) {
            return ['state' => 'invalid_xml', 'hash_matches_metadata' => $hashMatches, 'trusted_uuid' => null];
        }

        $nodes = (new DOMXPath($dom))->query('//*[local-name()="TimbreFiscalDigital"]/@UUID');

        if ($nodes === false || $nodes->length === 0) {
            return ['state' => 'timbre_uuid_missing', 'hash_matches_metadata' => $hashMatches, 'trusted_uuid' => null];
        }

        $uuids = [];

        foreach ($nodes as $node) {
            $uuid = $this->validUuidOrNull(trim((string) $node->nodeValue));

            if ($uuid === null) {
                return ['state' => 'timbre_uuid_invalid', 'hash_matches_metadata' => $hashMatches, 'trusted_uuid' => null];
            }

            if (! in_array(strtolower($uuid), array_map('strtolower', $uuids), true)) {
                $uuids[] = $uuid;
            }
        }

        if (count($uuids) !== 1) {
            return ['state' => 'timbre_uuid_ambiguous', 'hash_matches_metadata' => $hashMatches, 'trusted_uuid' => null];
        }

        if (! $hashMatches) {
            return ['state' => 'hash_mismatch', 'hash_matches_metadata' => false, 'trusted_uuid' => null];
        }

        return ['state' => 'verified', 'hash_matches_metadata' => true, 'trusted_uuid' => $uuids[0]];
    }

    /**
     * @param  array<string, mixed>|null  $response
     * @return array{present: bool, status: ?string, uuid_state: string, uuid: ?string, uuid_matches_local: ?bool}
     */
    private function inspectHistoricalResponse(?array $response, string $localUuid): array
    {
        if ($response === null) {
            return [
                'present' => false,
                'status' => null,
                'uuid_state' => 'absent',
                'uuid' => null,
                'uuid_matches_local' => null,
            ];
        }

        $rawUuid = isset($response['uuid']) && is_string($response['uuid']) ? $response['uuid'] : null;
        $uuid = $this->validUuidOrNull($rawUuid);

        return [
            'present' => true,
            'status' => $this->safeStatus(isset($response['status']) && is_string($response['status']) ? $response['status'] : null),
            'uuid_state' => $rawUuid === null ? 'absent' : ($uuid === null ? 'invalid' : 'valid'),
            'uuid' => $this->maskNullableUuid($uuid),
            'uuid_matches_local' => $this->equals($localUuid, $uuid),
        ];
    }

    private function loadXmlSafely(string $xml, string $label): DOMDocument
    {
        if (trim($xml) === '' || ! str_starts_with(ltrim($xml), '<')) {
            throw new RuntimeException("El XML de {$label} esta vacio o no parece XML.");
        }

        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new RuntimeException("El XML de {$label} contiene un DOCTYPE no permitido.");
        }

        $previousInternalErrors = libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;

        try {
            $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousInternalErrors);
        }

        if (! $loaded || $errors !== [] || ! $dom->documentElement instanceof DOMElement) {
            throw new RuntimeException("El XML de {$label} esta mal formado.");
        }

        return $dom;
    }

    /** @param array<string, ?bool> $comparisons */
    private function scenario(array $comparisons): string
    {
        if ($comparisons['local_equals_remote'] === true
            && $comparisons['local_equals_receipt'] === true
            && $comparisons['local_equals_cfdi_xml'] === true) {
            return 'scenario_4_all_equal';
        }

        if ($comparisons['local_equals_cfdi_xml'] === true
            && $comparisons['local_equals_remote'] === false
            && $comparisons['remote_equals_receipt'] === true) {
            return 'scenario_3_remote_identity_differs_from_original';
        }

        if ($comparisons['remote_equals_receipt'] === true
            && $comparisons['local_equals_remote'] === false
            && $comparisons['remote_equals_cfdi_xml'] === false) {
            return 'scenario_2_external_id_points_to_different_remote_cfdi';
        }

        if ($comparisons['local_equals_remote'] === true
            && $comparisons['local_equals_cfdi_xml'] === true
            && $comparisons['local_equals_receipt'] === false) {
            return 'scenario_1_receipt_does_not_belong_to_expected_cfdi';
        }

        return 'undetermined';
    }

    private function equals(?string $left, ?string $right): ?bool
    {
        return $left === null || $right === null ? null : strcasecmp($left, $right) === 0;
    }

    /** @param list<string> $candidates */
    private function equalsAny(?string $uuid, array $candidates): ?bool
    {
        if ($uuid === null || $candidates === []) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if (strcasecmp($uuid, $candidate) === 0) {
                return true;
            }
        }

        return false;
    }

    private function validUuidOrNull(?string $uuid): ?string
    {
        if ($uuid === null) {
            return null;
        }

        $uuid = trim($uuid);

        return preg_match(self::UUID_PATTERN, $uuid) === 1 ? $uuid : null;
    }

    private function maskNullableUuid(?string $uuid): ?string
    {
        return $uuid === null ? null : $this->maskUuid($uuid);
    }

    private function maskUuid(string $uuid): string
    {
        return mb_substr($uuid, 0, 8).'...'.mb_substr($uuid, -4);
    }

    private function maskIdentifier(string $identifier): string
    {
        return mb_strlen($identifier) <= 12
            ? '[masked]'
            : mb_substr($identifier, 0, 8).'...'.mb_substr($identifier, -4);
    }

    private function safeStatus(?string $status): ?string
    {
        if ($status === null || preg_match('/^[a-z0-9_.-]{1,50}$/i', $status) !== 1) {
            return null;
        }

        return $status;
    }

    private function unsafeStoragePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/(^|[\\\\\/])\.\.([\\\\\/]|$)/', $path) === 1;
    }

    private function assertAuditable(Invoice $invoice): void
    {
        if ($invoice->pac_provider !== 'facturapi'
            || $invoice->pac_external_id === null
            || $invoice->cfdi_uuid === null
            || $invoice->pac_status !== 'canceled'
            || $invoice->cancellation_status !== 'accepted') {
            throw new RuntimeException(sprintf(
                'La factura [%d] no cumple las precondiciones de auditoria Facturapi TEST.',
                $invoice->id,
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
            throw (new ModelNotFoundException)->setModel(Invoice::class, [$invoice->getKey()]);
        }

        return $fresh;
    }
}
