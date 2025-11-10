<?php

namespace App\Http\Controllers\MatierePremiere;

use App\Http\Controllers\Controller;
use App\Models\MatierePremiere\PVReception;
use Illuminate\Http\JsonResponse;

class StockController extends Controller
{
    /**
     * Récupère les statistiques de stock par type
     */
    public function getStockStats(): JsonResponse
    {
        try {
            // Récupérer le stock total par type (quantité restante)
            $stockStats = PVReception::selectRaw('
                type,
                SUM(quantite_restante) as stock_total,
                COUNT(*) as nombre_pv,
                SUM(quantite_totale) as quantite_totale_receptionnee,
                SUM(quantite_totale - quantite_restante) as quantite_livree
            ')
            ->where('statut', '!=', 'livree') // Exclure les PV complètement livrés
            ->groupBy('type')
            ->get();

            // Formater les données pour le frontend
            $formattedStats = [
                'FG' => [
                    'stock_total' => 0,
                    'nombre_pv' => 0,
                    'quantite_totale_receptionnee' => 0,
                    'quantite_livree' => 0,
                    'libelle' => 'Feuilles',
                    'icone' => 'Leaf'
                ],
                'CG' => [
                    'stock_total' => 0,
                    'nombre_pv' => 0,
                    'quantite_totale_receptionnee' => 0,
                    'quantite_livree' => 0,
                    'libelle' => 'Clous',
                    'icone' => 'Package'
                ],
                'GG' => [
                    'stock_total' => 0,
                    'nombre_pv' => 0,
                    'quantite_totale_receptionnee' => 0,
                    'quantite_livree' => 0,
                    'libelle' => 'Griffes',
                    'icone' => 'Box'
                ]
            ];

            // Mettre à jour avec les données réelles
            foreach ($stockStats as $stat) {
                if (isset($formattedStats[$stat->type])) {
                    $formattedStats[$stat->type] = [
                        'stock_total' => (float) $stat->stock_total,
                        'nombre_pv' => (int) $stat->nombre_pv,
                        'quantite_totale_receptionnee' => (float) $stat->quantite_totale_receptionnee,
                        'quantite_livree' => (float) $stat->quantite_livree,
                        'libelle' => $formattedStats[$stat->type]['libelle'],
                        'icone' => $formattedStats[$stat->type]['icone']
                    ];
                }
            }

            // Calculer les totaux généraux
            $totalStock = array_sum(array_column($formattedStats, 'stock_total'));
            $totalPV = array_sum(array_column($formattedStats, 'nombre_pv'));

            return response()->json([
                'status' => 'success',
                'data' => [
                    'stats_par_type' => $formattedStats,
                    'totaux' => [
                        'stock_total' => $totalStock,
                        'nombre_pv_total' => $totalPV,
                        'types_actifs' => count(array_filter($formattedStats, fn($stat) => $stat['stock_total'] > 0))
                    ],
                    'derniere_mise_a_jour' => now()->toISOString()
                ]
            ], 200);

        } catch (\Exception $e) {
      

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des statistiques de stock'
            ], 500);
        }
    }

    /**
     * Récupère l'historique des mouvements de stock
     */
    public function getHistoriqueMouvements(): JsonResponse
    {
        try {
            // Récupérer les dernières réceptions avec leurs livraisons
            $mouvements = PVReception::with(['ficheLivraisons.livraison'])
                ->select('id', 'type', 'numero_doc', 'quantite_totale', 'quantite_restante', 'statut', 'created_at')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($pv) {
                    return [
                        'id' => $pv->id,
                        'type' => $pv->type,
                        'numero_doc' => $pv->numero_doc,
                        'quantite_totale' => $pv->quantite_totale,
                        'quantite_restante' => $pv->quantite_restante,
                        'statut' => $pv->statut,
                        'date_reception' => $pv->created_at,
                        'livraisons' => $pv->ficheLivraisons->map(function ($fiche) {
                            return [
                                'quantite_livree' => $fiche->quantite_a_livrer - $fiche->quantite_restante,
                                'date_livraison' => $fiche->date_livraison,
                                'confirmee' => !is_null($fiche->livraison)
                            ];
                        })
                    ];
                });

            return response()->json([
                'status' => 'success',
                'data' => $mouvements
            ], 200);

        } catch (\Exception $e) {
       

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération de l\'historique'
            ], 500);
        }
    }

    /**
     * Récupère les tendances de stock sur les 30 derniers jours
     */
    public function getTendancesStock(): JsonResponse
    {
        try {
            $dateDebut = now()->subDays(30);

            $tendances = PVReception::selectRaw('
                DATE(created_at) as date,
                type,
                SUM(quantite_totale) as quantite_receptionnee,
                SUM(quantite_restante) as stock_restant
            ')
            ->where('created_at', '>=', $dateDebut)
            ->groupBy('date', 'type')
            ->orderBy('date', 'asc')
            ->get();

            // Formater les données pour les graphiques
            $formattedTendances = [
                'FG' => [],
                'CG' => [],
                'GG' => []
            ];

            foreach ($tendances as $tendance) {
                $formattedTendances[$tendance->type][] = [
                    'date' => $tendance->date,
                    'quantite_receptionnee' => (float) $tendance->quantite_receptionnee,
                    'stock_restant' => (float) $tendance->stock_restant
                ];
            }

            return response()->json([
                'status' => 'success',
                'data' => $formattedTendances
            ], 200);

        } catch (\Exception $e) {
       

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des tendances'
            ], 500);
        }
    }
}