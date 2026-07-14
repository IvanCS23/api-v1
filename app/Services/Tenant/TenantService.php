<?php

namespace App\Services\Tenant;

use App\Models\Company;
use Illuminate\Http\Request;

final readonly class TenantService
{
    public function __construct(private Request $request) {}

    public function current(): ?Company
    {
        $company = $this->request->attributes->get('tenant.company');

        if ($company instanceof Company) {
            return $company;
        }

        $companyId = $this->currentId();

        if ($companyId === null) {
            return null;
        }

        return Company::query()->find($companyId);
    }

    public function currentId(): ?int
    {
        $companyId = $this->request->attributes->get('tenant.company_id');

        if (is_int($companyId)) {
            return $companyId;
        }

        if (is_string($companyId) && is_numeric($companyId)) {
            return (int) $companyId;
        }

        $company = $this->request->attributes->get('tenant.company');

        return $company instanceof Company ? $company->getKey() : null;
    }

    public function hasCompany(): bool
    {
        return $this->currentId() !== null;
    }
}
