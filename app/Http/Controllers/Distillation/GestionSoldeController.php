<?php

namespace App\Http\Controllers\Distillation;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\SoldeUser;
use App\Models\Distilleur\Historique;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class GestionSoldeController extends Controller
{
    /**
     * Effectuer un retrait pour l'utilisateur connecté
     */
    public function retrait(Request $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            // Récupérer l'utilisateur connecté avec Auth
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié. Veuillez vous connecter.'
                ], 401);
            }

            // Validation des données
            $validator = Validator::make($request->all(), [
                'montant' => 'required|numeric|min:0.01',
                'motif' => 'nullable|string|max:255'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();
            $montant = $validated['montant'];
            $motif = $validated['motif'] ?? 'Retrait de fonds';

            // Récupérer ou créer le solde de l'utilisateur
            $soldeUser = SoldeUser::where('utilisateur_id', $user->id)->first();

            if (!$soldeUser) {
                $soldeUser = SoldeUser::create([
                    'utilisateur_id' => $user->id,
                    'solde' => 0
                ]);
            }

            // Vérifier si le solde est suffisant
            if ($soldeUser->solde < $montant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solde insuffisant pour effectuer le retrait',
                    'solde_disponible' => $soldeUser->solde,
                    'montant_demande' => $montant
                ], 400);
            }

            // Enregistrer le solde avant retrait
            $soldeAvant = $soldeUser->solde;

            // Effectuer le retrait
            $soldeUser->solde -= $montant;
            $soldeUser->save();

            // Enregistrer l'historique du retrait
            $historique = Historique::create([
                'utilisateur_id' => $user->id,
                'type_operation' => 'retrait',
                'montant' => $montant,
                'solde_avant' => $soldeAvant,
                'solde_apres' => $soldeUser->solde,
                'motif' => $motif,
                'reference' => Historique::generateReference(),
                'statut' => 'success'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Retrait effectué avec succès',
                'data' => [
                    'transaction' => [
                        'id' => $historique->id,
                        'reference' => $historique->reference,
                        'type' => 'retrait',
                        'montant' => $montant,
                        'solde_avant' => $soldeAvant,
                        'solde_apres' => $soldeUser->solde,
                        'motif' => $motif,
                        'date_operation' => $historique->created_at->toDateTimeString()
                    ],
                    'utilisateur' => [
                        'id' => $user->id,
                        'nom' => $user->nom,
                        'prenom' => $user->prenom,
                        'numero' => $user->numero
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du retrait',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer le solde de l'utilisateur connecté
     */
    public function monSolde(): JsonResponse
    {
        try {
        
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié. Veuillez vous connecter.'
                ], 401);
            }

           
            $soldeUser = SoldeUser::where('utilisateur_id', $user->id)->first();
            
           
            if (!$soldeUser) {
                $soldeUser = SoldeUser::create([
                    'utilisateur_id' => $user->id,
                    'solde' => 0
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'utilisateur' => [
                        'id' => $user->id,
                        'nom' => $user->nom,
                        'prenom' => $user->prenom,
                        'numero' => $user->numero,
                        'role' => $user->role
                    ],
                    'solde' => [
                        'montant' => $soldeUser->solde,
                        'derniere_mise_a_jour' => $soldeUser->updated_at
                    ]
                ],
                'message' => 'Votre solde a été récupéré avec succès'
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
     * Récupérer l'historique des retraits de l'utilisateur connecté
     */
    public function historiqueRetraits(Request $request): JsonResponse
    {
        try {
            // Récupérer l'utilisateur connecté avec Auth
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié. Veuillez vous connecter.'
                ], 401);
            }

            // Paramètres de pagination
            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 10);

            // Récupérer l'historique des retraits avec pagination
            $historique = Historique::where('utilisateur_id', $user->id)
                ->where('type_operation', 'retrait')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            // Récupérer le solde actuel
            $soldeUser = SoldeUser::where('utilisateur_id', $user->id)->first();
            $soldeActuel = $soldeUser ? $soldeUser->solde : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'utilisateur' => [
                        'id' => $user->id,
                        'nom' => $user->nom,
                        'prenom' => $user->prenom,
                        'solde_actuel' => $soldeActuel
                    ],
                    'historique' => $historique->items(),
                    'pagination' => [
                        'current_page' => $historique->currentPage(),
                        'last_page' => $historique->lastPage(),
                        'per_page' => $historique->perPage(),
                        'total' => $historique->total()
                    ]
                ],
                'message' => 'Historique des retraits récupéré avec succès'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l\'historique',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer les détails d'un retrait spécifique
     */
    public function detailRetrait($id): JsonResponse
    {
        try {
            // Récupérer l'utilisateur connecté avec Auth
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié. Veuillez vous connecter.'
                ], 401);
            }

            // Récupérer le retrait spécifique
            $retrait = Historique::where('id', $id)
                ->where('utilisateur_id', $user->id)
                ->where('type_operation', 'retrait')
                ->first();

            if (!$retrait) {
                return response()->json([
                    'success' => false,
                    'message' => 'Retrait non trouvé'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $retrait,
                'message' => 'Détails du retrait récupérés avec succès'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des détails du retrait',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}