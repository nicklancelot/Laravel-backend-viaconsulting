<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Models\SoldeUser;

class SoldeUserController extends Controller
{
    /**
     * Récupérer le solde d'un utilisateur
     */
    public function show($utilisateur_id): JsonResponse
    {
        try {
            $soldeUser = SoldeUser::with('utilisateur:id,nom,prenom,numero,role')
                ->where('utilisateur_id', $utilisateur_id)
                ->first();

            // Si pas de solde, retourner 0
            $solde = $soldeUser ? $soldeUser->solde : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'utilisateur_id' => $utilisateur_id,
                    'solde' => $solde,
                    'utilisateur' => $soldeUser->utilisateur ?? null
                ],
                'message' => 'Solde récupéré avec succès'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du solde',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer tous les soldes
     */
    public function index(): JsonResponse
    {
        try {
            $soldes = SoldeUser::with('utilisateur:id,nom,prenom,numero,role')->get();

            return response()->json([
                'success' => true,
                'data' => $soldes,
                'message' => 'Soldes récupérés avec succès'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des soldes',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}