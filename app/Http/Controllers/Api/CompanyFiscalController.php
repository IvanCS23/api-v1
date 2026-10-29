<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Company\CompanyFiscalException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCompanyFiscalRequest;
use App\Http\Resources\CompanyFiscalResource;
use App\Models\Company;
use App\Models\FacturapiOrganization;
use App\Services\Company\UpdateCompanyFiscalDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fase 6.27b — Datos fiscales de la empresa del usuario autenticado.
 * GET: Admin o Accountant. PUT: sólo Admin, sincronizado primero con
 * Facturapi (UpdateCompanyFiscalDataService).
 */
class CompanyFiscalController extends Controller
{
    public function show(Request $request): CompanyFiscalResource
    {
        $company = $this->company($request);
        $this->authorize('viewFiscal', $company);

        return $this->resource($company);
    }

    public function update(UpdateCompanyFiscalRequest $request, UpdateCompanyFiscalDataService $service): CompanyFiscalResource|JsonResponse
    {
        $company = $this->company($request);
        $this->authorize('updateFiscal', $company);

        try {
            $company = $service->update($request->user('api'), $company, $request->validated());
        } catch (CompanyFiscalException $e) {
            return response()->json($e->toResponseArray(), $e->status);
        }

        return $this->resource($company);
    }

    private function company(Request $request): Company
    {
        return Company::query()->findOrFail($request->user('api')->company_id);
    }

    private function resource(Company $company): CompanyFiscalResource
    {
        return new CompanyFiscalResource($company, FacturapiOrganization::query()->where('company_id', $company->id)->first());
    }
}
