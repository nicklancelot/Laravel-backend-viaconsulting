<?php

namespace App\Http\Controllers\MatierePremiere;

use App\Http\Controllers\Controller;
use App\Models\MatierePremiere\Livraison;
use Illuminate\Http\Request;

class LivraisonController extends Controller
{
    // GET /livraisons (liste)
    public function index()
    {
        try {
            $livraisons = Livraison::with(['ficheLivraison.pvReception'])->get();

            return response()->json([
                'status' => 'success',
                'data' => $livraisons
            ], 200);

        } catch (\Exception $e) {
            // \Log::error('Erreur liste livraisons', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des livraisons'
            ], 500);
        }
    }

    // GET /livraisons/{id}
    public function show($id)
    {
        try {
            $livraison = Livraison::with(['ficheLivraison.pvReception'])->find($id);

            if (!$livraison) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Livraison non trouvée'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $livraison
            ], 200);

        } catch (\Exception $e) {
            // \Log::error('Erreur show livraison', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération de la livraison'
            ], 500);
        }
    }
}