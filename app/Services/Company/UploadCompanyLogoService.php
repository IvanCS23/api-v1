<?php

namespace App\Services\Company;

use App\Enums\FacturapiOrganizationAuditAction;
use App\Exceptions\Company\CompanyFiscalException;
use App\Infrastructure\Billing\Facturapi\FacturapiOrganizationClient;
use App\Models\Company;
use App\Models\FacturapiOrganization;
use App\Models\User;
use App\Services\Billing\FacturapiOrganizationAuditor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Fase 6.29 — Logotipo del PDF. Facturapi lo imprime en el PDF y en los
 * correos del CFDI; si la organización no tiene uno propio, el PDF muestra
 * el que tenga cargado (en desarrollo, una imagen con "Tecnologias S.A").
 *
 * Facturapi primero, como los datos fiscales: la copia privada local (para
 * la vista previa de Datos fiscales) se escribe antes con un nombre nuevo y
 * se borra si Facturapi rechaza el archivo, así que un rechazo no cambia
 * nada. Al aceptarlo se guarda la ruta, se borra la copia anterior y se
 * audita (huella, tamaño y tipo; nunca el contenido).
 */
final class UploadCompanyLogoService
{
    public const DISK = 'local';

    public function __construct(
        private readonly FacturapiOrganizationClient $organizations,
        private readonly FacturapiOrganizationAuditor $auditor,
    ) {}

    public function upload(User $actor, Company $company, UploadedFile $file): FacturapiOrganization
    {
        $organization = FacturapiOrganization::query()->where('company_id', $company->id)->first();

        if ($organization === null) {
            throw new CompanyFiscalException('COMPANY_PAC_ORGANIZATION_MISSING', 'La empresa no tiene organización de Facturapi. La crea o la vincula el operador del sistema.', 409);
        }

        $contents = (string) $file->get();
        $sha256 = hash('sha256', $contents);
        $extension = $file->getMimeType() === 'image/png' ? 'png' : 'jpg';
        $path = "company-logos/{$company->id}/logo-".substr($sha256, 0, 16).".{$extension}";
        $previous = $company->logo;
        $storage = Storage::disk(self::DISK);

        $storage->put($path, $contents);

        try {
            $snapshot = $this->organizations->uploadLogo($organization->organization_id, $contents, "logo.{$extension}");
        } catch (Throwable $e) {
            if ($path !== $previous) {
                $storage->delete($path);
            }

            throw FacturapiOrganizationErrors::translate($e, $company->id, 'logo_upload', 'COMPANY_LOGO_REJECTED', 'Facturapi rechazó el logotipo:');
        }

        DB::transaction(function () use ($actor, $company, $organization, $snapshot, $path, $sha256, $contents, $extension): void {
            $company->forceFill(['logo' => $path, 'logo_updated_at' => now()])->save();
            $organization->fill($snapshot->toAttributes())->save();
            $this->auditor->record($company->id, $organization->organization_id, FacturapiOrganizationAuditAction::LogoUploaded, $actor, [
                'sha256' => $sha256,
                'bytes' => strlen($contents),
                'type' => $extension,
            ]);
        });

        if (filled($previous) && $previous !== $path) {
            $storage->delete($previous);
        }

        return $organization->refresh();
    }
}
