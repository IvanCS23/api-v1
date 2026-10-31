<?php

namespace App\Enums;

enum FacturapiOrganizationAuditAction: string
{
    case OrganizationCreated = 'organization_created';
    case TestKeyStored = 'test_key_stored';
    case TestKeyReplaced = 'test_key_replaced';
    case LegalUpdated = 'legal_updated';
    case CertificateUploaded = 'certificate_uploaded';

    // Fase 6.29: logotipo del PDF.
    case LogoUploaded = 'logo_uploaded';
}
