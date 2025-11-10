<?php

namespace App\Http\Controllers\MatierePremiere;

use App\Http\Controllers\Controller;
use App\Models\MatierePremiere\PVReception;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;

class PVReceptionController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $pvReceptions = PVReception::with(['utilisateur', 'fournisseur', 'localisation'])
                ->forUser($user) // Applique le filtre selon le rôle
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $pvReceptions
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des PV de réception: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des PV de réception',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $rules = [
                'type' => 'required|in:FG,CG,GG',
                'date_reception' => 'required|date',
                'dette_fournisseur' => 'required|numeric|min:0',
                'utilisateur_id' => 'required|exists:utilisateurs,id',
                'fournisseur_id' => 'required|exists:fournisseurs,id',
                'localisation_id' => 'required|exists:localisations,id',
                'poids_brut' => 'required|numeric|min:0',
                'type_emballage' => 'required|in:sac,bidon,fut',
                'poids_emballage' => 'required|numeric|min:0',
                'nombre_colisage' => 'required|integer|min:1',
                'prix_unitaire' => 'required|numeric|min:0',
                'taux_humidite' => 'nullable|numeric|min:0|max:100',
            ];

            // Règles spécifiques au type CG
            if ($request->type === 'CG') {
                $rules['taux_dessiccation'] = 'required|numeric|min:0|max:100';
            }

            $request->validate($rules);

            // Vérifier que l'utilisateur ne peut créer que pour lui-même (sauf admin)
            if ($user->role !== 'admin' && $request->utilisateur_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous ne pouvez créer des PV que pour votre propre compte'
                ], 403);
            }

            // Générer le numéro de document
            $lastDoc = PVReception::where('type', $request->type)->orderBy('id', 'desc')->first();
            $docNumber = $request->type . '-' . str_pad(($lastDoc ? intval(explode('-', $lastDoc->numero_doc)[1]) : 0) + 1, 6, '0', STR_PAD_LEFT);

            // Calculs automatiques
            $poidsNet = $this->calculerPoidsNet($request);
            $prixTotal = $poidsNet * $request->prix_unitaire;

            // Déterminer le statut initial selon la dette
            $statut = $request->dette_fournisseur == 0 ? 'paye' : 'non_paye';

            $pvReception = PVReception::create([
                'type' => $request->type,
                'numero_doc' => $docNumber,
                'date_reception' => $request->date_reception,
                'dette_fournisseur' => $request->dette_fournisseur,
                'utilisateur_id' => $request->utilisateur_id,
                'fournisseur_id' => $request->fournisseur_id,
                'localisation_id' => $request->localisation_id,
                'poids_brut' => $request->poids_brut,
                'type_emballage' => $request->type_emballage,
                'poids_emballage' => $request->poids_emballage,
                'poids_net' => $poidsNet,
                'nombre_colisage' => $request->nombre_colisage,
                'prix_unitaire' => $request->prix_unitaire,
                'taux_humidite' => $request->taux_humidite,
                'taux_dessiccation' => $request->taux_dessiccation,
                'prix_total' => $prixTotal,
                'statut' => $statut,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'PV de réception créé avec succès',
                'data' => $pvReception->load(['utilisateur', 'fournisseur', 'localisation'])
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la création du PV de réception: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du PV de réception',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function show(PVReception $pvReception): JsonResponse
    {
        try {
            $user = Auth::user();
            
            // Vérifier les permissions
            if ($user->role !== 'admin' && $pvReception->utilisateur_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé à ce PV de réception'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => $pvReception->load(['utilisateur', 'fournisseur', 'localisation'])
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération du PV de réception: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du PV de réception',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function update(Request $request, PVReception $pvReception): JsonResponse
    {
        try {
            $user = Auth::user();
            
            // Vérifier les permissions
            if ($user->role !== 'admin' && $pvReception->utilisateur_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé pour modifier ce PV de réception'
                ], 403);
            }

            $rules = [
                'date_reception' => 'sometimes|date',
                'dette_fournisseur' => 'sometimes|numeric|min:0',
                'poids_brut' => 'sometimes|numeric|min:0',
                'type_emballage' => 'sometimes|in:sac,bidon,fut',
                'poids_emballage' => 'sometimes|numeric|min:0',
                'nombre_colisage' => 'sometimes|integer|min:1',
                'prix_unitaire' => 'sometimes|numeric|min:0',
                'taux_humidite' => 'nullable|numeric|min:0|max:100',
            ];

            if ($pvReception->type === 'CG') {
                $rules['taux_dessiccation'] = 'sometimes|numeric|min:0|max:100';
            }

            $request->validate($rules);

            // Recalculer si les données changent
            $data = $request->all();
            
            if ($request->hasAny(['poids_brut', 'poids_emballage', 'taux_dessiccation', 'prix_unitaire'])) {
                $poidsNet = $this->calculerPoidsNet($request, $pvReception->type);
                $prixTotal = $poidsNet * ($request->prix_unitaire ?? $pvReception->prix_unitaire);

                $data['poids_net'] = $poidsNet;
                $data['prix_total'] = $prixTotal;
            }

            // Mettre à jour le statut selon la dette
            if ($request->has('dette_fournisseur')) {
                $data['statut'] = $request->dette_fournisseur == 0 ? 'paye' : 'non_paye';
            }

            $pvReception->update($data);

            return response()->json([
                'success' => true,
                'message' => 'PV de réception mis à jour avec succès',
                'data' => $pvReception->load(['utilisateur', 'fournisseur', 'localisation'])
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour du PV de réception: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du PV de réception',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function destroy(PVReception $pvReception): JsonResponse
    {
        try {
            $user = Auth::user();
            
            // Vérifier les permissions
            if ($user->role !== 'admin' && $pvReception->utilisateur_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé pour supprimer ce PV de réception'
                ], 403);
            }

            $pvReception->delete();

            return response()->json([
                'success' => true,
                'message' => 'PV de réception supprimé avec succès'
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression du PV de réception: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression du PV de réception',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    // Méthodes pour les filtres par type et statut
    public function getByType($type): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $pvReceptions = PVReception::with(['utilisateur', 'fournisseur', 'localisation'])
                ->where('type', $type)
                ->forUser($user) // Applique le filtre selon le rôle
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $pvReceptions
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des PV par type: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des PV de réception',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function getByStatut($statut): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $pvReceptions = PVReception::with(['utilisateur', 'fournisseur', 'localisation'])
                ->where('statut', $statut)
                ->forUser($user) // Applique le filtre selon le rôle
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $pvReceptions
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des PV par statut: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des PV de réception',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    // Méthode pour calculer le poids net selon le type
    private function calculerPoidsNet(Request $request, string $type = null): float
    {
        $type = $type ?? $request->type;
        $poidsBrut = $request->poids_brut;
        $poidsEmballage = $request->poids_emballage;
        
        if ($type === 'CG') {
            $tauxDessiccation = $request->taux_dessiccation;
            $poidsSansEmballage = $poidsBrut - $poidsEmballage;
            $dessiccation = $poidsSansEmballage * ($tauxDessiccation / 100);
            return $poidsSansEmballage - $dessiccation;
        }
        
        // Pour FG et GG: poids_net = poids_brut - poids_emballage
        return $poidsBrut - $poidsEmballage;
    }
}