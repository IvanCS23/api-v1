<?php

namespace App\Services\Catalogs;

use App\Models\Client;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ClientService
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    public function all(): Collection
    {
        return Client::all();
    }

    public function paginated(int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        return Client::query()
            ->orderBy((new Client)->getKeyName())
            ->paginate(perPage: $perPage, page: $page);
    }

    /**
     * int|string: las rutas /clients/{id} no restringen el id a números;
     * con un id no numérico findOrFail responde 404 (no un TypeError/500).
     *
     * @throws ModelNotFoundException
     */
    public function get(int|string $id): Client
    {
        return Client::findOrFail($id);
    }

    public function create(array $data): Client
    {
        return Client::create($data);
    }

    public function update(Client $client, array $data): Client
    {
        $client->update($data);

        return $client;
    }

    public function delete(Client $client): void
    {
        $client->delete();
    }
}
