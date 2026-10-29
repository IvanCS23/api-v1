<?php

namespace App\Services\Company;

use App\Enums\FacturapiOrganizationAuditAction;
use App\Exceptions\Company\CompanyFiscalException;
use App\Infrastructure\Billing\Facturapi\FacturapiOrganizationClient;
use App\Models\Company;
use App\Models\FacturapiOrganization;
use App\Models\User;
use App\Services\Billing\FacturapiOrganizationAuditor;
use App\Services\Sat\SatFiscalValidator;
use App\Support\Sat\CsdCertificateReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SensitiveParameter;
use Throwable;

/**
 * Fase 6.27b — CSD de la empresa, enviado directo a Facturapi.
 *
 * Antes de cualquier petición lee el .cer en memoria y rechaza un
 * certificado ilegible, una FIEL, uno vencido o uno de otro RFC: subirlo
 * cambiaría el RFC de la organización en Facturapi sin forma de deshacerlo.
 * La .key no se lee: sólo Facturapi la descifra con la contraseña.
 *
 * Nunca se guardan los archivos ni la contraseña (ni en disco, ni en la
 * BD, ni en logs). Con 200 se actualiza la copia local del certificado y
 * se audita con el usuario, la serie y la vigencia.
 */
final class UploadCompanyCsdService
{
    public function __construct(
        private readonly CsdCertificateReader $reader,
        private readonly FacturapiOrganizationClient $organizations,
        private readonly FacturapiOrganizationAuditor $auditor,
    ) {}

    public function upload(
        User $actor,
        Company $company,
        string $cer,
        #[SensitiveParameter] string $key,
        #[SensitiveParameter] string $password,
    ): FacturapiOrganization {
        $organization = FacturapiOrganization::query()->where('company_id', $company->id)->first();

        if ($organization === null) {
            throw new CompanyFiscalException('COMPANY_PAC_ORGANIZATION_MISSING', 'La empresa no tiene organización de Facturapi. La crea o la vincula el operador del sistema.', 409);
        }

        try {
            $certificate = $this->reader->read($cer);
        } catch (InvalidArgumentException $e) {
            throw new CompanyFiscalException('COMPANY_CSD_INVALID', $e->getMessage(), 422, [['code' => 'COMPANY_CSD_INVALID', 'field' => 'cer', 'message' => $e->getMessage()]]);
        }

        if (! $certificate->isCsd()) {
            throw new CompanyFiscalException('COMPANY_CSD_IS_FIEL', 'El archivo es una e.firma (FIEL), no un Certificado de Sello Digital.', 422, [['code' => 'COMPANY_CSD_IS_FIEL', 'field' => 'cer', 'message' => 'Usa el Certificado de Sello Digital (CSD), no la e.firma (FIEL).']]);
        }

        if ($certificate->isExpiredAt(CarbonImmutable::now())) {
            throw new CompanyFiscalException('COMPANY_CSD_EXPIRED', 'El certificado está vencido.', 422, [['code' => 'COMPANY_CSD_EXPIRED', 'field' => 'cer', 'message' => "El certificado venció el {$certificate->validTo->toDateString()}."]]);
        }

        $companyRfc = SatFiscalValidator::normalizeRfc($company->rfc);

        if ($certificate->rfc !== $companyRfc) {
            throw new CompanyFiscalException('COMPANY_RFC_CSD_MISMATCH', 'El certificado es de otro RFC.', 422, [[
                'code' => 'COMPANY_RFC_CSD_MISMATCH',
                'field' => 'cer',
                'message' => "El certificado es del RFC {$certificate->rfc} y la empresa tiene {$companyRfc}. Si el RFC de la empresa es incorrecto, corrígelo primero en Datos fiscales.",
            ]]);
        }

        try {
            $snapshot = $this->organizations->uploadCertificate($organization->organization_id, $cer, $key, $password);
        } catch (Throwable $e) {
            throw FacturapiOrganizationErrors::translate($e, $company->id, 'csd_upload', 'COMPANY_CSD_REJECTED', 'Facturapi rechazó el CSD:');
        }

        DB::transaction(function () use ($actor, $company, $organization, $snapshot, $certificate): void {
            $organization->fill($snapshot->toAttributes())->save();
            $this->auditor->record($company->id, $organization->organization_id, FacturapiOrganizationAuditAction::CertificateUploaded, $actor, [
                'serial_number' => $snapshot->certificateSerialNumber ?? $certificate->serialNumber,
                'expires_at' => ($snapshot->certificateExpiresAt ?? $certificate->validTo)->toIso8601String(),
                'rfc' => $certificate->rfc,
            ]);
        });

        return $organization->refresh();
    }
}
