<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Client;

class ClientController extends Controller
{
    public function index()
    {
        $clients = Client::all();

        return response()->json($clients);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'rfc' => 'required|string|max:13',
            'codigo_postal' => 'required|string|max:10',
            'regimen_fiscal' => 'required|string|max:10',
            'uso_cfdi' => 'required|string|max:10',
            'calle' => 'nullable|string|max:255',
            'no_exterior' => 'nullable|string|max:20',
            'no_interior' => 'nullable|string|max:20',
            'colonia' => 'nullable|string|max:255',
            'localidad' => 'nullable|string|max:255',
            'municipio' => 'nullable|string|max:255',
            'estado' => 'nullable|string|max:255',
            'pais' => 'nullable|string|max:255',
        ]);

        $client = Client::create($validated);
        return response()->json($client, 201);
    }

    public function show($id)
    {
        $client = Client::findOrFail($id);
        return response()->json($client);
    }

    public function update(Request $request, $id)
    {
        $client = Client::findOrFail($id);
        
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|max:255',
            'rfc' => 'sometimes|required|string|max:13',
            'codigo_postal' => 'sometimes|required|string|max:10',
            'regimen_fiscal' => 'sometimes|required|string|max:10',
            'uso_cfdi' => 'sometimes|required|string|max:10',
            'calle' => 'nullable|string|max:255',
            'no_exterior' => 'nullable|string|max:20',
            'no_interior' => 'nullable|string|max:20',
            'colonia' => 'nullable|string|max:255',
            'localidad' => 'nullable|string|max:255',
            'municipio' => 'nullable|string|max:255',
            'estado' => 'nullable|string|max:255',
            'pais' => 'nullable|string|max:255',
        ]);

        $client->update($validated);
        return response()->json($client);
    }

    public function destroy($id)
    {
        $client = Client::findOrFail($id);
        $client->delete();
        return response()->json(null, 204);
    }
}