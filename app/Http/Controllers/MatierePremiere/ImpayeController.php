<?php

namespace App\Http\Controllers\MatierePremiere;

use App\Http\Controllers\Controller;
use App\Models\MatierePremiere\Impaye;
use App\Models\MatierePremiere\PVReception;
use App\Models\SoldeUser;
use Illuminate\Http\Request;

class ImpayeController extends Controller
{
    public function index()
    {
        try {
            $impayes = Impaye::with(['pvReception.fournisseur', 'pvReception.provenance'])->get();
            return response()->json([
                'status' => 'success',
                'data' => $impayes
            ], 200);
        } catch (\Exception $e) {
         
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des impayés'
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
        

            $validator = validator($request->all(), [
                'pv_reception_id' => 'required|exists:p_v_receptions,id',
                'date_paiement' => 'nullable|date',
                'mode_paiement' => 'required|in:especes,virement,cheque,carte,mobile_money',
                'reference_paiement' => 'nullable|string|max:255',
                'montant_total' => 'required|numeric|min:0',
                'montant_paye' => 'required|numeric|min:0',
            ]);

            if ($validator->fails()) {
         
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $pvReception = PVReception::find($request->pv_reception_id);
            
            if (!$pvReception) {
          
                return response()->json([
                    'status' => 'error',
                    'message' => 'PV de réception non trouvé'
                ], 404);
            }

            // VÉRIFICATION SOLDE UTILISATEUR
            $soldeUser = SoldeUser::where('utilisateur_id', $pvReception->utilisateur_id)->first();
            $soldeActuel = $soldeUser ? $soldeUser->solde : 0;

            if ($soldeActuel < $request->montant_paye) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Solde utilisateur insuffisant. Solde disponible: ' . number_format($soldeActuel, 0, ',', ' ') . ' Ar - Montant à payer: ' . number_format($request->montant_paye, 0, ',', ' ') . ' Ar',
                    'solde_actuel' => $soldeActuel,
                    'montant_paye' => $request->montant_paye,
                    'solde_insuffisant' => true
                ], 400);
            }

            // DÉCRÉMENTER LE SOLDE UTILISATEUR
            if ($soldeUser && $request->montant_paye > 0) {
                $soldeUser->decrement('solde', $request->montant_paye);
                $nouveauSolde = $soldeUser->solde;
            } else {
                $nouveauSolde = $soldeActuel;
            }

            // ✅ Génération du numéro d'impayé avec log pour debug
            $numeroFacture = Impaye::genererNumeroImpaye();
     

            $request->merge([
                'numero_facture' => $numeroFacture
            ]);


            $impaye = Impaye::create($request->all());
            

