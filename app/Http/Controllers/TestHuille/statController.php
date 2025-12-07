<?php

namespace App\Http\Controllers\TestHuille;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TestHuille\FicheReception;
use Illuminate\Http\JsonResponse;


class statController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $totalFiches = FicheReception::count();
            $fichesEnAttente = FicheReception::where('statut', 'en attente de teste')->count();
            $stockBrutTotal = FicheReception::where('statut', '!=', 'livré')->sum('poids_brut');
            $stockNetTotal = FicheReception::where('statut', '!=', 'livré')->sum('poids_net');
            $enAttenteTest = FicheReception::where('statut', 'en attente de teste')->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'total_fiches' => $totalFiches,
                    'fiches_en_attente' => $fichesEnAttente,
                    'stock_brut_total' => (float) $stockBrutTotal,
                    'stock_net_total' => (float) $stockNetTotal,
                    'en_attente_test' => $enAttenteTest
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}