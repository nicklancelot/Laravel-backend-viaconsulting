<?php

namespace App\Http\Controllers\TestHuille;

use App\Http\Controllers\Controller;
use App\Models\TestHuille\Stockhe;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class StockheController extends Controller
{
    /**
     * Obtenir l'état du stock
     */
    public function getEtatStock(): JsonResponse
    {
        try {
            $stock = Stockhe::getStockActuel();
            
            return response()->json([
                'success' => true,
                'data' => $stock
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du stock',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Vérifier la disponibilité
     */
    public function verifierDisponibilite(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'quantite' => 'required|numeric|min:0'
            ]);
            
            $disponible = Stockhe::verifierDisponibilite($request->quantite);
            $stock = Stockhe::getStockActuel();
            
            return response()->json([
                'success' => true,
                'disponible' => $disponible,
                'stock_actuel' => $stock ? $stock->stock_disponible : 0,
                'quantite_demandee' => $request->quantite
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la vérification',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}