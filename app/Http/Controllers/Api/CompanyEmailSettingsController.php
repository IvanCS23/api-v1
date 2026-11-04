<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Billing\SendInvoiceEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fase 6.30 — Configuración del envío de facturas por correo: el envío
 * automático tras el timbrado (sólo Admin lo cambia) y el uso del tope
 * diario de la empresa. Admin y Contador la consultan.
 */
class CompanyEmailSettingsController extends Controller
{
    public function __construct(private readonly SendInvoiceEmailService $email) {}

    public function show(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $this->authorize('viewFiscal', $company);

        return $this->respond($request, $company);
    }

    public function update(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $this->authorize('updateFiscal', $company);

        $validated = $request->validate(['auto_email_invoices' => ['required', 'boolean']]);
        $company->forceFill(['auto_email_invoices' => (bool) $validated['auto_email_invoices']])->save();

        return $this->respond($request, $company->refresh());
    }

    private function respond(Request $request, Company $company): JsonResponse
    {
        return response()->json([
            'auto_email_invoices' => (bool) $company->auto_email_invoices,
            'pac_mode' => in_array(config('billing.pac_mode'), ['test', 'live'], true) ? config('billing.pac_mode') : null,
            'daily_limit' => $this->email->dailyLimit(),
            'sent_last_24h' => $this->email->sentInLastDay((int) $company->id),
            'can_edit' => (bool) $request->user('api')?->can('updateFiscal', $company),
        ]);
    }

    private function company(Request $request): Company
    {
        return Company::query()->findOrFail($request->user('api')->company_id);
    }
}
