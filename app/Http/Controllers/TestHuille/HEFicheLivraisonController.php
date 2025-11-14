<?php

namespace App\Http\Controllers\TestHuille;

use App\Http\Controllers\Controller;
use App\Models\TestHuille\HEFicheLivraison;
use App\Models\TestHuille\FicheReception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HEFicheLivraisonController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $livraisons = HEFicheLivraison::with(['ficheReception.fournisseur', 'ficheReception.siteCollecte'])
                ->orderBy('created_at', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'message' => 'Liste des fiches de livraison',
                'data' => $livraisons,
                'count' => $livraisons->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des fiches de livraison',
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
                'date_heure_livraison' => 'required|date',
                'nom_livreur' => 'required|string|max:100',
                'prenom_livreur' => 'required|string|max:100',
                'telephone_livreur' => 'required|string|max:20',
                'numero_vehicule' => 'required|string|max:50',
                'nom_destinataire' => 'required|string|max:100',
                'prenom_destinataire' => 'required|string|max:100',
                'fonction_destinataire' => 'required|string|max:100',
                'telephone_destinataire' => 'required|string|max:20',
                'lieu_depart' => 'required|string|max:100',
                'destination' => 'required|string|max:100',
                'type_produit' => 'required|string|max:100',
                'poids_net' => 'required|numeric|min:0',
                'ristourne_regionale' => 'nullable|numeric|min:0',
                'ristourne_communale' => 'nullable|numeric|min:0'
            ]);

            // Vérifier si la fiche existe et a le statut "payé"
            $fiche = FicheReception::find($validated['fiche_reception_id']);
            if (!$fiche) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fiche de réception non trouvée'
                ], 404);
            }

            // Vérifier si la fiche est payée
            if ($fiche->statut !== 'payé') {
                return response()->json([
                    'success' => false,
                    'message' => 'Seules les fiches avec statut "payé" peuvent être livrées'
                ], 400);
            }

            // Vérifier si une fiche de livraison existe déjà pour cette fiche
            $existingLivraison = HEFicheLivraison::where('fiche_reception_id', $validated['fiche_reception_id'])->first();
            if ($existingLivraison) {
                return response()->json([
                    'success' => false,
                    'message' => 'Une fiche de livraison existe déjà pour cette fiche de réception'
                ], 409);
            }

            $livraison = HEFicheLivraison::create($validated);

            // Mettre à jour le statut de la fiche de réception
            $fiche->update(['statut' => 'En attente de livraison']);

            DB::commit();

            $livraison->load(['ficheReception.fournisseur', 'ficheReception.siteCollecte']);

            return response()->json([
                'success' => true,
                'message' => 'Fiche de livraison créée avec succès',
                'data' => $livraison,
                'nouveau_statut' => 'En attente de livraison'
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
                'message' => 'Erreur lors de la création de la fiche de livraison',
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
            $livraison = HEFicheLivraison::with(['ficheReception.fournisseur', 'ficheReception.siteCollecte'])->find($id);

            if (!$livraison) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fiche de livraison non trouvée'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Fiche de livraison trouvée',
                'data' => $livraison
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la fiche de livraison',
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

            $livraison = HEFicheLivraison::find($id);

            if (!$livraison) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fiche de livraison non trouvée'
                ], 404);
            }

            $validated = $request->validate([
                'date_heure_livraison' => 'sometimes|date',
                'nom_livreur' => 'sometimes|string|max:100',
                'prenom_livreur' => 'sometimes|string|max:100',
                'telephone_livreur' => 'sometimes|string|max:20',
                'numero_vehicule' => 'sometimes|string|max:50',
                'nom_destinataire' => 'sometimes|string|max:100',
                'prenom_destinataire' => 'sometimes|string|max:100',
                'fonction_destinataire' => 'sometimes|string|max:100',
                'telephone_destinataire' => 'sometimes|string|max:20',
                'lieu_depart' => 'sometimes|string|max:100',
                'destination' => 'sometimes|string|max:100',
                'type_produit' => 'sometimes|string|max:100',
                'poids_net' => 'sometimes|numeric|min:0',
                'ristourne_regionale' => 'nullable|numeric|min:0',
                'ristourne_communale' => 'nullable|numeric|min:0'
            ]);

            $livraison->update($validated);

            DB::commit();

            $livraison->load(['ficheReception.fournisseur', 'ficheReception.siteCollecte']);

            return response()->json([
                'success' => true,
                'message' => 'Fiche de livraison mise à jour avec succès',
                'data' => $livraison
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
                'message' => 'Erreur lors de la mise à jour de la fiche de livraison',
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

            $livraison = HEFicheLivraison::find($id);

            if (!$livraison) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fiche de livraison non trouvée'
                ], 404);
            }

            // Remettre le statut de la fiche à "payé"
            $fiche = $livraison->ficheReception;
            $fiche->update(['statut' => 'payé']);

            $livraison->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Fiche de livraison supprimée avec succès'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la fiche de livraison',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer la fiche de livraison par fiche de réception
     */
    public function getByFicheReception($fiche_reception_id)
    {
        try {
            $livraison = HEFicheLivraison::with(['ficheReception.fournisseur', 'ficheReception.siteCollecte'])
                ->where('fiche_reception_id', $fiche_reception_id)
                ->first();

            if (!$livraison) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune fiche de livraison trouvée pour cette fiche de réception'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Fiche de livraison trouvée',
                'data' => $livraison
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la fiche de livraison',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}