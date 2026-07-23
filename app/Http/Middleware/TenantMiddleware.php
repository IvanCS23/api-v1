<?php

namespace App\Http\Middleware;

use App\Support\Tenant\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puebla CurrentTenant desde el usuario autenticado para toda la duración
 * de la request, y SIEMPRE lo limpia al finalizar — éxito, excepción, 404,
 * 403, lo que sea — vía try/finally, para que nunca sobreviva de una
 * request a la siguiente dentro del mismo proceso.
 *
 * IMPORTANTE — orden de ejecución: este middleware depende de que
 * $request->user() ya esté resuelto, es decir, debe ejecutarse DESPUÉS
 * de auth:api. Está registrado a nivel del grupo "api" (bootstrap/app.php)
 * mientras que auth:api se aplica a nivel de ruta (routes/api.php); pese
 * a eso, el orden real de ejecución es correcto porque Laravel reordena
 * el pipeline según Illuminate\Foundation\Http\Kernel::$middlewarePriority
 * (que incluye el contrato AuthenticatesRequests, implementado por
 * Illuminate\Auth\Middleware\Authenticate, el que resuelve el alias
 * "auth") SIN IMPORTAR si la declaración vino del grupo o de la ruta.
 * Esto está verificado empíricamente con una petición HTTP real (no solo
 * inferido) en tests/Feature/Tenant/TenantLifecycleTest.php.
 */
class TenantMiddleware
{
    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $company = $request->user()?->company;

        $request->attributes->set('tenant.company', $company);
        $request->attributes->set('tenant.company_id', $company?->getKey());

        if ($company !== null) {
            $this->currentTenant->set($company->getKey());
        }

        try {
            return $next($request);
        } finally {
            $this->currentTenant->clear();
        }
    }
}
