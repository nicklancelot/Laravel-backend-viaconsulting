<?php

namespace App\Http\Controllers\Distillation;

use App\Http\Controllers\Controller;
use App\Models\Distillation\Distillation;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class DistillationController extends Controller
{
    /**
     * Récupérer toutes les distillations du distilleur connecté
     */
  public function index(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            // Construire la requête selon le rôle
            $query = Distillation::query();
            
            if ($user->role === 'distilleur') {
                // Distilleur : seulement ses propres distillations
                $query->whereHas('expedition.ficheLivraison', function($query) use ($user) {
                    $query->where('distilleur_id', $user->id);
                });
            } 
            // Admin : pas de filtre, voit toutes les distillations
            
            $distillations = $query
                ->with(['expedition.ficheLivraison.stockpv', 'expedition.ficheLivraison.livreur', 'expedition.ficheLivraison.distilleur.siteCollecte'])
                ->orderBy('created_at', 'desc')
                ->get();

            // Ajouter des informations formatées
            $distillations->each(function ($distillation) use ($user) {
                $distillation->peut_demarrer = $distillation->estEnAttente();
                $distillation->peut_terminer = $distillation->estEnCours();
                $distillation->rendement_formate = $distillation->rendement_formate;
                
                // Ajouter info distilleur pour admin
                if ($user->role === 'admin' && $distillation->expedition->ficheLivraison->distilleur) {
                    $distillation->distilleur_info = [
                        'id' => $distillation->expedition->ficheLivraison->distilleur->id,
                        'nom_complet' => $distillation->expedition->ficheLivraison->distilleur->nom . ' ' . $distillation->expedition->ficheLivraison->distilleur->prenom,
                        'site_collecte' => $distillation->expedition->ficheLivraison->distilleur->siteCollecte->Nom ?? 'Non défini'
                    ];
                }
            });

            // Statistiques
            $stats = [
                'total' => $distillations->count(),
                'en_attente' => $distillations->where('statut', 'en_attente')->count(),
                'en_cours' => $distillations->where('statut', 'en_cours')->count(),
                'terminees' => $distillations->where('statut', 'termine')->count(),
                'quantite_recue_totale' => $distillations->sum('quantite_recue'),
                'quantite_resultat_totale' => $distillations->where('statut', 'termine')->sum('quantite_resultat')
            ];

            // Préparer la réponse selon le rôle
            $response = [
                'success' => true,
                'message' => $user->role === 'admin' ? 'Liste de toutes les distillations' : 'Liste de toutes vos distillations',
                'data' => $distillations,
                'stats' => $stats,
                'count' => $distillations->count(),
                'user_role' => $user->role,
                'user_info' => [
                    'id' => $user->id,
                    'nom_complet' => $user->nom . ' ' . $user->prenom,
                    'role' => $user->role
                ]
            ];

            // Ajouter info spécifique au distilleur
            if ($user->role === 'distilleur') {
                $response['distilleur_info'] = [
                    'id' => $user->id,
                    'nom_complet' => $user->nom . ' ' . $user->prenom,
                    'site_collecte' => $user->siteCollecte->Nom ?? 'Non défini'
                ];
            }

            return response()->json($response);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des distillations',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }


    /**
     * Démarrer une distillation (pour le distilleur connecté)
     */
    public function demarrerDistillation(Request $request, $distillationId): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if ($user->role !== 'distilleur') {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès réservé aux distillateurs'
                ], 403);
            }

            $request->validate([
                'id_ambalic' => 'required|string|max:50',
                'date_debut' => 'required|date',
                'poids_distiller' => 'required|numeric|min:0',
                'usine' => 'required|string|max:100',
                'duree_distillation' => 'required|integer|min:1',
                'poids_chauffage' => 'required|numeric|min:0',
                'carburant' => 'required|string|max:50',
                'main_oeuvre' => 'required|numeric|min:0'
            ]);

            // Vérifier que la distillation appartient au distilleur connecté
            $distillation = Distillation::where('id', $distillationId)
                ->whereHas('expedition.ficheLivraison', function($query) use ($user) {
                    $query->where('distilleur_id', $user->id);
                })
                ->first();
            
            if (!$distillation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Distillation non trouvée ou non autorisée'
                ], 404);
            }

            // Vérifier que la distillation est en attente
            if (!$distillation->estEnAttente()) {
                return response()->json([
                    'success' => false,
                    'message' => 'La distillation n\'est pas en attente. Statut actuel: ' . $distillation->statut
                ], 400);
            }

            // Démarrer la distillation
            $distillation->demarrer([
                'id_ambalic' => $request->id_ambalic,
                'date_debut' => $request->date_debut,
                'poids_distiller' => $request->poids_distiller,
                'usine' => $request->usine,
                'duree_distillation' => $request->duree_distillation,
                'poids_chauffage' => $request->poids_chauffage,
                'carburant' => $request->carburant,
                'main_oeuvre' => $request->main_oeuvre
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Distillation démarrée avec succès',
                'data' => $distillation->load(['expedition.ficheLivraison.stockpv']),
                'distilleur_info' => [
                    'id' => $user->id,
                    'nom_complet' => $user->nom . ' ' . $user->prenom,
                    'site_collecte' => $user->siteCollecte->Nom ?? 'Non défini'
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du démarrage de la distillation',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Terminer une distillation (pour le distilleur connecté)
     */
    public function terminerDistillation(Request $request, $distillationId): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if ($user->role !== 'distilleur') {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès réservé aux distillateurs'
                ], 403);
            }

            $request->validate([
                'reference' => 'required|string|max:50',
                'matiere' => 'required|string|max:100',
                'site' => 'required|string|max:100',
                'quantite_traitee' => 'required|numeric|min:0',
                'date_fin' => 'required|date',
                'type_he' => 'required|string|max:50',
                'quantite_resultat' => 'required|numeric|min:0',
                'observations' => 'nullable|string'
            ]);

            // Vérifier que la distillation appartient au distilleur connecté
            $distillation = Distillation::where('id', $distillationId)
                ->whereHas('expedition.ficheLivraison', function($query) use ($user) {
                    $query->where('distilleur_id', $user->id);
                })
                ->first();
            
            if (!$distillation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Distillation non trouvée ou non autorisée'
                ], 404);
            }

            // Vérifier que la distillation est en cours
            if (!$distillation->estEnCours()) {
                return response()->json([
                    'success' => false,
                    'message' => 'La distillation n\'est pas en cours. Statut actuel: ' . $distillation->statut
                ], 400);
            }

            // Terminer la distillation
            $distillation->terminer([
                'reference' => $request->reference,
                'matiere' => $request->matiere,
                'site' => $request->site,
                'quantite_traitee' => $request->quantite_traitee,
                'date_fin' => $request->date_fin,
                'type_he' => $request->type_he,
                'quantite_resultat' => $request->quantite_resultat,
                'observations' => $request->observations
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Distillation terminée avec succès',
                'data' => $distillation->load(['expedition.ficheLivraison.stockpv']),
                'rendement' => $distillation->rendement_formate,
                'distilleur_info' => [
                    'id' => $user->id,
                    'nom_complet' => $user->nom . ' ' . $user->prenom,
                    'site_collecte' => $user->siteCollecte->Nom ?? 'Non défini'
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la finalisation de la distillation',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }
}