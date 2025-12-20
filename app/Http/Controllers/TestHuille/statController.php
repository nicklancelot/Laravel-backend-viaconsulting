<?php

namespace App\Http\Controllers\TestHuille;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TestHuille\FicheReception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class statController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $query = FicheReception::query();
            
            if ($user->role !== 'admin') {
                $query->where('utilisateur_id', $user->id);
            }
            
            $totalFiches = $query->count();
            $fichesEnAttente = $query->clone()->where('statut', 'en attente de teste')->count();
            $stockBrutTotal = $query->clone()->where('statut', '!=', 'livré')->sum('poids_brut');
            $stockNetTotal = $query->clone()->where('statut', '!=', 'livré')->sum('poids_net');
            $enAttenteTest = $query->clone()->where('statut', 'en attente de teste')->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'total_fiches' => $totalFiches,
                    'fiches_en_attente' => $fichesEnAttente,
                    'stock_brut_total' => (float) $stockBrutTotal,
                    'stock_net_total' => (float) $stockNetTotal,
                    'en_attente_test' => $enAttenteTest,
                    'user_role' => $user->role,
                    'user_id' => $user->id
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