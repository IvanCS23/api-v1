<?php

namespace App\Models\Scopes;

use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtra toda consulta por el company_id activo (CurrentTenant).
 *
 * Comportamiento — fail closed, nunca "todas las filas" por accidente:
 *
 * 1. HTTP autenticado CON tenant resuelto (caso normal): filtra por ese
 *    company_id.
 * 2. HTTP autenticado SIN tenant resuelto (no debería ocurrir si
 *    TenantMiddleware corre correctamente — ver esa clase — pero se
 *    cubre como defensa en profundidad): aplica una condición imposible
 *    (`1 = 0`). CERO filas. Nunca todas.
 * 3. Consola, migraciones, seeders, tareas administrativas: por defecto
 *    también obtienen CERO filas (no hay tenant activo), no "todas". Si
 *    de verdad necesitan ver todo, deben pedirlo explícitamente con
 *    Model::withoutGlobalScope(CompanyScope::class) — eso quita el scope
 *    por completo, es la única vía real de acceso global.
 * 4. Jobs en cola: no hay Request. Deben fijar el tenant explícitamente
 *    al inicio de handle() con app(CurrentTenant::class)->set($id), y
 *    limpiarlo al final (idealmente en un finally), igual que hace
 *    TenantMiddleware para HTTP. Ningún Job existe todavía en el
 *    proyecto; ver también ERP_ARCHITECTURE_PLAN.md.
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $companyId = app(CurrentTenant::class)->id();

        if ($companyId !== null) {
            $builder->where($model->qualifyColumn('company_id'), $companyId);

            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
