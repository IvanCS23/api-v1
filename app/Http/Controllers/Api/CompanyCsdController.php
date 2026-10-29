<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Company\CompanyFiscalException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UploadCompanyCsdRequest;
use App\Http\Resources\CompanyFiscalResource;
use App\Models\Company;
use App\Services\Company\UploadCompanyCsdService;
use Illuminate\Http\JsonResponse;

/**
 * Fase 6.27b — Sube el CSD de la empresa del usuario a su organización de
 * Facturapi. Sólo Admin, con throttle:company-csd. Responde los datos
 * fiscales con el estado nuevo del certificado.
 */
class CompanyCsdController extends Controller
{
    public function store(UploadCompanyCsdRequest $request, UploadCompanyCsdService $service): CompanyFiscalResource|JsonResponse
    {
        $company = Company::query()->findOrFail($request->user('api')->company_id);
        $this->authorize('updateFiscal', $company);

        try {
            $organization = $service->upload(
                $request->user('api'),
                $company,
                (string) $request->file('cer')?->get(),
                (string) $request->file('key')?->get(),
                (string) $request->input('csd_password'),
            );
        } catch (CompanyFiscalException $e) {
            return response()->json($e->toResponseArray(), $e->status);
        }

        return new CompanyFiscalResource($company, $organization);
    }
}
