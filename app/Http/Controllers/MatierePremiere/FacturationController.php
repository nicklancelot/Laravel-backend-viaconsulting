<?php

namespace App\Http\Controllers\MatierePremiere;

use App\Http\Controllers\Controller;
use App\Models\MatierePremiere\Facturation;
use App\Models\MatierePremiere\Impaye;
use App\Models\MatierePremiere\PVReception;
use Illuminate\Http\Request;

class FacturationController extends Controller
{
    public function index()
    {
        try {
            $facturations = Facturation::with(['pvReception.fournisseur', 'pvReception.provenance'])->get();
            return response()->json([
                'status' => 'success',
                'data' => $facturations
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération des facturations'
            ], 500);
        }
    }
public function store(Request $request)
{
    try {
        $validator = validator($request->all(), [
            'pv_reception_id' => 'required|exists:p_v_receptions,id',
            'date_facturation' => 'required|date',
            'mode_paiement' => 'required|in:especes,virement,cheque,carte,mobile_money',
            'reference_paiement' => 'nullable|string|max:255',
            'montant_total' => 'required|numeric|min:0',
            'montant_paye' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur de validation',
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

        // Vérifier si une facturation existe déjà pour ce PV
        $facturationExistante = Facturation::where('pv_reception_id', $request->pv_reception_id)->first();
        if ($facturationExistante) {
            return response()->json([
                'status' => 'error',
                'message' => 'Une facturation existe déjà pour ce PV de réception'
            ], 422);
        }

        // Générer le numéro de facture automatiquement
        $request->merge([
            'numero_facture' => Facturation::genererNumeroFacture()
        ]);

        $facturation = Facturation::create($request->all());
        
        // ✅ CORRECTION : Mettre à jour la dette du fournisseur seulement
        $nouvelleDette = $pvReception->dette_fournisseur - $request->montant_paye;
        $pvReception->update([
            'dette_fournisseur' => max(0, $nouvelleDette)
        ]);

        // ✅ SUPPRIMER : La création automatique d'impayé
        // Les impayés seront créés manuellement si nécessaire

        return response()->json([
            'status' => 'success',
            'message' => 'Facturation créée avec succès',
            'data' => $facturation->load(['pvReception.fournisseur', 'pvReception.provenance'])
        ], 201);

    } catch (\Exception $e) {
        // \Log::error('Erreur création facturation: ' . $e->getMessage());
        return response()->json([
            'status' => 'error',
            'message' => 'Erreur lors de la création de la facturation: ' . $e->getMessage()
        ], 500);
    }
}
    public function show($id)
    {
        try {
            $facturation = Facturation::with(['pvReception.fournisseur', 'pvReception.provenance'])->find($id);
            
            if (!$facturation) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Facturation non trouvée'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $facturation
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la récupération de la facturation'
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $facturation = Facturation::find($id);
            
            if (!$facturation) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Facturation non trouvée'
                ], 404);
            }

            $validator = validator($request->all(), [
                'date_facturation' => 'sometimes|date',
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

            $facturation->update($request->all());

            return response()->json([
                'status' => 'success',
                'message' => 'Facturation modifiée avec succès',
                'data' => $facturation->load(['pvReception.fournisseur', 'pvReception.provenance'])
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la modification de la facturation'
            ], 500);
        }
    }

    public function enregistrerPaiement(Request $request, $id)
    {
        try {
            $facturation = Facturation::find($id);
            
            if (!$facturation) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Facturation non trouvée'
                ], 404);
            }

            $validator = validator($request->all(), [
                'montant_paye' => 'required|numeric|min:0.01|max:' . $facturation->reste_a_payer,
                'mode_paiement' => 'required|in:especes,virement,cheque,carte,mobile_money',
                'reference_paiement' => 'nullable|string|max:255',
                'date_paiement' => 'nullable|date',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Mettre à jour le paiement
            $facturation->update([
                'montant_paye' => $facturation->montant_paye + $request->montant_paye,
                'mode_paiement' => $request->mode_paiement,
                'reference_paiement' => $request->reference_paiement,
                'date_paiement' => $request->date_paiement,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Paiement enregistré avec succès',
                'data' => $facturation->load(['pvReception.fournisseur', 'pvReception.provenance'])
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de l\'enregistrement du paiement'
            ], 500);
        }
    }
}