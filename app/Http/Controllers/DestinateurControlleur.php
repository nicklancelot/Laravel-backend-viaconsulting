<?php

namespace App\Http\Controllers;

use App\Models\Destinateur;
use App\Models\Utilisateur;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class DestinateurControlleur extends Controller
{
    public function index(): JsonResponse
    {
        $destinateurs = Destinateur::with('createur:id,nom,prenom,role')->get();
        return response()->json($destinateurs);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom_entreprise' => 'required|string|max:255',
            'nom_prenom' => 'required|string|max:255',
            'contact' => 'required|string|max:255',
            'observation' => 'nullable|string',
        ]);

        // Récupérer l'utilisateur authentifié
        $user = Auth::user();
        $validated['created_by'] = $user->id;

        $destinateur = Destinateur::create($validated);

        // Charger les informations du créateur dans la réponse
        $destinateur->load('createur:id,nom,prenom,role');

        return response()->json($destinateur, 201);
    }

    public function show($id): JsonResponse
    {
        $destinateur = Destinateur::with('createur:id,nom,prenom,role')->findOrFail($id);
        return response()->json($destinateur);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $destinateur = Destinateur::findOrFail($id);

        $validated = $request->validate([
            'nom_entreprise' => 'sometimes|required|string|max:255',
            'nom_prenom' => 'sometimes|required|string|max:255',
            'contact' => 'sometimes|required|string|max:255',
            'observation' => 'nullable|string',
        ]);

        $destinateur->update($validated);

        // Recharger avec les informations du créateur
        $destinateur->load('createur:id,nom,prenom,role');

        return response()->json($destinateur);
    }

    public function destroy($id): JsonResponse
    {
        $destinateur = Destinateur::findOrFail($id);
        $destinateur->delete();

        return response()->json(['message' => 'Destinateur supprimé avec succès']);
    }

    // Méthode pour récupérer les destinateurs créés par un utilisateur spécifique
    public function getByUser($userId): JsonResponse
    {
        $destinateurs = Destinateur::with('createur:id,nom,prenom,role')
            ->where('created_by', $userId)
            ->get();
        
        return response()->json($destinateurs);
    }
}