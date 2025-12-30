<?php

namespace App\Http\Controllers\TestHuille;

use App\Http\Controllers\Controller;
use App\Models\TestHuille\HEFicheLivraison;
use App\Models\TestHuille\Stockhe;
use App\Models\Livreur;
use App\Models\Utilisateur;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class HEFicheLivraisonController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            // Pour admin: voir toutes les livraisons
            // Pour autres utilisateurs: voir seulement leurs livraisons liées à leur stock
            if ($user->role === 'admin') {
                $livraisons = HEFicheLivraison::with(['stockhe', 'livreur', 'vendeur'])
                    ->orderBy('created_at', 'desc')
                    ->get();
            } else {
                $livraisons = HEFicheLivraison::whereHas('stockhe', function($query) use ($user) {
                        $query->where('utilisateur_id', $user->id);
                    })
                    ->with(['stockhe', 'livreur', 'vendeur'])
                    ->orderBy('created_at', 'desc')
                    ->get();
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Liste des fiches de livraison',
                'data' => $livraisons,
                'count' => $livraisons->count(),
                'user_id' => $user->id,
                'role' => $user->role
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
    public function store(Request $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            $user = Auth::user();
            
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

            // RÉCUPÉRER LE STOCK PERSONNEL DE L'UTILISATEUR CONNECTÉ
            $stockPersonnel = Stockhe::where('utilisateur_id', $user->id)
                ->where('niveau_stock', 'utilisateur')
                ->first();
                
            if (!$stockPersonnel) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous n\'avez pas de stock personnel d\'huile essentielle',
                    'user_id' => $user->id,
                    'solution' => 'Vous devez d\'abord recevoir du stock via une fiche de réception payée'
                ], 404);
            }
            
            // VÉRIFICATION OBLIGATOIRE : Stock personnel ne doit pas être vide
            if ($stockPersonnel->stock_disponible <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Votre stock personnel d\'huile essentielle est vide',
                    'stock_disponible' => $stockPersonnel->stock_disponible,
                    'user_id' => $user->id,
                    'solution' => 'Vous ne pouvez pas créer de fiche de livraison sans stock personnel'
                ], 400);
            }

            // VÉRIFICATION : Quantité disponible dans le stock personnel
            if ($stockPersonnel->stock_disponible < $validated['quantite_a_livrer']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stock personnel insuffisant. Disponible: ' . 
                        number_format($stockPersonnel->stock_disponible, 2) . ' kg, Demandé: ' . 
                        number_format($validated['quantite_a_livrer'], 2) . ' kg',
                    'stock_disponible' => $stockPersonnel->stock_disponible,
                    'quantite_demandee' => $validated['quantite_a_livrer'],
                    'difference' => $validated['quantite_a_livrer'] - $stockPersonnel->stock_disponible,
                    'user_id' => $user->id
                ], 400);
            }

            // RÉCUPÉRER LE STOCK GLOBAL POUR VÉRIFICATION
            $stockGlobal = Stockhe::whereNull('utilisateur_id')
                ->where('niveau_stock', 'global')
                ->first();
                
            if (!$stockGlobal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stock global non initialisé'
                ], 500);
            }

            // VÉRIFIER QUE LE STOCK GLOBAL A SUFFISAMMENT (sécurité supplémentaire)
            if ($stockGlobal->stock_disponible < $validated['quantite_a_livrer']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stock global insuffisant pour valider la transaction',
                    'stock_global_disponible' => $stockGlobal->stock_disponible,
                    'quantite_demandee' => $validated['quantite_a_livrer']
                ], 400);
            }

            // ENREGISTRER LES STOCKS AVANT MODIFICATION
            $stockPersonnelAvant = $stockPersonnel->stock_disponible;
            $stockGlobalAvant = $stockGlobal->stock_disponible;

            // ✅ SOUSTRACTION DU STOCK PERSONNEL (OBLIGATOIRE)
            $stockPersonnel->decrement('stock_disponible', $validated['quantite_a_livrer']);
            $stockPersonnelApres = $stockPersonnel->stock_disponible;
            
            // ✅ SOUSTRACTION DU STOCK GLOBAL (AUSSI OBLIGATOIRE)
            $stockGlobal->decrement('stock_disponible', $validated['quantite_a_livrer']);
            $stockGlobalApres = $stockGlobal->stock_disponible;

            // Créer la fiche de livraison
            $livraison = HEFicheLivraison::create([
                'stockhe_id' => $stockPersonnel->id,
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
                'statut' => 'livree',
                'date_statut' => now(),
                'created_by' => $user->id
            ]);

            DB::commit();

            $livraison->load(['stockhe', 'livreur', 'vendeur']);

            return response()->json([
                'success' => true,
                'message' => 'Fiche de livraison d\'huile essentielle créée avec succès',
                'data' => $livraison,
                'stock_info' => [
                    'stock_personnel' => [
                        'id' => $stockPersonnel->id,
                        'stock_avant' => $stockPersonnelAvant,
                        'stock_apres' => $stockPersonnelApres,
                        'quantite_soustraction' => $validated['quantite_a_livrer'],
                        'utilisateur_id' => $user->id,
                        'utilisateur_nom' => $user->nom . ' ' . $user->prenom
                    ],
                    'stock_global' => [
                        'id' => $stockGlobal->id,
                        'stock_avant' => $stockGlobalAvant,
                        'stock_apres' => $stockGlobalApres,
                        'quantite_soustraction' => $validated['quantite_a_livrer']
                    ],
                    'total_soustrait' => $validated['quantite_a_livrer'] * 2,
                    'note' => 'Le stock a été soustrait à la fois du stock personnel et du stock global'
                ],
                'vendeur_info' => [
                    'id' => $vendeur->id,
                    'nom_complet' => $vendeur->nom . ' ' . $vendeur->prenom,
                    'localisation' => $vendeur->localisation->Nom ?? 'Non défini'
                ],
                'user_info' => [
                    'id' => $user->id,
                    'nom' => $user->nom,
                    'prenom' => $user->prenom,
                    'role' => $user->role
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
    public function show($id): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $livraison = HEFicheLivraison::with(['stockhe', 'livreur', 'vendeur'])->find($id);

            if (!$livraison) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fiche de livraison non trouvée',
                    'user_id' => $user->id
                ], 404);
            }

            // Vérifier que l'utilisateur a accès à cette fiche (sauf admin)
            if ($user->role !== 'admin') {
                if (!$livraison->stockhe || $livraison->stockhe->utilisateur_id != $user->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Accès non autorisé à cette fiche de livraison',
                        'user_id' => $user->id,
                        'livraison_user_id' => $livraison->stockhe ? $livraison->stockhe->utilisateur_id : null
                    ], 403);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Fiche de livraison trouvée',
                'data' => $livraison,
                'user_id' => $user->id,
                'role' => $user->role
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
    public function update(Request $request, $id): JsonResponse
    {
        try {
            DB::beginTransaction();

            $livraison = HEFicheLivraison::with('stockhe')->find($id);
            $user = Auth::user();

            if (!$livraison) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fiche de livraison non trouvée'
                ], 404);
            }

            // Vérifier que l'utilisateur a accès à cette fiche (sauf admin)
            if ($user->role !== 'admin') {
                if (!$livraison->stockhe || $livraison->stockhe->utilisateur_id != $user->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Accès non autorisé pour modifier cette fiche de livraison',
                        'user_id' => $user->id,
                        'livraison_user_id' => $livraison->stockhe ? $livraison->stockhe->utilisateur_id : null
                    ], 403);
                }
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
                'data' => $livraison,
                'user_id' => $user->id,
                'role' => $user->role
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
    public function destroy($id): JsonResponse
    {
        try {
            DB::beginTransaction();

            $livraison = HEFicheLivraison::with('stockhe')->find($id);
            $user = Auth::user();

            if (!$livraison) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fiche de livraison non trouvée'
                ], 404);
            }

            // Vérifier que l'utilisateur a accès à cette fiche (sauf admin)
            if ($user->role !== 'admin') {
                if (!$livraison->stockhe || $livraison->stockhe->utilisateur_id != $user->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Accès non autorisé pour supprimer cette fiche de livraison',
                        'user_id' => $user->id,
                        'livraison_user_id' => $livraison->stockhe ? $livraison->stockhe->utilisateur_id : null
                    ], 403);
                }
            }

            // Si la livraison est "livree", restaurer le stock avant suppression
            if ($livraison->estLivree()) {
                // Récupérer les informations de stock
                $stockPersonnel = $livraison->stockhe;
                $quantite = $livraison->quantite_a_livrer;
                
                // Restaurer le stock personnel
                if ($stockPersonnel) {
                    $stockPersonnel->increment('stock_disponible', $quantite);
                }
                
                // Restaurer le stock global
                $stockGlobal = Stockhe::whereNull('utilisateur_id')
                    ->where('niveau_stock', 'global')
                    ->first();
                    
                if ($stockGlobal) {
                    $stockGlobal->increment('stock_disponible', $quantite);
                }
            }
            
            // Sauvegarder les données avant suppression
            $quantiteALivrer = $livraison->quantite_a_livrer;
            $statut = $livraison->statut;
            
            $livraison->delete();

            DB::commit();

            // Récupérer le stock après restauration
            $stockPersonnelApres = Stockhe::where('utilisateur_id', $user->id)
                ->where('niveau_stock', 'utilisateur')
                ->first();
                
            $stockGlobalApres = Stockhe::whereNull('utilisateur_id')
                ->where('niveau_stock', 'global')
                ->first();

            return response()->json([
                'success' => true,
                'message' => 'Fiche de livraison supprimée avec succès',
                'suppression_info' => [
                    'quantite_restauree' => $livraison->estLivree() ? $quantiteALivrer : 0,
                    'statut_avant_suppression' => $statut,
                    'stock_personnel_apres' => $stockPersonnelApres ? $stockPersonnelApres->stock_disponible : 0,
                    'stock_global_apres' => $stockGlobalApres ? $stockGlobalApres->stock_disponible : 0
                ],
                'user_id' => $user->id,
                'role' => $user->role
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
    public function getLivreurs(): JsonResponse
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
    public function getVendeurs(): JsonResponse
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
    public function getEtatStock(): JsonResponse
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
    public function annulerLivraison($id): JsonResponse
    {
        try {
            DB::beginTransaction();

            $livraison = HEFicheLivraison::with(['stockhe', 'vendeur'])->find($id);
            $user = Auth::user();

            if (!$livraison) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fiche de livraison non trouvée'
                ], 404);
            }

            // Vérifier que l'utilisateur a accès à cette fiche (sauf admin)
            if ($user->role !== 'admin') {
                if (!$livraison->stockhe || $livraison->stockhe->utilisateur_id != $user->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Accès non autorisé pour annuler cette livraison',
                        'user_id' => $user->id,
                        'livraison_user_id' => $livraison->stockhe ? $livraison->stockhe->utilisateur_id : null
                    ], 403);
                }
            }

            // Vérifier que la livraison n'est pas déjà annulée
            if ($livraison->estAnnulee()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cette livraison est déjà annulée'
                ], 400);
            }

            // Déterminer d'où vient le stock
            $stockPersonnel = $livraison->stockhe;
            $quantite = $livraison->quantite_a_livrer;
            
            if (!$stockPersonnel) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stock personnel non trouvé pour cette livraison'
                ], 404);
            }

            // Annuler la livraison
            $livraison->statut = 'annulee';
            $livraison->date_statut = now();
            $livraison->save();

            // Restaurer le stock personnel
            $stockPersonnel->increment('stock_disponible', $quantite);
            
            // Restaurer le stock global
            $stockGlobal = Stockhe::whereNull('utilisateur_id')
                ->where('niveau_stock', 'global')
                ->first();
                
            if ($stockGlobal) {
                $stockGlobal->increment('stock_disponible', $quantite);
            }

            DB::commit();

            $livraison->load(['stockhe', 'livreur', 'vendeur']);

            // Récupérer le stock après restauration
            $stockPersonnelApres = Stockhe::where('utilisateur_id', $stockPersonnel->utilisateur_id)
                ->where('niveau_stock', 'utilisateur')
                ->first();
                
            $stockGlobalApres = Stockhe::whereNull('utilisateur_id')
                ->where('niveau_stock', 'global')
                ->first();

            return response()->json([
                'success' => true,
                'message' => 'Livraison annulée avec succès',
                'data' => $livraison,
                'annulation_info' => [
                    'quantite_restauree' => $quantite,
                    'source_stock' => 'personnel',
                    'utilisateur_id' => $stockPersonnel->utilisateur_id,
                    'stock_personnel_apres' => $stockPersonnelApres ? $stockPersonnelApres->stock_disponible : 0,
                    'stock_global_apres' => $stockGlobalApres ? $stockGlobalApres->stock_disponible : 0
                ],
                'user_id' => $user->id,
                'role' => $user->role
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

    /**
     * Vérifier le stock disponible pour l'utilisateur connecté
     */
    public function verifierStockDisponible(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $request->validate([
                'quantite' => 'required|numeric|min:0'
            ]);
            
            $quantite = $request->quantite;
            
            // Récupérer le stock personnel
            $stockPersonnel = Stockhe::where('utilisateur_id', $user->id)
                ->where('niveau_stock', 'utilisateur')
                ->first();
            
            $stockPersonnelDisponible = $stockPersonnel ? $stockPersonnel->stock_disponible : 0;
            $disponible = $stockPersonnelDisponible >= $quantite;
            
            // Récupérer le stock global (pour information)
            $stockGlobal = Stockhe::whereNull('utilisateur_id')
                ->where('niveau_stock', 'global')
                ->first();
                
            $stockGlobalDisponible = $stockGlobal ? $stockGlobal->stock_disponible : 0;
            
            return response()->json([
                'success' => true,
                'disponible' => $disponible,
                'message' => $disponible ? 
                    'Stock personnel suffisant' : 
                    'Stock personnel insuffisant',
                'quantite_demandee' => $quantite,
                'stock_personnel_disponible' => $stockPersonnelDisponible,
                'stock_global_disponible' => $stockGlobalDisponible,
                'regle' => 'Seul le stock personnel compte pour créer des fiches de livraison',
                'user_id' => $user->id,
                'role' => $user->role
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la vérification du stock',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer les stocks disponibles pour l'utilisateur connecté
     * Seul le stock personnel compte pour créer des fiches
     */
    public function getStocksDisponiblesUtilisateur(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non connecté'
                ], 401);
            }

            // RÉCUPÉRER LE STOCK PERSONNEL (SEUL COMPTE POUR CRÉER DES FICHES)
            $stockPersonnel = Stockhe::where('utilisateur_id', $user->id)
                ->where('niveau_stock', 'utilisateur')
                ->first();
            
            // Stock global (pour information seulement)
            $stockGlobal = Stockhe::whereNull('utilisateur_id')
                ->where('niveau_stock', 'global')
                ->first();

            // Formater la réponse
            $result = [
                'personnel' => $stockPersonnel ? [
                    'id' => $stockPersonnel->id,
                    'stock_disponible' => (float) $stockPersonnel->stock_disponible,
                    'stock_total' => (float) $stockPersonnel->stock_total,
                    'utilisateur_id' => $stockPersonnel->utilisateur_id,
                    'niveau_stock' => 'utilisateur',
                    'peut_livrer' => $stockPersonnel->stock_disponible > 0,
                    'description' => 'Votre stock personnel (obligatoire pour créer une fiche)'
                ] : null,
                'global' => $stockGlobal ? [
                    'id' => $stockGlobal->id,
                    'stock_disponible' => (float) $stockGlobal->stock_disponible,
                    'stock_total' => (float) $stockGlobal->stock_total,
                    'niveau_stock' => 'global',
                    'peut_livrer' => false,
                    'description' => 'Stock global (non utilisable directement pour créer des fiches)'
                ] : null
            ];

            $aDuStockPersonnel = $stockPersonnel && $stockPersonnel->stock_disponible > 0;
            
            return response()->json([
                'success' => true,
                'data' => $result,
                'user' => [
                    'id' => $user->id,
                    'role' => $user->role,
                    'a_du_stock_personnel' => $aDuStockPersonnel,
                    'peut_creer_fiche' => $aDuStockPersonnel,
                    'message' => $aDuStockPersonnel ? 
                        '✅ Vous avez ' . number_format($stockPersonnel->stock_disponible, 2) . ' kg dans votre stock personnel' :
                        '❌ Vous ne pouvez pas créer de fiche (stock personnel vide ou inexistant)'
                ],
                'regles' => [
                    '1' => 'Seul le stock personnel permet de créer des fiches de livraison',
                    '2' => 'Le stock global est une vérification de sécurité seulement',
                    '3' => 'La quantité sera soustraite du stock personnel ET du stock global'
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des stocks',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne'
            ], 500);
        }
    }

    /**
     * Vérifier si l'utilisateur peut créer une fiche de livraison
     */
    public function peutCreerFicheLivraison(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non connecté'
                ], 401);
            }

            // Vérifier le stock personnel
            $stockPersonnel = Stockhe::where('utilisateur_id', $user->id)
                ->where('niveau_stock', 'utilisateur')
                ->first();
            
            $aDuStockPersonnel = $stockPersonnel && $stockPersonnel->stock_disponible > 0;
            
            // Vérifier le stock global (pour information seulement)
            $stockGlobal = Stockhe::whereNull('utilisateur_id')
                ->where('niveau_stock', 'global')
                ->first();
            
            $stockGlobalDisponible = $stockGlobal ? $stockGlobal->stock_disponible : 0;

            return response()->json([
                'success' => true,
                'peut_creer_fiche' => $aDuStockPersonnel,
                'message' => $aDuStockPersonnel ? 
                    'Vous avez du stock personnel et pouvez créer une fiche de livraison' : 
                    'Vous ne pouvez pas créer de fiche de livraison (stock personnel vide ou inexistant)',
                'stock_info' => [
                    'personnel' => $stockPersonnel ? [
                        'id' => $stockPersonnel->id,
                        'stock_disponible' => (float) $stockPersonnel->stock_disponible,
                        'stock_total' => (float) $stockPersonnel->stock_total,
                        'utilisateur_id' => $stockPersonnel->utilisateur_id
                    ] : null,
                    'global' => $stockGlobal ? [
                        'id' => $stockGlobal->id,
                        'stock_disponible' => (float) $stockGlobal->stock_disponible,
                        'stock_total' => (float) $stockGlobal->stock_total
                    ] : null
                ],
                'regles' => [
                    'condition_obligatoire' => 'Doit avoir du stock personnel > 0',
                    'soustraction' => 'Le stock sera soustrait du stock personnel ET du stock global',
                    'impossibilite' => 'Si stock personnel = 0 → Impossible de créer une fiche'
                ],
                'user_id' => $user->id,
                'role' => $user->role
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la vérification',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}