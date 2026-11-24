<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Services\Catalogs\ClientService;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function __construct(private readonly ClientService $clients) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Client::class);

        if ($request->has('page')) {
            return ClientResource::collection(
                $this->clients->paginated(
                    (int) $request->query('page', 1),
                    (int) $request->query('per_page', ClientService::DEFAULT_PER_PAGE),
                )
            );
        }

        return ClientResource::collection($this->clients->all());
    }

    public function store(StoreClientRequest $request)
    {
        $this->authorize('create', Client::class);

        $client = $this->clients->create($request->validated());

        return (new ClientResource($client))->response()->setStatusCode(201);
    }

    public function show($id)
    {
        $client = $this->clients->get($id);

        $this->authorize('view', $client);

        return new ClientResource($client);
    }

    public function update(UpdateClientRequest $request, $id)
    {
        $client = $this->clients->get($id);

        $this->authorize('update', $client);

        $client = $this->clients->update($client, $request->validated());

        return new ClientResource($client);
    }

    public function destroy($id)
    {
        $client = $this->clients->get($id);

        $this->authorize('delete', $client);

        $this->clients->delete($client);

        return response()->json(null, 204);
    }
}
