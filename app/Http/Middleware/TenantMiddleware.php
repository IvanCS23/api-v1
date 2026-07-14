<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = $request->user()?->company;

        $request->attributes->set('tenant.company', $company);
        $request->attributes->set('tenant.company_id', $company?->getKey());

        return $next($request);
    }
}
