<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;

class ClientController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Client::class);

        return ClientResource::collection(Client::all());
    }

    public function store(StoreClientRequest $request)
    {
        $this->authorize('create', Client::class);

        $client = Client::create($request->validated());

        return (new ClientResource($client))->response()->setStatusCode(201);
    }

    public function show($id)
    {
        $client = Client::findOrFail($id);

        $this->authorize('view', $client);

        return new ClientResource($client);
    }

    public function update(UpdateClientRequest $request, $id)
    {
        $client = Client::findOrFail($id);

        $this->authorize('update', $client);

        $client->update($request->validated());

        return new ClientResource($client);
    }

    public function destroy($id)
    {
        $client = Client::findOrFail($id);

        $this->authorize('delete', $client);

        $client->delete();

        return response()->json(null, 204);
    }
}
