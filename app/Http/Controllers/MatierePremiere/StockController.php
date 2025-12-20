<?php

namespace App\Http\Controllers\MatierePremiere;

use App\Http\Controllers\Controller;
use App\Models\MatierePremiere\PVReception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class StockController extends Controller
{
    
    public function getStockStats(): JsonResponse
{
    try {
        $user = Auth::user();
        
        $query = PVReception::selectRaw('type,SUM(quantite_restante) as stock_total,COUNT(*) as nombre_pv,SUM(quantite_totale) as quantite_totale_receptionnee,SUM(quantite_totale - quantite_restante) as quantite_livree,SUM(prix_total) as prix_total,AVG(prix_unitaire) as prix_unitaire_moyen,SUM(poids_net) as poids_net_total')
        ->where('statut', '!=', 'livree');
        
        // Modifier la condition pour inclure les admins
        if ($user->role !== 'admin') {
            $query->where('utilisateur_id', $user->id);
        }
        
        $stockStats = $query->groupBy('type')->get();

        // Formater les données pour le frontend
        $formattedStats = [
            'FG' => [
                'stock_total' => 0,
                'nombre_pv' => 0,
                'quantite_totale_receptionnee' => 0,
                'quantite_livree' => 0,
                'prix_total' => 0,
                'prix_unitaire_moyen' => 0,
                'poids_net_total' => 0,
                'libelle' => 'Feuilles',
                'icone' => 'Leaf'
            ],
            'CG' => [
                'stock_total' => 0,
                'nombre_pv' => 0,
                'quantite_totale_receptionnee' => 0,
                'quantite_livree' => 0,
                'prix_total' => 0,
                'prix_unitaire_moyen' => 0,
                'poids_net_total' => 0,
                'libelle' => 'Clous',
                'icone' => 'Package'
            ],
            'GG' => [
                'stock_total' => 0,
                'nombre_pv' => 0,
                'quantite_totale_receptionnee' => 0,
                'quantite_livree' => 0,
                'prix_total' => 0,
                'prix_unitaire_moyen' => 0,
                'poids_net_total' => 0,
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
                    'prix_total' => (float) $stat->prix_total,
                    'prix_unitaire_moyen' => (float) $stat->prix_unitaire_moyen,
                    'poids_net_total' => (float) $stat->poids_net_total,
                    'libelle' => $formattedStats[$stat->type]['libelle'],
                    'icone' => $formattedStats[$stat->type]['icone']
                ];
            }
        }

        // Calculer les totaux généraux
        $totalStock = array_sum(array_column($formattedStats, 'stock_total'));
        $totalPV = array_sum(array_column($formattedStats, 'nombre_pv'));
        $totalPrix = array_sum(array_column($formattedStats, 'prix_total'));
        $totalPoidsNet = array_sum(array_column($formattedStats, 'poids_net_total'));

        return response()->json([
            'status' => 'success',
            'data' => [
                'stats_par_type' => $formattedStats,
                'totaux' => [
                    'stock_total' => $totalStock,
                    'nombre_pv_total' => $totalPV,
                    'prix_total' => $totalPrix,
                    'poids_net_total' => $totalPoidsNet,
                    'types_actifs' => count(array_filter($formattedStats, fn($stat) => $stat['stock_total'] > 0))
                ],
                'derniere_mise_a_jour' => now()->toISOString(),
                'utilisateur' => [
                    'id' => $user->id,
                    'nom' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role 
                ]
            ]
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Erreur lors de la récupération des statistiques de stock',
            'error' => config('app.debug') ? $e->getMessage() : null
        ], 500);
    }
}

    /**
     * Récupère l'historique des mouvements de stock pour l'utilisateur connecté
     */
    public function getHistoriqueMouvements(): JsonResponse
    {
        try {
            $user = Auth::user();

            // Récupérer les dernières réceptions avec leurs livraisons
            $query = PVReception::with(['ficheLivraisons.livraison'])
                ->select('id', 'type', 'numero_doc', 'quantite_totale', 'quantite_restante', 'statut', 'prix_total', 'prix_unitaire', 'created_at', 'utilisateur_id')
                ->orderBy('created_at', 'desc')
                ->limit(10);
            
            // Modifier la condition pour inclure les admins
            if ($user->role !== 'admin') {
                $query->where('utilisateur_id', $user->id);
            }
            
            $mouvements = $query->get()->map(function ($pv) use ($user) {
                return [
                    'id' => $pv->id,
                    'type' => $pv->type,
                    'numero_doc' => $pv->numero_doc,
                    'quantite_totale' => $pv->quantite_totale,
                    'quantite_restante' => $pv->quantite_restante,
                    'prix_total' => $pv->prix_total,
                    'prix_unitaire' => $pv->prix_unitaire,
                    'statut' => $pv->statut,
                    'date_reception' => $pv->created_at,
                    'utilisateur_id' => $pv->utilisateur_id,
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
                'data' => [
                    'mouvements' => $mouvements,
                    'utilisateur' => [
                        'id' => $user->id,
                        'nom' => $user->name,
                        'role' => $user->role
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération de l\'historique',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Récupère les tendances de stock sur les 30 derniers jours pour l'utilisateur connecté
     */
    public function getTendancesStock(): JsonResponse
    {
        try {
            $user = Auth::user();
            $dateDebut = now()->subDays(30);

            $query = PVReception::selectRaw('
                DATE(created_at) as date,
                type,
                SUM(quantite_totale) as quantite_receptionnee,
                SUM(quantite_restante) as stock_restant,
                SUM(prix_total) as prix_total
            ')
            ->where('created_at', '>=', $dateDebut);
            
            // Modifier la condition pour inclure les admins
            if ($user->role !== 'admin') {
                $query->where('utilisateur_id', $user->id);
            }
            
            $tendances = $query->groupBy('date', 'type')
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
                    'stock_restant' => (float) $tendance->stock_restant,
                    'prix_total' => (float) $tendance->prix_total
                ];
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'tendances' => $formattedTendances,
                    'periode' => [
                        'debut' => $dateDebut->toDateString(),
                        'fin' => now()->toDateString()
                    ],
                    'utilisateur' => [
                        'id' => $user->id,
                        'nom' => $user->name,
                        'role' => $user->role
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des tendances',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Récupère les statistiques globales (admin uniquement)
     */
    public function getStatsGlobales(): JsonResponse
    {
        try {
            $user = Auth::user();

            // Vérifier si l'utilisateur est admin
            if ($user->role !== 'admin') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Accès non autorisé. Réservé aux administrateurs.'
                ], 403);
            }

            // Récupérer les statistiques globales (tous utilisateurs)
            $statsGlobales = PVReception::selectRaw('
                type,
                SUM(quantite_restante) as stock_total,
                COUNT(*) as nombre_pv,
                SUM(quantite_totale) as quantite_totale_receptionnee,
                SUM(quantite_totale - quantite_restante) as quantite_livree,
                SUM(prix_total) as prix_total,
                AVG(prix_unitaire) as prix_unitaire_moyen,
                COUNT(DISTINCT utilisateur_id) as nombre_utilisateurs
            ')
            ->where('statut', '!=', 'livree')
            ->groupBy('type')
            ->get();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'stats_globales' => $statsGlobales,
                    'total_utilisateurs' => $statsGlobales->sum('nombre_utilisateurs'),
                    'prix_total_global' => $statsGlobales->sum('prix_total'),
                    'derniere_mise_a_jour' => now()->toISOString()
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des statistiques globales',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Récupère le résumé des stocks pour le dashboard utilisateur
     */
    public function getResumeStock(): JsonResponse
{
    try {
        $user = Auth::user();

        $query = PVReception::selectRaw('
            COUNT(*) as total_pv,
            SUM(quantite_restante) as stock_total,
            SUM(quantite_totale - quantite_restante) as quantite_livree_total,
            SUM(prix_total) as prix_total,
            AVG(prix_unitaire) as prix_unitaire_moyen,
            COUNT(DISTINCT type) as types_actifs,
            SUM(poids_net) as poids_net_total
        ')
        ->where('statut', '!=', 'livree');
        
        // Modifier la condition pour inclure les admins
        if ($user->role !== 'admin') {
            $query->where('utilisateur_id', $user->id);
        }
        
        $resume = $query->first();

        // Dernier PV créé
        $queryDernierPV = PVReception::orderBy('created_at', 'desc');
        
        // Modifier la condition pour inclure les admins
        if ($user->role !== 'admin') {
            $queryDernierPV->where('utilisateur_id', $user->id);
        }
        
        $dernierPV = $queryDernierPV->first();

        return response()->json([
            'status' => 'success',
            'data' => [
                'resume' => [
                    'total_pv' => $resume->total_pv ?? 0,
                    'stock_total' => (float) ($resume->stock_total ?? 0),
                    'quantite_livree_total' => (float) ($resume->quantite_livree_total ?? 0),
                    'prix_total' => (float) ($resume->prix_total ?? 0),
                    'prix_unitaire_moyen' => (float) ($resume->prix_unitaire_moyen ?? 0),
                    'types_actifs' => $resume->types_actifs ?? 0,
                    'poids_net_total' => (float) ($resume->poids_net_total ?? 0) 
                ],
                'dernier_pv' => $dernierPV ? [
                    'numero_doc' => $dernierPV->numero_doc,
                    'type' => $dernierPV->type,
                    'quantite_totale' => $dernierPV->quantite_totale,
                    'prix_total' => $dernierPV->prix_total,
                    'prix_unitaire' => $dernierPV->prix_unitaire,
                    'date_creation' => $dernierPV->created_at,
                    'poids_net' => $dernierPV->poids_net,
                    'utilisateur_id' => $dernierPV->utilisateur_id
                ] : null,
                'utilisateur' => [
                    'id' => $user->id,
                    'nom' => $user->name,
                    'role' => $user->role
                ]
            ]
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Erreur lors de la récupération du résumé',
            'error' => config('app.debug') ? $e->getMessage() : null
        ], 500);
    }
}
}