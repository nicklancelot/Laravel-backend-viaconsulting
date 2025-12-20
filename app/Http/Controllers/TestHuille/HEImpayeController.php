<?php

namespace App\Http\Controllers\TestHuille;

use App\Http\Controllers\Controller;
use App\Models\TestHuille\HEImpaye;
use App\Models\TestHuille\HEFacturation;
use App\Models\TestHuille\FicheReception;
use App\Models\TestHuille\Stockhe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HEImpayeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $impayes = HEImpaye::with([
                'facturation.ficheReception.fournisseur',
                'facturation.ficheReception.siteCollecte'
            ])
            ->orderBy('created_at', 'desc')
            ->get();
            
            return response()->json([
                'success' => true,
                'message' => 'Liste des impayés',
                'data' => $impayes,
                'count' => $impayes->count(),
                'total_impayes' => $impayes->sum('reste_a_payer')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des impayés',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
  // Dans HEImpayeController.php - méthode store
public function store(Request $request)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validate([
                'facturation_id' => 'required|exists:h_e_facturations,id',
                'montant_paye' => 'required|numeric|min:0'
            ]);

            $facturation = HEFacturation::with('ficheReception')->find($validated['facturation_id']);
            if (!$facturation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Facturation non trouvée'
                ], 404);
            }

            if ($facturation->reste_a_payer <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cette facturation est déjà entièrement payée'
                ], 400);
            }

            if ($validated['montant_paye'] > $facturation->reste_a_payer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le montant payé ne peut pas dépasser le reste à payer'
                ], 400);
            }

            // VÉRIFICATION SOLDE UTILISATEUR
            $soldeUser = \App\Models\SoldeUser::where('utilisateur_id', $facturation->ficheReception->utilisateur_id)->first();
            $soldeActuel = $soldeUser ? $soldeUser->solde : 0;

            if ($soldeActuel < $validated['montant_paye']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solde utilisateur insuffisant',
                    'solde_actuel' => $soldeActuel
                ], 400);
            }

            // DÉCRÉMENTER LE SOLDE UTILISATEUR
            if ($soldeUser && $validated['montant_paye'] > 0) {
                $soldeUser->decrement('solde', $validated['montant_paye']);
                $nouveauSolde = $soldeUser->solde;
            } else {
                $nouveauSolde = $soldeActuel;
            }

            // Mettre à jour la facturation
            $nouvelleAvance = $facturation->avance_versee + $validated['montant_paye'];
            $nouveauReste = $facturation->montant_total - $nouvelleAvance;

            $facturation->update([
                'avance_versee' => $nouvelleAvance,
                'reste_a_payer' => $nouveauReste
            ]);

            // Déterminer le statut de paiement
            $statutPaiement = $this->determinerStatutPaiement($nouvelleAvance, $nouveauReste);

            // Mettre à jour le statut de la fiche de réception
            $fiche = $facturation->ficheReception;
            $fiche->update(['statut' => $statutPaiement]);

            // ✅ AJOUTER AU STOCK SI LE NOUVEAU STATUT EST "payé"
            if ($statutPaiement === 'payé') {
                Stockhe::ajouterStock($fiche->quantite_totale);
            }

            $impaye = HEImpaye::updateOrCreate(
                ['facturation_id' => $validated['facturation_id']],
                [
                    'montant_du' => $facturation->montant_total,
                    'montant_paye' => $nouvelleAvance,
                    'reste_a_payer' => $nouveauReste
                ]
            );

            DB::commit();

            $impaye->load([
                'facturation.ficheReception.fournisseur',
                'facturation.ficheReception.siteCollecte'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Paiement de l\'impayé effectué avec succès',
                'data' => $impaye,
                'nouveau_statut' => $statutPaiement,
                'stock_ajoute' => $statutPaiement === 'payé',
                'solde_info' => [
                    'solde_avant' => $soldeActuel,
                    'solde_apres' => $nouveauSolde,
                    'montant_debite' => $validated['montant_paye']
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du paiement de l\'impayé',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        try {
            $impaye = HEImpaye::with([
                'facturation.ficheReception.fournisseur',
                'facturation.ficheReception.siteCollecte'
            ])->find($id);

            if (!$impaye) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impayé non trouvé'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Impayé trouvé',
                'data' => $impaye
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l\'impayé',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        try {
            DB::beginTransaction();

            $impaye = HEImpaye::with('facturation.ficheReception')->find($id);

            if (!$impaye) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impayé non trouvé'
                ], 404);
            }

            $validated = $request->validate([
                'montant_paye' => 'required|numeric|min:0'
            ]);

            // Vérifier que le montant payé ne dépasse pas le reste à payer
            if ($validated['montant_paye'] > $impaye->facturation->reste_a_payer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le montant payé ne peut pas dépasser le reste à payer (' . $impaye->facturation->reste_a_payer . ')'
                ], 400);
            }

            // VÉRIFICATION SOLDE UTILISATEUR
            $soldeUser = \App\Models\SoldeUser::where('utilisateur_id', $impaye->facturation->ficheReception->utilisateur_id)->first();
            $soldeActuel = $soldeUser ? $soldeUser->solde : 0;

            if ($soldeActuel < $validated['montant_paye']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solde utilisateur insuffisant. Solde disponible: ' . number_format($soldeActuel, 0, ',', ' ') . ' Ar - Montant à payer: ' . number_format($validated['montant_paye'], 0, ',', ' ') . ' Ar',
                    'solde_actuel' => $soldeActuel,
                    'montant_requis' => $validated['montant_paye'],
                    'solde_insuffisant' => true
                ], 400);
            }

            // DÉCRÉMENTER LE SOLDE UTILISATEUR
            if ($soldeUser && $validated['montant_paye'] > 0) {
                $soldeUser->decrement('solde', $validated['montant_paye']);
                $nouveauSolde = $soldeUser->solde;
            } else {
                $nouveauSolde = $soldeActuel;
            }

            // Mettre à jour la facturation
            $nouvelleAvance = $impaye->facturation->avance_versee + $validated['montant_paye'];
            $nouveauReste = $impaye->facturation->montant_total - $nouvelleAvance;

            $impaye->facturation->update([
                'avance_versee' => $nouvelleAvance,
                'reste_a_payer' => $nouveauReste
            ]);

            // Déterminer le statut de paiement
            $statutPaiement = $this->determinerStatutPaiement($nouvelleAvance, $nouveauReste);

            // Mettre à jour le statut de la fiche de réception
            $impaye->facturation->ficheReception->update(['statut' => $statutPaiement]);

            // Mettre à jour l'impayé
            $impaye->update([
                'montant_paye' => $nouvelleAvance,
                'reste_a_payer' => $nouveauReste
            ]);

            DB::commit();

            $impaye->load([
                'facturation.ficheReception.fournisseur',
                'facturation.ficheReception.siteCollecte'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Paiement de l\'impayé mis à jour avec succès',
                'data' => $impaye,
                'nouveau_statut' => $statutPaiement,
                'solde_info' => [
                    'solde_avant' => $soldeActuel,
                    'solde_apres' => $nouveauSolde,
                    'montant_debite' => $validated['montant_paye']
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du paiement',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        try {
            DB::beginTransaction();

            $impaye = HEImpaye::find($id);

            if (!$impaye) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impayé non trouvé'
                ], 404);
            }

            $impaye->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Impayé supprimé avec succès'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de l\'impayé',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer les impayés actifs (avec reste à payer > 0)
     */
    public function getImpayesActifs()
    {
        try {
            $impayes = HEImpaye::with([
                'facturation.ficheReception.fournisseur',
                'facturation.ficheReception.siteCollecte'
            ])
            ->where('reste_a_payer', '>', 0)
            ->orderBy('reste_a_payer', 'desc')
            ->get();

            return response()->json([
                'success' => true,
                'message' => 'Impayés actifs',
                'data' => $impayes,
                'count' => $impayes->count(),
                'total_impayes' => $impayes->sum('reste_a_payer')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des impayés actifs',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Déterminer le statut de paiement
     */
    private function determinerStatutPaiement($avanceVersee, $resteAPayer)
    {
        if ($resteAPayer <= 0) {
            return 'payé'; // Paiement complet
        } elseif ($avanceVersee > 0 && $resteAPayer > 0) {
            return 'payement incomplète'; // Paiement partiel
        } else {
            return 'en attente de paiement'; // Aucun paiement
        }
    }
}