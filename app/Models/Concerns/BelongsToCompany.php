<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Aísla un modelo por empresa (multi-tenant).
 *
 * - Agrega el Global Scope (CompanyScope) que filtra toda consulta.
 * - Al crear: si company_id ya viene fijado (fuera de $fillable, así que
 *   solo puede venir de código de confianza: una factory bajo
 *   Model::unguarded(), un seeder, o asignación directa antes de save()),
 *   se respeta tal cual — así siguen funcionando las factories y los
 *   tests que simulan una empresa específica. Si NO viene fijado, se toma
 *   de CurrentTenant. Si tampoco hay tenant activo, se lanza una
 *   excepción — nunca se guarda company_id NULL en silencio.
 * - Al actualizar: revierte cualquier intento de cambiar company_id al
 *   valor original, sin importar el mecanismo (fill(), update(),
 *   asignación directa + save(), payload malicioso). company_id no es
 *   editable una vez creado el registro.
 *
 * company_id NO se agrega a $fillable en los modelos que usan este
 * trait: así ningún payload de request puede fijarlo por mass
 * assignment (defensa en profundidad, además del guard de creación).
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope());

        static::creating(function (Model $model): void {
            if ($model->getAttribute('company_id') !== null) {
                return;
            }

            $companyId = app(CurrentTenant::class)->id();

            if ($companyId === null) {
                throw new RuntimeException(sprintf(
                    'No se puede crear un [%s] sin un tenant activo. '.
                    'CurrentTenant está vacío y no se proporcionó company_id explícito. '.
                    'En consola/seeders/jobs, fija el tenant antes de crear: app(%s::class)->set($companyId).',
                    static::class,
                    CurrentTenant::class,
                ));
            }

            $model->setAttribute('company_id', $companyId);
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('company_id')) {
                $model->setAttribute('company_id', $model->getOriginal('company_id'));
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
