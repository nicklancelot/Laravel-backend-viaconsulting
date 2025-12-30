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

        /**
     * Retourne des statistiques globales sur les livreurs
     */
    public function stats(): JsonResponse
    {
        $totalLivreurs = Livreur::count();

        $livreursParZone = Livreur::select('zone_livraison')
            ->selectRaw('count(*) as nombre')
            ->groupBy('zone_livraison')
            ->orderByDesc('nombre')
            ->get();

        $livreursParCreateur = Livreur::with('createur:id,nom,prenom')
            ->select('created_by')
            ->selectRaw('count(*) as nombre')
            ->groupBy('created_by')
            ->orderByDesc('nombre')
            ->get();

        // Optionnel : livreurs ajoutés par mois (sur les 12 derniers mois)
        $livreursParMois = Livreur::selectRaw('DATE_FORMAT(created_at, "%Y-%m") as mois')
            ->selectRaw('count(*) as nombre')
            ->where('created_at', '>=', now()->subYear())
            ->groupBy('mois')
            ->orderBy('mois')
            ->pluck('nombre', 'mois');

        return response()->json([
            'total_livreurs' => $totalLivreurs,
            'livreurs_par_zone' => $livreursParZone,
            'livreurs_par_createur' => $livreursParCreateur,
            'livreurs_par_mois_dernier_an' => $livreursParMois,
        ]);
    }
}