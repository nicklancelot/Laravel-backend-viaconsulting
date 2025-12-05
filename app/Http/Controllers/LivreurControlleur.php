<?php

namespace App\Http\Controllers;

use App\Models\Livreur;
use App\Models\Utilisateur;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class LivreurControlleur extends Controller
{
    public function index(): JsonResponse
    {
        $livreurs = Livreur::with('createur:id,nom,prenom,role')->get();
        return response()->json($livreurs);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom' => 'required|string|max:255',
            'prenom' => 'required|string|max:255',
            'cin' => 'required|string|max:255|unique:livreurs,cin',
            'date_naissance' => 'required|date',
            'lieu_naissance' => 'required|string|max:255',
            'date_delivrance_cin' => 'required|date',
            'contact_famille' => 'required|string|max:255',
            'telephone' => 'required|string|max:255',
            'numero_vehicule' => 'required|string|max:255',
            'observation' => 'nullable|string',
            'zone_livraison' => 'required|string|max:255',
        ]);

        // Récupérer l'utilisateur authentifié
        $user = Auth::user();
        $validated['created_by'] = $user->id;

        $livreur = Livreur::create($validated);

        // Charger les informations du créateur dans la réponse
        $livreur->load('createur:id,nom,prenom,role');

        return response()->json($livreur, 201);
    }

    public function show($id): JsonResponse
    {
        $livreur = Livreur::with('createur:id,nom,prenom,role')->findOrFail($id);
        return response()->json($livreur);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $livreur = Livreur::findOrFail($id);

        $validated = $request->validate([
            'nom' => 'sometimes|required|string|max:255',
            'prenom' => 'sometimes|required|string|max:255',
            'cin' => 'sometimes|required|string|max:255|unique:livreurs,cin,' . $livreur->id,
            'date_naissance' => 'sometimes|required|date',
            'lieu_naissance' => 'sometimes|required|string|max:255',
            'date_delivrance_cin' => 'sometimes|required|date',
            'contact_famille' => 'sometimes|required|string|max:255',
            'telephone' => 'sometimes|required|string|max:255',
            'numero_vehicule' => 'sometimes|required|string|max:255',
            'observation' => 'nullable|string',
            'zone_livraison' => 'sometimes|required|string|max:255',
        ]);

        $livreur->update($validated);

        // Recharger avec les informations du créateur
        $livreur->load('createur:id,nom,prenom,role');

        return response()->json($livreur);
    }

    public function destroy($id): JsonResponse
    {
        $livreur = Livreur::findOrFail($id);
        $livreur->delete();

        return response()->json(['message' => 'Livreur supprimé avec succès']);
    }

    // Méthode pour récupérer les livreurs créés par un utilisateur spécifique
    public function getByUser($userId): JsonResponse
    {
        $livreurs = Livreur::with('createur:id,nom,prenom,role')
            ->where('created_by', $userId)
            ->get();
        
        return response()->json($livreurs);
    }
}