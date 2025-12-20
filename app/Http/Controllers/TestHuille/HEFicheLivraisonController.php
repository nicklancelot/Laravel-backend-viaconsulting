<?php

namespace App\Http\Controllers\TestHuille;

use App\Http\Controllers\Controller;
use App\Models\TestHuille\HEFicheLivraison;
use App\Models\TestHuille\Stockhe;
use App\Models\Livreur;
use App\Models\Utilisateur;
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
            $livraisons = HEFicheLivraison::with([
                'stockhe', // CHANGÉ: stockhe au lieu de ficheReception
                'livreur',
                'vendeur'
            ])
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
            'livreur_id' => 'required|exists:livreurs,id',
            'vendeur_id' => 'required|exists:utilisateurs,id',
            'date_heure_livraison' => 'required|date',
            'fonction_destinataire' => 'required|string|max:100',
            'lieu_depart' => 'required|string|max:100',
            'destination' => 'required|string|max:100',
            'type_produit' => 'required|string|max:100',
            'poids_net' => 'required|numeric|min:0',
            'quantite_a_livrer' => 'required|numeric|min:0',
            'ristourne_regionale' => 'nullable|numeric|min:0',
            'ristourne_communale' => 'nullable|numeric|min:0'
        ]);

        // Vérifier que c'est un vendeur
        $vendeur = Utilisateur::where('id', $validated['vendeur_id'])
            ->where('role', 'vendeur')
            ->first();
        
        if (!$vendeur) {
            return response()->json([
                'success' => false,
                'message' => 'L\'utilisateur sélectionné doit être un vendeur'
            ], 400);
        }

        // Récupérer le stock actuel
        $stockhe = Stockhe::getStockActuel();
        if (!$stockhe) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun stock disponible'
            ], 404);
        }

        // Vérifier la quantité disponible
        if ($stockhe->stock_disponible < $validated['quantite_a_livrer']) {
            return response()->json([
                'success' => false,
                'message' => 'Stock insuffisant. Disponible: ' . $stockhe->stock_disponible . ' kg, Demandé: ' . $validated['quantite_a_livrer'] . ' kg'
            ], 400);
        }

        // Créer la fiche de livraison (statut "livree" par défaut)
        $livraison = HEFicheLivraison::create([
            'stockhe_id' => $stockhe->id,
            'livreur_id' => $validated['livreur_id'],
            'vendeur_id' => $validated['vendeur_id'],
            'date_heure_livraison' => $validated['date_heure_livraison'],
            'fonction_destinataire' => $validated['fonction_destinataire'],
            'lieu_depart' => $validated['lieu_depart'],
            'destination' => $validated['destination'],
            'type_produit' => $validated['type_produit'],
            'poids_net' => $validated['poids_net'],
            'quantite_a_livrer' => $validated['quantite_a_livrer'],
            'ristourne_regionale' => $validated['ristourne_regionale'] ?? 0,
            'ristourne_communale' => $validated['ristourne_communale'] ?? 0,
            'statut' => 'livree', // Statut par défaut
            'date_statut' => now()
        ]);

        // ✅ SOUSTRAIRE DU STOCK (car statut = "livree")
        $retraitReussi = Stockhe::retirerStock($validated['quantite_a_livrer']);
        
        if (!$retraitReussi) {
            throw new \Exception('Erreur lors du retrait du stock');
        }

        DB::commit();

        $livraison->load(['stockhe', 'livreur', 'vendeur']);

        // Recharger le stock après modification
        $stockhe = Stockhe::getStockActuel();

        return response()->json([
            'success' => true,
            'message' => 'Fiche de livraison créée avec succès (statut: livrée)',
            'data' => $livraison,
            'stock_info' => [
                'stock_id' => $stockhe->id,
                'stock_total' => $stockhe->stock_total,
                'stock_disponible' => $stockhe->stock_disponible,
                'quantite_livree' => $validated['quantite_a_livrer'],
                'reste_en_stock' => $stockhe->stock_disponible,
                'statut_livraison' => 'livree'
            ]
        ], 201);

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
            $livraison = HEFicheLivraison::with([
                'stockhe',
                'livreur',
                'vendeur'
            ])->find($id);

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

            $livraison = HEFicheLivraison::with('stockhe')->find($id);

            if (!$livraison) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fiche de livraison non trouvée'
                ], 404);
            }

            $validated = $request->validate([
                'livreur_id' => 'sometimes|exists:livreurs,id',
                'vendeur_id' => 'sometimes|exists:utilisateurs,id',
                'date_heure_livraison' => 'sometimes|date',
                'fonction_destinataire' => 'sometimes|string|max:100',
                'lieu_depart' => 'sometimes|string|max:100',
                'destination' => 'sometimes|string|max:100',
                'type_produit' => 'sometimes|string|max:100',
                'poids_net' => 'sometimes|numeric|min:0',
                'ristourne_regionale' => 'nullable|numeric|min:0',
                'ristourne_communale' => 'nullable|numeric|min:0'
            ]);

            // Si vendeur_id est modifié, vérifier que c'est bien un vendeur
            if (isset($validated['vendeur_id'])) {
                $vendeur = Utilisateur::where('id', $validated['vendeur_id'])
                    ->where('role', 'vendeur')
                    ->first();
                
                if (!$vendeur) {
                    return response()->json([
                        'success' => false,
                        'message' => 'L\'utilisateur sélectionné doit être un vendeur'
                    ], 400);
                }
            }

            $livraison->update($validated);

            DB::commit();

            $livraison->load(['stockhe', 'livreur', 'vendeur']);

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

        $livraison = HEFicheLivraison::with('stockhe')->find($id);

        if (!$livraison) {
            return response()->json([
                'success' => false,
                'message' => 'Fiche de livraison non trouvée'
            ], 404);
        }

        // Si la livraison est "livree", restaurer le stock avant suppression
        if ($livraison->estLivree()) {
            Stockhe::ajouterStock($livraison->quantite_a_livrer);
        }
        
        // Sauvegarder les données avant suppression
        $quantiteALivrer = $livraison->quantite_a_livrer;
        $statut = $livraison->statut;
        
        $livraison->delete();

        DB::commit();

        // Recharger le stock
        $stockhe = Stockhe::getStockActuel();

        return response()->json([
            'success' => true,
            'message' => 'Fiche de livraison supprimée avec succès',
            'suppression_info' => [
                'quantite_restauree' => $livraison->estLivree() ? $quantiteALivrer : 0,
                'statut_avant_suppression' => $statut,
                'stock_apres_suppression' => $stockhe->stock_disponible
            ]
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
     * Récupérer tous les livreurs disponibles
     */
    public function getLivreurs()
    {
        try {
            $livreurs = Livreur::all();
            
            return response()->json([
                'success' => true,
                'message' => 'Liste des livreurs',
                'data' => $livreurs,
                'count' => $livreurs->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des livreurs',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer tous les vendeurs disponibles
     */
    public function getVendeurs()
    {
        try {
            $vendeurs = Utilisateur::where('role', 'vendeur')
                ->select('id', 'nom', 'prenom', 'numero', 'CIN', 'localisation_id')
                ->with('localisation')
                ->get()
                ->map(function ($vendeur) {
                    return [
                        'id' => $vendeur->id,
                        'nom_complet' => $vendeur->nom . ' ' . $vendeur->prenom,
                        'nom' => $vendeur->nom,
                        'prenom' => $vendeur->prenom,
                        'numero' => $vendeur->numero,
                        'CIN' => $vendeur->CIN,
                        'localisation' => $vendeur->localisation->Nom ?? 'Non défini',
                        'localisation_id' => $vendeur->localisation_id
                    ];
                });
            
            return response()->json([
                'success' => true,
                'message' => 'Liste des vendeurs',
                'data' => $vendeurs,
                'count' => $vendeurs->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des vendeurs',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer l'état du stock
     */
    public function getEtatStock()
    {
        try {
            $stock = Stockhe::getStockActuel();
            
            if (!$stock) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stock non initialisé'
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'data' => $stock
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du stock',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
 * Annuler une fiche de livraison
 */
public function annulerLivraison($id)
{
    try {
        DB::beginTransaction();

        $livraison = HEFicheLivraison::with('stockhe')->find($id);

        if (!$livraison) {
            return response()->json([
                'success' => false,
                'message' => 'Fiche de livraison non trouvée'
            ], 404);
        }

        // Vérifier que la livraison n'est pas déjà annulée
        if ($livraison->estAnnulee()) {
            return response()->json([
                'success' => false,
                'message' => 'Cette livraison est déjà annulée'
            ], 400);
        }

        // Annuler la livraison (restaure automatiquement le stock)
        $livraison->marquerAnnulee();

        DB::commit();

        $livraison->load(['stockhe', 'livreur', 'vendeur']);

        // Recharger le stock après restauration
        $stockhe = Stockhe::getStockActuel();

        return response()->json([
            'success' => true,
            'message' => 'Livraison annulée avec succès',
            'data' => $livraison,
            'stock_info' => [
                'stock_id' => $stockhe->id,
                'stock_total' => $stockhe->stock_total,
                'stock_disponible' => $stockhe->stock_disponible,
                'quantite_restauree' => $livraison->quantite_a_livrer,
                'statut_livraison' => 'annulee'
            ]
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de l\'annulation de la livraison',
            'error' => $e->getMessage()
        ], 500);
    }
}
}