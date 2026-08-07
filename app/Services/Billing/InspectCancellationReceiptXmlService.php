<?php

namespace App\Services\Billing;

use App\Contracts\Billing\PacProvider;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenant\CurrentTenant;
use DOMAttr;
use DOMDocument;
use DOMElement;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use RuntimeException;

/**
 * Diagnostico de solo lectura para conocer la forma de un acuse real.
 *
 * Nunca devuelve el XML ni contenido textual arbitrario: limita la salida a
 * nombres/rutas estructurales, namespaces y UUID exactos ya enmascarados.
 */
class InspectCancellationReceiptXmlService
{
    private const MAX_STRUCTURAL_ITEMS = 100;

    private const UUID_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    public function __construct(private readonly PacProvider $pacProvider) {}

    /**
     * @return array{
     *     root: string,
     *     root_namespace: string,
     *     namespaces: list<string>,
     *     elements: list<string>,
     *     uuid_fields: list<array{kind: string, location: string, name: string}>,
     *     uuid_candidates: list<array{kind: string, location: string, name: string, value: string, matches_invoice: bool}>
     * }
     */
    public function inspect(Invoice $invoice): array
    {
        $current = $this->requireCurrentTenantInvoice($invoice);
        $this->assertReceiptCanBeInspected($current);

        $xml = $this->pacProvider->downloadCancellationReceiptXml((string) $current->pac_external_id);

        return $this->inspectXml($current, $xml);
    }

    /**
     * @return array{
     *     root: string,
     *     root_namespace: string,
     *     namespaces: list<string>,
     *     elements: list<string>,
     *     uuid_fields: list<array{kind: string, location: string, name: string}>,
     *     uuid_candidates: list<array{kind: string, location: string, name: string, value: string, matches_invoice: bool}>
     * }
     */
    private function inspectXml(Invoice $invoice, string $xml): array
    {
        if (trim($xml) === '' || ! str_starts_with(ltrim($xml), '<')) {
            throw new RuntimeException('El acuse XML esta vacio o no parece XML.');
        }

        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new RuntimeException('El acuse XML contiene un DOCTYPE no permitido.');
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

        $root = $dom->documentElement;

        if (! $loaded || $errors !== [] || ! $root instanceof DOMElement) {
            throw new RuntimeException('El acuse XML esta mal formado.');
        }

        $namespaces = [];
        $elements = [];
        $uuidFields = [];
        $uuidCandidates = [];

        /** @var DOMElement $element */
        foreach ($dom->getElementsByTagName('*') as $element) {
            $path = $this->elementPath($element);
            $this->appendUnique($elements, $path);

            if (is_string($element->namespaceURI) && $element->namespaceURI !== '') {
                $this->appendUnique($namespaces, $this->sanitizeNamespace($element->namespaceURI));
            }

            if ($this->looksLikeUuidField($element->localName)) {
                $this->appendUniqueRecord($uuidFields, [
                    'kind' => 'element',
                    'location' => $path,
                    'name' => $element->localName,
                ]);
            }

            $text = $this->directText($element);

            if ($this->isUuid($text)) {
                $uuidCandidates[] = $this->candidate(
                    $invoice,
                    'element_text',
                    $path,
                    $element->localName,
                    $text,
                );
            }

            /** @var DOMAttr $attribute */
            foreach ($element->attributes as $attribute) {
                if ($this->looksLikeUuidField($attribute->localName)) {
                    $this->appendUniqueRecord($uuidFields, [
                        'kind' => 'attribute',
                        'location' => $path.'/@'.$attribute->localName,
                        'name' => $attribute->localName,
                    ]);
                }

                if ($this->isUuid(trim($attribute->value))) {
                    $uuidCandidates[] = $this->candidate(
                        $invoice,
                        'attribute',
                        $path.'/@'.$attribute->localName,
                        $attribute->localName,
                        trim($attribute->value),
                    );
                }

                if (count($uuidCandidates) >= self::MAX_STRUCTURAL_ITEMS) {
                    break;
                }
            }

            if (count($elements) >= self::MAX_STRUCTURAL_ITEMS) {
                break;
            }
        }

        return [
            'root' => $root->localName,
            'root_namespace' => $this->sanitizeNamespace((string) $root->namespaceURI),
            'namespaces' => $namespaces,
            'elements' => $elements,
            'uuid_fields' => array_slice($uuidFields, 0, self::MAX_STRUCTURAL_ITEMS),
            'uuid_candidates' => array_slice($uuidCandidates, 0, self::MAX_STRUCTURAL_ITEMS),
        ];
    }

    /**
     * @return array{kind: string, location: string, name: string, value: string, matches_invoice: bool}
     */
    private function candidate(
        Invoice $invoice,
        string $kind,
        string $location,
        string $name,
        string $value,
    ): array {
        return [
            'kind' => $kind,
            'location' => $location,
            'name' => $name,
            'value' => $this->maskUuid($value),
            'matches_invoice' => strcasecmp($value, (string) $invoice->cfdi_uuid) === 0,
        ];
    }

    private function elementPath(DOMElement $element): string
    {
        $parts = [];
        $current = $element;

        while ($current instanceof DOMElement) {
            array_unshift($parts, $current->localName);
            $current = $current->parentNode;
        }

        return '/'.implode('/', $parts);
    }

    private function directText(DOMElement $element): string
    {
        $text = '';

        foreach ($element->childNodes as $child) {
            if (in_array($child->nodeType, [XML_TEXT_NODE, XML_CDATA_SECTION_NODE], true)) {
                $text .= $child->nodeValue;
            }
        }

        return trim($text);
    }

    private function isUuid(string $value): bool
    {
        return preg_match(self::UUID_PATTERN, $value) === 1;
    }

    private function looksLikeUuidField(string $name): bool
    {
        return preg_match('/uuid|folio.?fiscal/i', $name) === 1;
    }

    private function maskUuid(string $uuid): string
    {
        return mb_substr($uuid, 0, 8).'...'.mb_substr($uuid, -4);
    }

    private function sanitizeNamespace(string $namespace): string
    {
        if ($namespace === '') {
            return '(none)';
        }

        $namespace = preg_replace(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            '[uuid-redacted]',
            $namespace,
        ) ?? '[redacted]';
        $namespace = preg_replace('/[?#].*\z/', '', $namespace) ?? '[redacted]';

        return mb_substr($namespace, 0, 200);
    }

    /** @param list<string> $items */
    private function appendUnique(array &$items, string $value): void
    {
        if (count($items) < self::MAX_STRUCTURAL_ITEMS && ! in_array($value, $items, true)) {
            $items[] = $value;
        }
    }

    /**
     * @param  list<array{kind: string, location: string, name: string}>  $items
     * @param  array{kind: string, location: string, name: string}  $value
     */
    private function appendUniqueRecord(array &$items, array $value): void
    {
        if (count($items) < self::MAX_STRUCTURAL_ITEMS && ! in_array($value, $items, true)) {
            $items[] = $value;
        }
    }

    private function assertReceiptCanBeInspected(Invoice $invoice): void
    {
        if ($invoice->pac_external_id === null || $invoice->cfdi_uuid === null) {
            throw new RuntimeException(sprintf('La factura [%d] requiere pac_external_id y cfdi_uuid.', $invoice->id));
        }

        if ($invoice->pac_status !== 'canceled' || $invoice->cancellation_status !== 'accepted') {
            throw new RuntimeException(sprintf(
                'La factura [%d] requiere pac_status=canceled y cancellation_status=accepted.',
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
