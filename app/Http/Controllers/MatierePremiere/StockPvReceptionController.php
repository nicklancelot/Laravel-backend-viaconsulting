<?php

namespace App\Http\Controllers\MatierePremiere;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class StockPvReceptionController extends Controller
{
    public function getEtatStock(): JsonResponse
    {
        $stocks = DB::table('stockpvs')->get();
        
        $result = [];
        foreach (['FG', 'CG', 'GG'] as $type) {
            $stock = $stocks->firstWhere('type_matiere', $type);
            
            $result[$type] = [
                'total_entree' => $stock ? (float) $stock->stock_total : 0,
                'total_disponible' => $stock ? (float) $stock->stock_disponible : 0,
                'total_utilise' => $stock ? 
                    (float) $stock->stock_total - (float) $stock->stock_disponible : 0,
                'nombre_lots' => $stock ? 1 : 0
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $result
        ]);
    }
}