<?php

namespace App\Support\Tenant;

/**
 * Contenedor del company_id "activo" en el proceso actual.
 *
 * Se registra como singleton (ver AppServiceProvider) para que sobreviva
 * durante toda la request. En HTTP, TenantMiddleware lo puebla desde el
 * usuario autenticado y lo limpia SIEMPRE al finalizar (éxito, excepción,
 * 404, 403 — ver TenantMiddleware::handle(), usa try/finally) para que
 * nunca sobreviva de una request a la siguiente dentro del mismo proceso
 * (relevante bajo servidores persistentes tipo Octane, y para que los
 * tests no se contaminen entre sí).
 *
 * En consola/Jobs, donde no existe un Request, debe poblarse y limpiarse
 * explícitamente:
 *
 *   app(CurrentTenant::class)->set($this->companyId);
 *   try {
 *       // ... trabajo del job/comando ...
 *   } finally {
 *       app(CurrentTenant::class)->clear();
 *   }
 *
 * Ningún Job existe todavía en el proyecto (fuera de alcance de esta
 * etapa); este es el patrón a seguir cuando se agreguen (ver también
 * ERP_ARCHITECTURE_PLAN.md).
 */
class CurrentTenant
{
    private ?int $companyId = null;

    public function set(int $companyId): void
    {
        $this->companyId = $companyId;
    }

    public function id(): ?int
    {
        return $this->companyId;
    }

    public function has(): bool
    {
        return $this->companyId !== null;
    }

    public function clear(): void
    {
        $this->companyId = null;
    }
}