            return response()->json([
                'status' => 'success',
                'message' => 'Impayé créé avec succès',
                'data' => $impaye->load(['pvReception.fournisseur', 'pvReception.provenance']),
                'solde_info' => [
                    'solde_avant' => $soldeActuel,
                    'solde_apres' => $nouveauSolde,
                    'montant_debite' => $request->montant_paye
                ]
            ], 201);

        } catch (\Exception $e) {

            
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la création de l\'impayé: ' . $e->getMessage() // ✅ Inclure le message d'erreur réel pour le frontend
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $impaye = Impaye::with(['pvReception.fournisseur', 'pvReception.provenance'])->find($id);
            
            if (!$impaye) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Impayé non trouvé'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $impaye
            ], 200);

        } catch (\Exception $e) {
    
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération de l\'impayé'
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $impaye = Impaye::find($id);
            
            if (!$impaye) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Impayé non trouvé'
                ], 404);
            }

            $validator = validator($request->all(), [
                'date_paiement' => 'sometimes|date',
                'mode_paiement' => 'sometimes|in:especes,virement,cheque,carte,mobile_money',
                'reference_paiement' => 'nullable|string|max:255',
                'montant_total' => 'sometimes|numeric|min:0',
                'montant_paye' => 'sometimes|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // VÉRIFICATION SOLDE UTILISATEUR POUR MODIFICATION
            $pvReception = PVReception::find($impaye->pv_reception_id);
            if ($pvReception && isset($request->montant_paye) && $request->montant_paye != $impaye->montant_paye) {
                $difference = $request->montant_paye - $impaye->montant_paye;
                
                if ($difference > 0) {
                    // Vérifier si on doit augmenter le paiement
                    $soldeUser = SoldeUser::where('utilisateur_id', $pvReception->utilisateur_id)->first();
                    $soldeActuel = $soldeUser ? $soldeUser->solde : 0;
                    
                    if ($soldeActuel < $difference) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Solde utilisateur insuffisant pour augmenter le paiement. Solde disponible: ' . number_format($soldeActuel, 0, ',', ' ') . ' Ar - Montant supplémentaire: ' . number_format($difference, 0, ',', ' ') . ' Ar',
                            'solde_insuffisant' => true
                        ], 400);
                    }
                    
                    // Décrémenter le solde pour la différence
                    if ($soldeUser && $difference > 0) {
                        $soldeUser->decrement('solde', $difference);
                    }
                }
            }

            // ✅ Correction : Utiliser fill() + save() pour déclencher les événements du modèle (calculs et hooks)
            $impaye->fill($request->all());
            $impaye->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Impayé modifié avec succès',
                'data' => $impaye->load(['pvReception.fournisseur', 'pvReception.provenance'])
            ], 200);

        } catch (\Exception $e) {
          
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la modification de l\'impayé'
            ], 500);
        }
    }

    public function enregistrerPaiement(Request $request, $id)
    {
        try {
      

            $impaye = Impaye::find($id);
            
            if (!$impaye) {
            
                return response()->json([
                    'status' => 'error',
                    'message' => 'Impayé non trouvé'
                ], 404);
            }

   

            $validator = validator($request->all(), [
                'montant_paye' => 'required|numeric|min:0.01|max:' . $impaye->reste_a_payer,
                'mode_paiement' => 'required|in:especes,virement,cheque,carte,mobile_money',
                'reference_paiement' => 'nullable|string|max:255',
                'date_paiement' => 'nullable|date',
            ]);

            if ($validator->fails()) {
            
                return response()->json([
                    'status' => 'error',
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // VÉRIFICATION SOLDE UTILISATEUR
            $pvReception = PVReception::find($impaye->pv_reception_id);
            $soldeUser = SoldeUser::where('utilisateur_id', $pvReception->utilisateur_id)->first();
            $soldeActuel = $soldeUser ? $soldeUser->solde : 0;

            if ($soldeActuel < $request->montant_paye) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Solde utilisateur insuffisant. Solde disponible: ' . number_format($soldeActuel, 0, ',', ' ') . ' Ar - Montant à payer: ' . number_format($request->montant_paye, 0, ',', ' ') . ' Ar',
                    'solde_actuel' => $soldeActuel,
                    'montant_paye' => $request->montant_paye,
                    'solde_insuffisant' => true
                ], 400);
            }

            // DÉCRÉMENTER LE SOLDE UTILISATEUR
            if ($soldeUser && $request->montant_paye > 0) {
                $soldeUser->decrement('solde', $request->montant_paye);
                $nouveauSolde = $soldeUser->solde;
            } else {
                $nouveauSolde = $soldeActuel;
            }


            // Calculer le nouveau montant payé
            $nouveauMontantPaye = $impaye->montant_paye + $request->montant_paye;

            // ✅ Correction : Utiliser fill() + save() pour déclencher les événements (calcul de reste_a_payer et mise à jour de la réception)
            $updateData = [
                'montant_paye' => $nouveauMontantPaye,
                'mode_paiement' => $request->mode_paiement,
                'reference_paiement' => $request->reference_paiement,
                'date_paiement' => $request->date_paiement ?? now()->toDateTimeString(),
            ];
            
            $impaye->fill($updateData);
            $impaye->save(); // ✅ Déclenche saving (calculerChamps) et updated (mettreAJourStatutReception)

  

            // ✅ Suppression du bloc manuel de mise à jour de pvReception : le hook updated s'en charge automatiquement

            // Recharger l'impayé avec les relations (attributs déjà à jour via save())
            $impaye->load(['pvReception.fournisseur', 'pvReception.provenance']);

         

            return response()->json([
                'status' => 'success',
                'message' => 'Paiement d\'impayé enregistré avec succès',
                'data' => $impaye,
                'solde_info' => [
                    'solde_avant' => $soldeActuel,
                    'solde_apres' => $nouveauSolde,
                    'montant_debite' => $request->montant_paye
                ]
            ], 200);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'enregistrement du paiement d\'impayé: ' . $e->getMessage(),
                'debug' => env('APP_DEBUG') ? [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ] : null
            ], 500);
        }
    }
}