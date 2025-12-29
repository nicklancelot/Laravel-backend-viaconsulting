<?php

namespace App\Http\Controllers\MatierePremiere;

use App\Http\Controllers\Controller;
use App\Models\MatierePremiere\FicheLivraison;
use App\Models\MatierePremiere\Stockpv;
use App\Models\Utilisateur;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\Livreur;

class FicheLivraisonController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            // Pour admin: voir toutes les fiches
            // Pour autres utilisateurs: voir seulement leurs fiches liées à leur stock
            if ($user->role === 'admin') {
                $fiches = FicheLivraison::with(['stockpv', 'livreur', 'distilleur.siteCollecte'])
                    ->orderBy('created_at', 'desc')
                    ->get();
            } else {
                $fiches = FicheLivraison::whereHas('stockpv', function($query) use ($user) {
                        $query->where('utilisateur_id', $user->id);
                    })
                    ->with(['stockpv', 'livreur', 'distilleur.siteCollecte'])
                    ->orderBy('created_at', 'desc')
                    ->get();
            }
            
            return response()->json([
                'success' => true,
                'data' => $fiches,
                'user_id' => $user->id,
                'role' => $user->role,
                'count' => $fiches->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des fiches de livraison',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne'
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        DB::beginTransaction();
        
        try {
            $user = Auth::user();
            
            $validator = Validator::make($request->all(), [
                'stockpvs_id' => 'required|exists:stockpvs,id',
                'livreur_id' => 'required|exists:livreurs,id',
                'distilleur_id' => 'required|exists:utilisateurs,id',
                'date_livraison' => 'required|date',
                'lieu_depart' => 'required|string',
                'ristourne_regionale' => 'nullable|numeric|min:0',
                'ristourne_communale' => 'nullable|numeric|min:0',
                'quantite_a_livrer' => 'required|numeric|min:0.01',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors(),
                    'user_id' => $user->id
                ], 422);
            }

            // Récupérer le distilleur avec son site de collecte
            $distilleur = Utilisateur::with('siteCollecte')
                ->where('id', $request->distilleur_id)
                ->where('role', 'distilleur')
                ->first();

            if (!$distilleur) {
                return response()->json([
                    'success' => false,
                    'message' => 'Distilleur non trouvé ou rôle incorrect',
                    'user_id' => $user->id
                ], 404);
            }

            // Vérifier que le distilleur a un site de collecte
            if (!$distilleur->site_collecte_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le distilleur n\'a pas de site de collecte attribué',
                    'user_id' => $user->id
                ], 400);
            }

            $stockpv = Stockpv::find($request->stockpvs_id);
            
            if (!$stockpv) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stock non trouvé',
                    'user_id' => $user->id
                ], 404);
            }

            // VÉRIFICATION 1: Vérifier que le stock appartient à l'utilisateur (sauf admin)
            if ($user->role !== 'admin' && $stockpv->utilisateur_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé à ce stock. Ce stock appartient à un autre utilisateur',
                    'user_id' => $user->id,
                    'stock_user_id' => $stockpv->utilisateur_id,
                    'current_user_id' => $user->id
                ], 403);
            }

            // VÉRIFICATION 2: Vérifier si le stock est vide
            if ($stockpv->stock_disponible <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stock vide. Impossible de créer une fiche de livraison',
                    'stock_disponible' => $stockpv->stock_disponible,
                    'user_id' => $user->id,
                    'stock_id' => $stockpv->id
                ], 400);
            }

            // VÉRIFICATION 3: Vérifier le stock disponible
            if ($stockpv->stock_disponible < $request->quantite_a_livrer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stock insuffisant. Disponible: ' . number_format($stockpv->stock_disponible, 2) . ' kg - Demandé: ' . number_format($request->quantite_a_livrer, 2) . ' kg',
                    'stock_disponible' => $stockpv->stock_disponible,
                    'quantite_demandee' => $request->quantite_a_livrer,
                    'user_id' => $user->id,
                    'difference' => $request->quantite_a_livrer - $stockpv->stock_disponible
                ], 400);
            }

            // Soustraire du stock disponible DE L'UTILISATEUR
            $stockAvant = $stockpv->stock_disponible;
            $stockpv->decrement('stock_disponible', $request->quantite_a_livrer);
            $stockApres = $stockpv->stock_disponible;
            
            // Mettre à jour le stock global aussi si nécessaire
            $this->mettreAJourStockGlobal($stockpv, $request->quantite_a_livrer);

            // Créer la fiche de livraison
            $fiche = FicheLivraison::create([
                'stockpvs_id' => $request->stockpvs_id,
                'livreur_id' => $request->livreur_id,
                'distilleur_id' => $distilleur->id,
                'date_livraison' => $request->date_livraison,
                'lieu_depart' => $request->lieu_depart,
                'ristourne_regionale' => $request->ristourne_regionale ?? 0,
                'ristourne_communale' => $request->ristourne_communale ?? 0,
                'quantite_a_livrer' => $request->quantite_a_livrer,
                'created_by' => $user->id,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Fiche de livraison créée avec succès',
                'data' => $fiche->load(['stockpv', 'livreur', 'distilleur.siteCollecte']),
                'stock_info' => [
                    'stock_id' => $stockpv->id,
                    'stock_avant' => $stockAvant,
                    'stock_apres' => $stockApres,
                    'quantite_soustraction' => $request->quantite_a_livrer,
                    'utilisateur_id' => $stockpv->utilisateur_id,
                    'type_matiere' => $stockpv->type_matiere,
                    'niveau_stock' => $stockpv->niveau_stock
                ],
                'destinataire' => [
                    'distilleur_id' => $distilleur->id,
                    'nom_complet' => $distilleur->nom . ' ' . $distilleur->prenom,
                    'site_collecte' => $distilleur->siteCollecte->Nom ?? 'Non défini',
                    'site_collecte_id' => $distilleur->site_collecte_id
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
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne',
                'user_id' => isset($user) ? $user->id : null
            ], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $fiche = FicheLivraison::with(['stockpv', 'livreur', 'distilleur.siteCollecte'])
                ->find($id);
            
            if (!$fiche) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fiche de livraison non trouvée',
                    'user_id' => $user->id
                ], 404);
            }

            // Vérifier que l'utilisateur a accès à cette fiche (sauf admin)
            if ($user->role !== 'admin') {
                if (!$fiche->stockpv || $fiche->stockpv->utilisateur_id != $user->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Accès non autorisé à cette fiche de livraison',
                        'user_id' => $user->id,
                        'fiche_user_id' => $fiche->stockpv ? $fiche->stockpv->utilisateur_id : null
                    ], 403);
                }
            }

            return response()->json([
                'success' => true,
                'data' => $fiche,
                'user_id' => $user->id,
                'role' => $user->role
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la fiche de livraison',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne',
                'user_id' => isset($user) ? $user->id : null
            ], 500);
        }
    }

    // Méthode pour récupérer les fiches par site de collecte
    public function getBySiteCollecte($siteCollecteNom): JsonResponse
    {
        try {
            $user = Auth::user();
            
            // Pour admin: voir toutes les fiches de ce site
            // Pour autres: voir seulement leurs fiches de ce site
            if ($user->role === 'admin') {
                $fiches = FicheLivraison::whereHas('distilleur.siteCollecte', function($query) use ($siteCollecteNom) {
                        $query->where('Nom', $siteCollecteNom);
                    })
                    ->with(['stockpv', 'livreur', 'distilleur.siteCollecte'])
                    ->orderBy('created_at', 'desc')
                    ->get();
            } else {
                $fiches = FicheLivraison::whereHas('distilleur.siteCollecte', function($query) use ($siteCollecteNom) {
                        $query->where('Nom', $siteCollecteNom);
                    })
                    ->whereHas('stockpv', function($query) use ($user) {
                        $query->where('utilisateur_id', $user->id);
                    })
                    ->with(['stockpv', 'livreur', 'distilleur.siteCollecte'])
                    ->orderBy('created_at', 'desc')
                    ->get();
            }
            
            return response()->json([
                'success' => true,
                'data' => $fiches,
                'site_collecte' => $siteCollecteNom,
                'user_id' => $user->id,
                'role' => $user->role,
                'count' => $fiches->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des fiches par site',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne',
                'user_id' => isset($user) ? $user->id : null
            ], 500);
        }
    }

    // Méthode pour récupérer les distillateurs disponibles
    public function getDistillateurs(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $distillateurs = Utilisateur::where('role', 'distilleur')
                ->with('siteCollecte')
                ->get()
                ->map(function ($distilleur) {
                    return [
                        'id' => $distilleur->id,
                        'nom_complet' => $distilleur->nom . ' ' . $distilleur->prenom,
                        'site_collecte' => $distilleur->siteCollecte->Nom ?? 'Non attribué',
                        'site_collecte_id' => $distilleur->site_collecte_id,
                        'numero' => $distilleur->numero,
                        'localisation_id' => $distilleur->localisation_id
                    ];
                });
            
            return response()->json([
                'success' => true,
                'data' => $distillateurs,
                'user_id' => $user->id,
                'count' => $distillateurs->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des distillateurs',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne',
                'user_id' => isset($user) ? $user->id : null
            ], 500);
        }
    }

    // Nouvelle méthode pour obtenir les stocks disponibles par utilisateur
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

            // Pour admin: voir tous les stocks des utilisateurs
            // Pour autres: voir seulement leur stock
            if ($user->role === 'admin') {
                $stocks = Stockpv::where('stock_disponible', '>', 0)
                    ->where('niveau_stock', 'utilisateur')
                    ->with(['utilisateur'])
                    ->get()
                    ->groupBy('type_matiere');
            } else {
                $stocks = Stockpv::where('utilisateur_id', $user->id)
                    ->where('stock_disponible', '>', 0)
                    ->where('niveau_stock', 'utilisateur')
                    ->get()
                    ->groupBy('type_matiere');
            }

            $result = [];
            foreach (['FG', 'CG', 'GG'] as $type) {
                $stocksType = $stocks->get($type, collect());
                
                $result[$type] = [
                    'stocks_disponibles' => $stocksType->map(function($stock) use ($user) {
                        return [
                            'id' => $stock->id,
                            'type_matiere' => $stock->type_matiere,
                            'stock_disponible' => (float) $stock->stock_disponible,
                            'stock_total' => (float) $stock->stock_total,
                            'utilisateur_id' => $stock->utilisateur_id,
                            'utilisateur_nom' => $user->role === 'admin' && $stock->utilisateur ? 
                                $stock->utilisateur->nom . ' ' . $stock->utilisateur->prenom : 
                                'Votre stock',
                            'niveau_stock' => $stock->niveau_stock,
                            'peut_livrer' => $stock->stock_disponible > 0
                        ];
                    }),
                    'total_disponible' => $stocksType->sum('stock_disponible'),
                    'nombre_stocks' => $stocksType->count(),
                    'peut_creer_fiche' => $stocksType->sum('stock_disponible') > 0
                ];
            }

            // Vérifier si l'utilisateur a du stock
            $aDuStock = collect($result)->some(function($type) {
                return $type['total_disponible'] > 0;
            });

            return response()->json([
                'success' => true,
                'data' => $result,
                'user' => [
                    'id' => $user->id,
                    'role' => $user->role,
                    'a_du_stock' => $aDuStock,
                    'message' => $aDuStock ? 
                        'Vous avez du stock disponible pour créer des fiches de livraison' : 
                        'Vous n\'avez pas de stock disponible'
                ],
                'summary' => [
                    'total_types_avec_stock' => collect($result)->filter(fn($type) => $type['total_disponible'] > 0)->count(),
                    'stock_total_disponible' => collect($result)->sum('total_disponible')
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

    // Méthode privée pour mettre à jour le stock global
    private function mettreAJourStockGlobal(Stockpv $stockPersonnel, $quantite): void
    {
        try {
            // Si c'est un stock personnel, mettre à jour aussi le stock global
            if ($stockPersonnel->utilisateur_id && $stockPersonnel->niveau_stock === 'utilisateur') {
                // Trouver le stock global correspondant
                $stockGlobal = Stockpv::where('type_matiere', $stockPersonnel->type_matiere)
                    ->whereNull('utilisateur_id')
                    ->where('niveau_stock', 'global')
                    ->first();
                    
                if ($stockGlobal) {
                    $avantGlobal = $stockGlobal->stock_disponible;
                    $stockGlobal->decrement('stock_disponible', $quantite);
                    $apresGlobal = $stockGlobal->stock_disponible;
                    
                    \Log::info("Stock global mis à jour: {$stockPersonnel->type_matiere} -{$quantite}kg (Avant: {$avantGlobal}kg, Après: {$apresGlobal}kg)");
                }
            }
        } catch (\Exception $e) {
            \Log::error("Erreur mise à jour stock global: " . $e->getMessage());
        }
    }
}