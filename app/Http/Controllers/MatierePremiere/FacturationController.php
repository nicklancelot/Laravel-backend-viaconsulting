<?php

namespace App\Http\Controllers\MatierePremiere;

use App\Http\Controllers\Controller;
use App\Models\MatierePremiere\Facturation;
use App\Models\MatierePremiere\Impaye;
use App\Models\MatierePremiere\PVReception;
use App\Models\SoldeUser;
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
                'montant_total' => 'nullable|numeric|min:0',
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

            // Générer le numéro de facture automatiquement
            $request->merge([
                'numero_facture' => Facturation::genererNumeroFacture()
            ]);

            $facturation = Facturation::create($request->all());
            
            // Mettre à jour la dette du fournisseur
            $nouvelleDette = $pvReception->dette_fournisseur - $request->montant_paye;
            
            // DÉTERMINER LE NOUVEAU STATUT
            $nouveauStatut = $pvReception->statut;
            if ($nouvelleDette <= 0) {
                // Si la dette est complètement payée
                $nouveauStatut = 'paye';
            } else {
                // Si il reste de la dette
                $nouveauStatut = 'incomplet';
            }

            // Mettre à jour le PV de réception
            $pvReception->update([
                'dette_fournisseur' => max(0, $nouvelleDette),
                'statut' => $nouveauStatut
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Facturation créée avec succès',
                'data' => $facturation->load(['pvReception.fournisseur', 'pvReception.provenance']),
                'solde_info' => [
                    'solde_avant' => $soldeActuel,
                    'solde_apres' => $nouveauSolde,
                    'montant_debite' => $request->montant_paye
                ]
            ], 201);

        } catch (\Exception $e) {
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