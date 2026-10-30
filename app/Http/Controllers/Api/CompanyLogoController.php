<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Company\CompanyFiscalException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UploadCompanyLogoRequest;
use App\Http\Resources\CompanyFiscalResource;
use App\Models\Company;
use App\Services\Company\UploadCompanyLogoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fase 6.29 — Logotipo del PDF de la empresa autenticada: subirlo
 * (Facturapi primero) y ver su copia privada local.
 */
class CompanyLogoController extends Controller
{
    public function store(UploadCompanyLogoRequest $request, UploadCompanyLogoService $service): CompanyFiscalResource|JsonResponse
    {
        $company = Company::query()->findOrFail($request->user('api')->company_id);
        $this->authorize('updateFiscal', $company);

        try {
            $organization = $service->upload($request->user('api'), $company, $request->file('logo'));
        } catch (CompanyFiscalException $e) {
            return response()->json($e->toResponseArray(), $e->status);
        }

        return new CompanyFiscalResource($company->refresh(), $organization);
    }

    public function show(Request $request): Response
    {
        $company = Company::query()->findOrFail($request->user('api')->company_id);
        $this->authorize('viewFiscal', $company);

        $disk = Storage::disk(UploadCompanyLogoService::DISK);

        if (blank($company->logo) || ! $disk->exists($company->logo)) {
            return response()->json(['message' => 'La empresa no tiene un logotipo cargado.'], 404);
        }

        return response($disk->get($company->logo), 200, [
            'Content-Type' => str_ends_with($company->logo, '.png') ? 'image/png' : 'image/jpeg',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
