<?php

namespace App\Http\Controllers\TestHuille;

use App\Http\Controllers\Controller;
use App\Models\TestHuille\FicheReception;
use App\Models\TestHuille\HEFicheLivraison;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HELivraisonController extends Controller
{
    /**
     * Démarrer la livraison (changer statut en "en cours de livraison")
     */
    public function demarrerLivraison($fiche_reception_id)
    {
        try {
            DB::beginTransaction();

            $fiche = FicheReception::find($fiche_reception_id);

            if (!$fiche) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fiche de réception non trouvée'
                ], 404);
            }

            // Vérifier si la fiche a une fiche de livraison
            $ficheLivraison = HEFicheLivraison::where('fiche_reception_id', $fiche_reception_id)->first();
            if (!$ficheLivraison) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune fiche de livraison trouvée pour cette fiche de réception'
                ], 404);
            }

            // Vérifier si la fiche est en attente de livraison
            if ($fiche->statut !== 'En attente de livraison') {
                return response()->json([
                    'success' => false,
                    'message' => 'La fiche doit être en statut "En attente de livraison" pour démarrer la livraison'
                ], 400);
            }

            // Mettre à jour le statut
            $fiche->update(['statut' => 'en cours de livraison']);

            DB::commit();

            $fiche->load(['fournisseur', 'siteCollecte']);

            return response()->json([
                'success' => true,
                'message' => 'Livraison démarrée avec succès',
                'data' => $fiche,
                'nouveau_statut' => 'en cours de livraison'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du démarrage de la livraison',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Terminer la livraison (changer statut en "livré")
     */
    public function terminerLivraison($fiche_reception_id)
    {
        try {
            DB::beginTransaction();

            $fiche = FicheReception::find($fiche_reception_id);

            if (!$fiche) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fiche de réception non trouvée'
                ], 404);
            }

            // Vérifier si la fiche a une fiche de livraison
            $ficheLivraison = HEFicheLivraison::where('fiche_reception_id', $fiche_reception_id)->first();
            if (!$ficheLivraison) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune fiche de livraison trouvée pour cette fiche de réception'
                ], 404);
            }

            // Vérifier si la fiche est en cours de livraison
            if ($fiche->statut !== 'en cours de livraison') {
                return response()->json([
                    'success' => false,
                    'message' => 'La fiche doit être en statut "en cours de livraison" pour terminer la livraison'
                ], 400);
            }

            // Mettre à jour le statut
            $fiche->update(['statut' => 'livré']);

            DB::commit();

            $fiche->load(['fournisseur', 'siteCollecte']);

            return response()->json([
                'success' => true,
                'message' => 'Livraison terminée avec succès',
                'data' => $fiche,
                'nouveau_statut' => 'livré'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la finalisation de la livraison',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer les fiches en attente de livraison
     */
    public function getEnAttenteLivraison()
    {
        try {
            $fiches = FicheReception::with(['fournisseur', 'siteCollecte', 'ficheLivraison'])
                ->where('statut', 'En attente de livraison')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Fiches en attente de livraison',
                'data' => $fiches,
                'count' => $fiches->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des fiches en attente de livraison',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer les fiches en cours de livraison
     */
    public function getEnCoursLivraison()
    {
        try {
            $fiches = FicheReception::with(['fournisseur', 'siteCollecte', 'ficheLivraison'])
                ->where('statut', 'en cours de livraison')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Fiches en cours de livraison',
                'data' => $fiches,
                'count' => $fiches->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des fiches en cours de livraison',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer les fiches livrées
     */
    public function getLivrees()
    {
        try {
            $fiches = FicheReception::with(['fournisseur', 'siteCollecte', 'ficheLivraison'])
                ->where('statut', 'livré')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Fiches livrées',
                'data' => $fiches,
                'count' => $fiches->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des fiches livrées',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}