<?php

namespace App\Services\Catalogs;

use App\Models\Employe;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Lógica de negocio de empleados (CRUD y paginado).
 *
 * La autorización (policies) NO se hace aquí: es responsabilidad de la
 * capa HTTP. El servicio recibe datos ya validados, no el Request.
 */
class EmployeService
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    public function all(): Collection
    {
        return Employe::all();
    }

    public function paginated(int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        // Orden explícito para que las páginas sean estables entre peticiones.
        return Employe::query()
            ->orderBy((new Employe)->getKeyName())
            ->paginate(perPage: $perPage, page: $page);
    }

    public function get(int|string $id): Employe
    {
        return Employe::findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data  Datos validados.
     */
    public function create(array $data): Employe
    {
        return Employe::create($data);
    }

    /**
     * @param  array<string, mixed>  $data  Datos validados.
     */
    public function update(Employe $employe, array $data): Employe
    {
        $employe->update($data);

        return $employe;
    }

    public function delete(Employe $employe): void
    {
        $employe->delete();
    }
}
