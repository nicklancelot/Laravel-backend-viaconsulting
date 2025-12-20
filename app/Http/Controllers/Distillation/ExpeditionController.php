<?php

namespace App\Http\Controllers\Distillation;

use App\Http\Controllers\Controller;
use App\Models\Distillation\Expedition;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ExpeditionController extends Controller
{
    /**
     * Récupérer toutes les expéditions du distilleur connecté
     */
public function index(): JsonResponse
{
    try {
        $user = Auth::user();
        
        // Construire la requête selon le rôle
        $query = Expedition::query();
        
        if ($user->role === 'distilleur') {
            // Distilleur : seulement ses propres expéditions
            $query->whereHas('ficheLivraison', function($query) use ($user) {
                $query->where('distilleur_id', $user->id);
            });
        } 
        // Admin : pas de filtre, voit toutes les expéditions
        
        $expeditions = $query
            ->with(['ficheLivraison.stockpv', 'ficheLivraison.livreur', 'ficheLivraison.distilleur.siteCollecte'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Ajouter des informations formatées
        $expeditions->each(function ($expedition) use ($user) {
            $expedition->peut_receptionner = $expedition->estEnAttente();
            $expedition->quantite_restante = $expedition->quantite_expediee - ($expedition->quantite_recue ?? 0);
            
            // Ajouter info distilleur pour admin
            if ($user->role === 'admin' && $expedition->ficheLivraison->distilleur) {
                $expedition->distilleur_info = [
                    'id' => $expedition->ficheLivraison->distilleur->id,
                    'nom_complet' => $expedition->ficheLivraison->distilleur->nom . ' ' . $expedition->ficheLivraison->distilleur->prenom,
                    'site_collecte' => $expedition->ficheLivraison->distilleur->siteCollecte->Nom ?? 'Non défini'
                ];
            }
        });

        // Statistiques
        $stats = [
            'total' => $expeditions->count(),
            'en_attente' => $expeditions->where('statut', 'en_attente')->count(),
            'receptionnees' => $expeditions->where('statut', 'receptionne')->count(),
            'quantite_expediee_totale' => $expeditions->sum('quantite_expediee'),
            'quantite_recue_totale' => $expeditions->where('statut', 'receptionne')->sum('quantite_recue'),
            'quantite_restante_totale' => $expeditions->where('statut', 'en_attente')->sum('quantite_expediee')
        ];

        // Préparer la réponse selon le rôle
        $response = [
            'success' => true,
            'message' => $user->role === 'admin' ? 'Liste de toutes les expéditions' : 'Liste de toutes vos expéditions',
            'data' => $expeditions,
            'stats' => $stats,
            'count' => $expeditions->count(),
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
            'message' => 'Erreur lors de la récupération des expéditions',
            'error' => env('APP_DEBUG') ? $e->getMessage() : null
        ], 500);
    }
}

    /**
     * Marquer une expédition comme réceptionnée
     */
    public function marquerReceptionne(Request $request, $expeditionId): JsonResponse
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
                'quantite_recue' => 'required|numeric|min:0'
            ]);

            // Vérifier que l'expédition appartient à ce distilleur
            $expedition = Expedition::where('id', $expeditionId)
                ->whereHas('ficheLivraison', function($query) use ($user) {
                    $query->where('distilleur_id', $user->id);
                })
                ->with('ficheLivraison')
                ->first();
            
            if (!$expedition) {
                return response()->json([
                    'success' => false,
                    'message' => 'Expédition non trouvée ou non autorisée'
                ], 404);
            }

            if (!$expedition->estEnAttente()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cette expédition n\'est pas en attente'
                ], 400);
            }

            // Vérifier que la quantité reçue ne dépasse pas la quantité expédiée
            if ($request->quantite_recue > $expedition->quantite_expediee) {
                return response()->json([
                    'success' => false,
                    'message' => 'La quantité reçue ne peut pas dépasser la quantité expédiée (' . $expedition->quantite_expediee . ')'
                ], 400);
            }

            $expedition->marquerCommeReceptionne($request->quantite_recue);

            // Recharger les relations
            $expedition->load(['ficheLivraison.stockpv', 'ficheLivraison.livreur']);

            return response()->json([
                'success' => true,
                'message' => 'Expédition marquée comme réceptionnée avec succès',
                'data' => $expedition,
                'distilleur_info' => [
                    'id' => $user->id,
                    'nom_complet' => $user->nom . ' ' . $user->prenom,
                    'site_collecte' => $user->siteCollecte->Nom ?? 'Non défini'
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du marquage de l\'expédition',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }
}