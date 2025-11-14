<?php

namespace App\Http\Controllers\MatierePremiere;

use App\Http\Controllers\Controller;
use App\Models\MatierePremiere\Impaye;
use App\Models\MatierePremiere\PVReception;
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

            // ✅ Génération du numéro d'impayé avec log pour debug
            $numeroFacture = Impaye::genererNumeroImpaye();
     

            $request->merge([
                'numero_facture' => $numeroFacture
            ]);


            $impaye = Impaye::create($request->all());
            

            return response()->json([
                'status' => 'success',
                'message' => 'Impayé créé avec succès',
                'data' => $impaye->load(['pvReception.fournisseur', 'pvReception.provenance'])
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
                'data' => $impaye
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