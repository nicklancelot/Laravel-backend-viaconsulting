<?php

namespace App\Http\Controllers\TestHuille;

use App\Http\Controllers\Controller;
use App\Models\TestHuille\HEFacturation;
use App\Models\TestHuille\FicheReception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HEFacturationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $facturations = HEFacturation::with(['ficheReception.fournisseur', 'ficheReception.siteCollecte'])
                ->orderBy('created_at', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'message' => 'Liste des facturations',
                'data' => $facturations,
                'count' => $facturations->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des facturations',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validate([
                'fiche_reception_id' => 'required|exists:fiche_receptions,id',
                // Supprimé: 'prix_unitaire' => 'required|numeric|min:0',
                'montant_total' => 'required|numeric|min:0',
                'avance_versee' => 'required|numeric|min:0',
                'controller_qualite' => 'required|string|max:100',
                'responsable_commercial' => 'required|string|max:100'
            ]);

            // Vérifier si la fiche existe et a un statut acceptable pour la facturation
            $fiche = FicheReception::find($validated['fiche_reception_id']);
            if (!$fiche) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fiche de réception non trouvée'
                ], 404);
            }

            // Vérifier si la fiche est acceptée
            if ($fiche->statut !== 'Accepté') {
                return response()->json([
                    'success' => false,
                    'message' => 'Seules les fiches avec statut "Accepté" peuvent être facturées'
                ], 400);
            }

            // Vérifier si une facturation existe déjà pour cette fiche
            $existingFacturation = HEFacturation::where('fiche_reception_id', $validated['fiche_reception_id'])->first();
            if ($existingFacturation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Une facturation existe déjà pour cette fiche de réception'
                ], 409);
            }

            // VÉRIFICATION SOLDE UTILISATEUR
            $soldeUser = \App\Models\SoldeUser::where('utilisateur_id', $fiche->utilisateur_id)->first();
            $soldeActuel = $soldeUser ? $soldeUser->solde : 0;

            if ($soldeActuel < $validated['avance_versee']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solde utilisateur insuffisant. Solde disponible: ' . number_format($soldeActuel, 0, ',', ' ') . ' Ar - Avance à verser: ' . number_format($validated['avance_versee'], 0, ',', ' ') . ' Ar',
                    'solde_actuel' => $soldeActuel,
                    'avance_requise' => $validated['avance_versee'],
                    'solde_insuffisant' => true
                ], 400);
            }

            // DÉCRÉMENTER LE SOLDE UTILISATEUR
            if ($soldeUser && $validated['avance_versee'] > 0) {
                $soldeUser->decrement('solde', $validated['avance_versee']);
                $nouveauSolde = $soldeUser->solde;
            } else {
                $nouveauSolde = $soldeActuel;
            }

            // Calculer le reste à payer
            $resteAPayer = $validated['montant_total'] - $validated['avance_versee'];
            if ($resteAPayer < 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'L\'avance versée ne peut pas dépasser le montant total'
                ], 400);
            }

            // Déterminer le statut de paiement selon les statuts existants
            $statutPaiement = $this->determinerStatutPaiement($validated['avance_versee'], $resteAPayer);

            $facturation = HEFacturation::create([
                'fiche_reception_id' => $validated['fiche_reception_id'],
                // Supprimé: 'prix_unitaire' => $validated['prix_unitaire'],
                'montant_total' => $validated['montant_total'],
                'avance_versee' => $validated['avance_versee'],
                'reste_a_payer' => $resteAPayer,
                'controller_qualite' => $validated['controller_qualite'],
                'responsable_commercial' => $validated['responsable_commercial']
            ]);

            // Mettre à jour le statut de la fiche de réception
            $fiche->update(['statut' => $statutPaiement]);

            DB::commit();

            $facturation->load(['ficheReception.fournisseur', 'ficheReception.siteCollecte']);

            return response()->json([
                'success' => true,
                'message' => 'Facturation créée avec succès',
                'data' => $facturation,
                'nouveau_statut' => $statutPaiement,
                'solde_info' => [
                    'solde_avant' => $soldeActuel,
                    'solde_apres' => $nouveauSolde,
                    'montant_debite' => $validated['avance_versee']
                ]
            ], 201);

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
                'message' => 'Erreur lors de la création de la facturation',
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
            $facturation = HEFacturation::with(['ficheReception.fournisseur', 'ficheReception.siteCollecte'])->find($id);

            if (!$facturation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Facturation non trouvée'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Facturation trouvée',
                'data' => $facturation
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la facturation',
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

            $facturation = HEFacturation::find($id);

            if (!$facturation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Facturation non trouvée'
                ], 404);
            }

            $validated = $request->validate([
                // Supprimé: 'prix_unitaire' => 'sometimes|numeric|min:0',
                'montant_total' => 'sometimes|numeric|min:0',
                'avance_versee' => 'sometimes|numeric|min:0',
                'controller_qualite' => 'sometimes|string|max:100',
                'responsable_commercial' => 'sometimes|string|max:100'
            ]);

            // Recalculer le reste à payer si montant_total ou avance_versee est modifié
            if ($request->has('montant_total') || $request->has('avance_versee')) {
                $montantTotal = $request->has('montant_total') ? $request->montant_total : $facturation->montant_total;
                $avanceVersee = $request->has('avance_versee') ? $request->avance_versee : $facturation->avance_versee;
                
                $resteAPayer = $montantTotal - $avanceVersee;
                if ($resteAPayer < 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'L\'avance versée ne peut pas dépasser le montant total'
                    ], 400);
                }
                
                $validated['reste_a_payer'] = $resteAPayer;
                
                // Mettre à jour le statut de paiement selon les statuts existants
                $statutPaiement = $this->determinerStatutPaiement($avanceVersee, $resteAPayer);
                
                // Mettre à jour le statut de la fiche de réception
                $fiche = $facturation->ficheReception;
                $fiche->update(['statut' => $statutPaiement]);
            }

            $facturation->update($validated);

            DB::commit();

            $facturation->load(['ficheReception.fournisseur', 'ficheReception.siteCollecte']);

            return response()->json([
                'success' => true,
                'message' => 'Facturation mise à jour avec succès',
                'data' => $facturation
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
                'message' => 'Erreur lors de la mise à jour de la facturation',
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

            $facturation = HEFacturation::find($id);

            if (!$facturation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Facturation non trouvée'
                ], 404);
            }

            // Remettre le statut de la fiche à "Accepté"
            $fiche = $facturation->ficheReception;
            $fiche->update(['statut' => 'Accepté']);

            $facturation->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Facturation supprimée avec succès'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la facturation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Ajouter un paiement (avance supplémentaire)
     */
    public function ajouterPaiement(Request $request, $id)
    {
        try {
            DB::beginTransaction();

            $facturation = HEFacturation::find($id);

            if (!$facturation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Facturation non trouvée'
                ], 404);
            }

            $validated = $request->validate([
                'montant_paiement' => 'required|numeric|min:0'
            ]);

            $nouvelleAvance = $facturation->avance_versee + $validated['montant_paiement'];
            $nouveauReste = $facturation->montant_total - $nouvelleAvance;

            if ($nouveauReste < 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le paiement ne peut pas dépasser le montant total'
                ], 400);
            }

            // Déterminer le statut selon les statuts existants
            $statutPaiement = $this->determinerStatutPaiement($nouvelleAvance, $nouveauReste);

            $facturation->update([
                'avance_versee' => $nouvelleAvance,
                'reste_a_payer' => $nouveauReste
            ]);

            // Mettre à jour le statut de la fiche de réception
            $fiche = $facturation->ficheReception;
            $fiche->update(['statut' => $statutPaiement]);

            DB::commit();

            $facturation->load(['ficheReception.fournisseur', 'ficheReception.siteCollecte']);

            return response()->json([
                'success' => true,
                'message' => 'Paiement ajouté avec succès',
                'data' => $facturation,
                'nouveau_statut' => $statutPaiement
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
                'message' => 'Erreur lors de l\'ajout du paiement',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer les facturations par statut de paiement
     */
    public function getByStatutPaiement($statut)
    {
        try {
            $statutsValides = ['payé', 'payement incomplète', 'en attente de paiement'];
            
            if (!in_array($statut, $statutsValides)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Statut de paiement invalide',
                    'statuts_valides' => $statutsValides
                ], 400);
            }

            $facturations = HEFacturation::with(['ficheReception.fournisseur', 'ficheReception.siteCollecte'])
                ->whereHas('ficheReception', function($query) use ($statut) {
                    $query->where('statut', $statut);
                })
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => "Facturations avec le statut: $statut",
                'data' => $facturations,
                'count' => $facturations->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des facturations',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Déterminer le statut de paiement selon les statuts existants
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

    /**
     * Récupérer les impayés (facturations avec paiement incomplet)
     */
    public function getImpayes()
    {
        try {
            $impayes = HEFacturation::with(['ficheReception.fournisseur', 'ficheReception.siteCollecte'])
                ->whereHas('ficheReception', function($query) {
                    $query->whereIn('statut', ['payement incomplète', 'en attente de paiement']);
                })
                ->orderBy('reste_a_payer', 'desc')
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
}