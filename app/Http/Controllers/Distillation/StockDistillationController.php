<?php

namespace App\Http\Controllers\Distillation;

use App\Http\Controllers\Controller;
use App\Models\Distillation\Stock;
use App\Models\Distillation\Distillation;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class StockDistillationController extends Controller
{
    /**
     * Récupérer le stock du distilleur connecté (REGROUPÉ par type de produit)
     */
    public function index(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            // Récupérer tous les stocks
            $stocks = Stock::where('distilleur_id', $user->id)
                ->with(['distillation', 'distilleur'])
                ->orderBy('date_entree', 'desc')
                ->get();
            
            // **REGROUPER PAR TYPE DE PRODUIT**
            $stocksRegroupes = $stocks->groupBy('type_produit')->map(function ($stocksMemeType, $typeProduit) {
                return [
                    'type_produit' => $typeProduit,
                    'nombre_lots' => $stocksMemeType->count(),
                    'lots' => $stocksMemeType, // Garder les lots individuels
                    'quantite_initiale_totale' => $stocksMemeType->sum('quantite_initiale'),
                    'quantite_disponible_totale' => $stocksMemeType->sum('quantite_disponible'),
                    'quantite_reservee_totale' => $stocksMemeType->sum('quantite_reservee'),
                    'quantite_sortie_totale' => $stocksMemeType->sum('quantite_sortie'),
                    'pourcentage_utilise' => $stocksMemeType->sum('quantite_initiale') > 0 
                        ? (($stocksMemeType->sum('quantite_reservee') + $stocksMemeType->sum('quantite_sortie')) / 
                           $stocksMemeType->sum('quantite_initiale')) * 100 
                        : 0,
                    'date_entree_plus_recente' => $stocksMemeType->max('date_entree'),
                    'date_production_plus_recente' => $stocksMemeType->max('date_production')
                ];
            })->values(); // Convertir en array simple
            
            // Statistiques globales (sur les données regroupées)
            $stats = [
                'total_types_produits' => $stocksRegroupes->count(),
                'total_lots' => $stocks->count(),
                'quantite_totale_disponible' => $stocksRegroupes->sum('quantite_disponible_totale'),
                'quantite_totale_reservee' => $stocksRegroupes->sum('quantite_reservee_totale'),
                'quantite_totale_sortie' => $stocksRegroupes->sum('quantite_sortie_totale'),
                'quantite_totale_initiale' => $stocksRegroupes->sum('quantite_initiale_totale'),
            ];
            
            // Ajouter les statistiques par type de produit
            $statsParType = $stocksRegroupes->map(function ($stockRegroupe) {
                return [
                    'nombre_lots' => $stockRegroupe['nombre_lots'],
                    'quantite_initiale' => $stockRegroupe['quantite_initiale_totale'],
                    'quantite_disponible' => $stockRegroupe['quantite_disponible_totale'],
                    'quantite_reservee' => $stockRegroupe['quantite_reservee_totale'],
                    'quantite_sortie' => $stockRegroupe['quantite_sortie_totale'],
                    'pourcentage_utilise' => $stockRegroupe['pourcentage_utilise']
                ];
            });
            
            return response()->json([
                'success' => true,
                'data' => $stocksRegroupes, // Données regroupées par type
                'data_detailles' => $stocks, // Données complètes (pour référence)
                'stats' => array_merge($stats, ['par_type' => $statsParType]),
                'distilleur_info' => [
                    'id' => $user->id,
                    'nom_complet' => $user->nom . ' ' . $user->prenom
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du stock',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Récupérer les stocks disponibles pour transport (REGROUPÉS)
     */
    public function getStocksDisponibles(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $stocks = Stock::where('distilleur_id', $user->id)
                ->where('quantite_disponible', '>', 0)
                ->where('statut', 'disponible')
                ->with(['distillation'])
                ->orderBy('type_produit')
                ->get();
            
            // **REGROUPER PAR TYPE DE PRODUIT avec addition des quantités**
            $stocksRegroupes = $stocks->groupBy('type_produit')->map(function ($stocksMemeType, $typeProduit) {
                return [
                    'type_produit' => $typeProduit,
                    'quantite_totale_disponible' => $stocksMemeType->sum('quantite_disponible'),
                    'nombre_lots' => $stocksMemeType->count(),
                    'lots_disponibles' => $stocksMemeType->map(function ($lot) {
                        return [
                            'id' => $lot->id,
                            'reference' => $lot->reference,
                            'quantite_disponible' => $lot->quantite_disponible,
                            'date_production' => $lot->date_production,
                            'distillation_id' => $lot->distillation_id
                        ];
                    }),
                    'peut_creer_transport' => $stocksMemeType->sum('quantite_disponible') > 0
                ];
            })->values();
            
            return response()->json([
                'success' => true,
                'data' => $stocksRegroupes,
                'distilleur_info' => [
                    'id' => $user->id,
                    'nom_complet' => $user->nom . ' ' . $user->prenom
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des stocks disponibles',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * NOUVELLE MÉTHODE : Récupérer les lots individuels d'un type de produit
     */
    public function getLotsParType($typeProduit): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $lots = Stock::where('distilleur_id', $user->id)
                ->where('type_produit', $typeProduit)
                ->where('quantite_disponible', '>', 0)
                ->with(['distillation'])
                ->orderBy('date_entree', 'asc') // FIFO : premier entré, premier sorti
                ->get();
            
            $quantiteTotale = $lots->sum('quantite_disponible');
            
            return response()->json([
                'success' => true,
                'type_produit' => $typeProduit,
                'quantite_totale_disponible' => $quantiteTotale,
                'nombre_lots' => $lots->count(),
                'data' => $lots,
                'distilleur_info' => [
                    'id' => $user->id,
                    'nom_complet' => $user->nom . ' ' . $user->prenom
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des lots',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * NOUVELLE MÉTHODE : Récupérer l'historique des mouvements de stock
     */
    public function getHistoriqueMouvements(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $stocks = Stock::where('distilleur_id', $user->id)
                ->with(['distillation', 'transports'])
                ->orderBy('created_at', 'desc')
                ->get()
                ->flatMap(function ($stock) {
                    $mouvements = [];
                    
                    // Entrée en stock
                    $mouvements[] = [
                        'type' => 'entree',
                        'date' => $stock->date_entree,
                        'type_produit' => $stock->type_produit,
                        'quantite' => $stock->quantite_initiale,
                        'reference' => $stock->reference,
                        'stock_id' => $stock->id
                    ];
                    
                    // Sorties (transports)
                    foreach ($stock->transports as $transport) {
                        $mouvements[] = [
                            'type' => 'sortie',
                            'date' => $transport->date_livraison ?? $transport->date_transport,
                            'type_produit' => $stock->type_produit,
                            'quantite' => $transport->quantite_a_livrer,
                            'reference' => $stock->reference,
                            'stock_id' => $stock->id,
                            'transport_id' => $transport->id,
                            'destination' => $transport->site_destination
                        ];
                    }
                    
                    return $mouvements;
                })
                ->sortByDesc('date')
                ->values();
            
            return response()->json([
                'success' => true,
                'data' => $stocks,
                'total_mouvements' => $stocks->count(),
                'entrees' => $stocks->where('type', 'entree')->count(),
                'sorties' => $stocks->where('type', 'sortie')->count(),
                'distilleur_info' => [
                    'id' => $user->id,
                    'nom_complet' => $user->nom . ' ' . $user->prenom
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l\'historique',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }
}