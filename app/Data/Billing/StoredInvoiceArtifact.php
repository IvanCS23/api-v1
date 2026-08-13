<?php

namespace App\Data\Billing;

final readonly class StoredInvoiceArtifact
{
    public function __construct(
        public string $contents,
        public string $contentType,
        public string $filename,
    ) {}
}
