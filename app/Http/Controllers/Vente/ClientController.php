<?php

namespace App\Http\Controllers\Vente;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\Vente\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ClientController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = Auth::user();

        // Admin sees all, vendeur sees only their clients
        if ($user && $user->role === 'admin') {
            $clients = Client::all();
        } elseif ($user && $user->role === 'vendeur') {
            $clients = Client::where('utilisateur_id', $user->id)->get();
        } else {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        return response()->json($clients);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        // Not used for API
        return response()->json(['message' => 'Not implemented'], 404);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreClientRequest $request)
    {
        $user = Auth::user();
        if (!$user || !in_array($user->role, ['admin', 'vendeur'])) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $data = $request->validated();
        // Set owner to the authenticated vendeur/admin
        $data['utilisateur_id'] = $user->id;

        $client = Client::create($data);

        return response()->json($client, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Client $client)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        if ($user->role === 'admin' || ($user->role === 'vendeur' && $client->utilisateur_id == $user->id)) {
            return response()->json($client);
        }

        return response()->json(['message' => 'Accès non autorisé'], 403);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Client $client)
    {
        // Not used for API
        return response()->json(['message' => 'Not implemented'], 404);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateClientRequest $request, Client $client)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        if ($user->role === 'admin' || ($user->role === 'vendeur' && $client->utilisateur_id == $user->id)) {
            $client->update($request->validated());
            return response()->json($client);
        }

        return response()->json(['message' => 'Accès non autorisé'], 403);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Client $client)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        if ($user->role === 'admin' || ($user->role === 'vendeur' && $client->utilisateur_id == $user->id)) {
            $client->delete();
            return response()->noContent();
        }

        return response()->json(['message' => 'Accès non autorisé'], 403);
    }
}
