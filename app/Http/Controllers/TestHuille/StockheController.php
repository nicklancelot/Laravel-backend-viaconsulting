<?php

namespace App\Http\Controllers\TestHuille;

use App\Http\Controllers\Controller;
use App\Models\TestHuille\Stockhe;
use App\Models\Utilisateur;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class StockheController extends Controller
{
    /**
     * Obtenir l'état du stock pour l'utilisateur connecté
     */
    public function getEtatStock(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }
            
            $stockDetaille = Stockhe::getStockDetaille($user->id);
            
            return response()->json([
                'success' => true,
                'data' => $stockDetaille,
                'utilisateur' => [
                    'id' => $user->id,
                    'nom_complet' => $user->nom . ' ' . $user->prenom,
                    'role' => $user->role
                ]
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
     * Obtenir le stock pour TOUS les utilisateurs (admin seulement)
     */
    public function getStockTousUtilisateurs(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }
            
            // Seul un admin peut voir le stock de tous les utilisateurs
            if ($user->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé. Admin seulement.'
                ], 403);
            }
            
            // Récupérer tous les utilisateurs ayant un stock
            $stocksUtilisateurs = Stockhe::where('niveau_stock', 'utilisateur')
                ->whereNotNull('utilisateur_id')
                ->with('utilisateur')
                ->get();
            
            // Formater les données
            $utilisateursAvecStock = $stocksUtilisateurs->map(function ($stock) {
                return [
                    'utilisateur_id' => $stock->utilisateur_id,
                    'nom_complet' => $stock->utilisateur ? 
                        $stock->utilisateur->nom . ' ' . $stock->utilisateur->prenom : 'Utilisateur inconnu',
                    'role' => $stock->utilisateur->role ?? 'Inconnu',
                    'stock_total' => $stock->stock_total,
                    'stock_disponible' => $stock->stock_disponible,
                    'stock_utilise' => $stock->stock_total - $stock->stock_disponible,
                    'pourcentage_utilisation' => $stock->stock_total > 0 ? 
                        round((($stock->stock_total - $stock->stock_disponible) / $stock->stock_total) * 100, 2) : 0,
                    'created_at' => $stock->created_at,
                    'updated_at' => $stock->updated_at
                ];
            })->sortBy('nom_complet')->values();
            
            // Récupérer le stock global
            $stockGlobal = Stockhe::whereNull('utilisateur_id')
                ->where('niveau_stock', 'global')
                ->first();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'stock_global' => $stockGlobal ? [
                        'id' => $stockGlobal->id,
                        'stock_total' => $stockGlobal->stock_total,
                        'stock_disponible' => $stockGlobal->stock_disponible,
                        'stock_utilise' => $stockGlobal->stock_total - $stockGlobal->stock_disponible,
                        'type' => 'global'
                    ] : null,
                    'utilisateurs' => $utilisateursAvecStock,
                    'statistiques' => [
                        'total_utilisateurs' => $utilisateursAvecStock->count(),
                        'stock_total_utilisateurs' => $utilisateursAvecStock->sum('stock_total'),
                        'stock_disponible_utilisateurs' => $utilisateursAvecStock->sum('stock_disponible'),
                        'stock_global_total' => $stockGlobal ? $stockGlobal->stock_total : 0,
                        'stock_global_disponible' => $stockGlobal ? $stockGlobal->stock_disponible : 0,
                        'stock_total_systeme' => ($stockGlobal ? $stockGlobal->stock_total : 0) + $utilisateursAvecStock->sum('stock_total')
                    ]
                ],
                'requested_by' => [
                    'id' => $user->id,
                    'nom' => $user->nom,
                    'role' => $user->role
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du stock de tous les utilisateurs',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Obtenir le stock d'un utilisateur spécifique (admin seulement)
     */
    public function getStockUtilisateur($userId): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }
            
            // Vérifier les permissions
            if ($user->role !== 'admin' && $user->id != $userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé. Vous ne pouvez voir que votre propre stock.'
                ], 403);
            }
            
            // Vérifier si l'utilisateur existe
            $utilisateur = Utilisateur::find($userId);
            
            if (!$utilisateur) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non trouvé'
                ], 404);
            }
            
            // Obtenir le stock détaillé
            $stockDetaille = Stockhe::getStockDetaille($userId);
            
            // Si l'utilisateur n'a pas de stock personnel, créer un enregistrement vide
            if (!$stockDetaille['utilisateur']) {
                $stockDetaille['utilisateur'] = [
                    'id' => null,
                    'stock_total' => 0,
                    'stock_disponible' => 0,
                    'utilisateur_id' => $userId,
                    'niveau_stock' => 'utilisateur'
                ];
            }
            
            return response()->json([
                'success' => true,
                'data' => $stockDetaille,
                'utilisateur' => [
                    'id' => $utilisateur->id,
                    'nom_complet' => $utilisateur->nom . ' ' . $utilisateur->prenom,
                    'role' => $utilisateur->role,
                    'email' => $utilisateur->email,
                    'site_collecte' => $utilisateur->siteCollecte->Nom ?? 'Non attribué'
                ],
                'requested_by' => [
                    'id' => $user->id,
                    'nom' => $user->nom,
                    'role' => $user->role
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du stock utilisateur',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Obtenir la liste de tous les utilisateurs (avec ou sans stock)
     */
    public function getListeUtilisateurs(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }
            
            // Seul un admin peut voir tous les utilisateurs
            if ($user->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé. Admin seulement.'
                ], 403);
            }
            
            // Récupérer tous les utilisateurs
            $utilisateurs = Utilisateur::orderBy('nom')
                ->orderBy('prenom')
                ->get();
            
            // Pour chaque utilisateur, récupérer son stock
            $utilisateursAvecStock = $utilisateurs->map(function ($utilisateur) {
                $stock = Stockhe::where('utilisateur_id', $utilisateur->id)
                    ->where('niveau_stock', 'utilisateur')
                    ->first();
                
                return [
                    'id' => $utilisateur->id,
                    'nom_complet' => $utilisateur->nom . ' ' . $utilisateur->prenom,
                    'role' => $utilisateur->role,
                    'email' => $utilisateur->email,
                    'contact' => $utilisateur->numero,
                    'site_collecte' => $utilisateur->siteCollecte->Nom ?? 'Non attribué',
                    'stock' => $stock ? [
                        'stock_total' => $stock->stock_total,
                        'stock_disponible' => $stock->stock_disponible,
                        'stock_utilise' => $stock->stock_total - $stock->stock_disponible,
                        'a_du_stock' => true
                    ] : [
                        'stock_total' => 0,
                        'stock_disponible' => 0,
                        'stock_utilise' => 0,
                        'a_du_stock' => false
                    ],
                    'date_inscription' => $utilisateur->created_at
                ];
            });
            
            return response()->json([
                'success' => true,
                'data' => [
                    'utilisateurs' => $utilisateursAvecStock,
                    'total_utilisateurs' => $utilisateursAvecStock->count(),
                    'utilisateurs_avec_stock' => $utilisateursAvecStock->where('stock.a_du_stock', true)->count(),
                    'utilisateurs_sans_stock' => $utilisateursAvecStock->where('stock.a_du_stock', false)->count()
                ],
                'requested_by' => [
                    'id' => $user->id,
                    'nom' => $user->nom,
                    'role' => $user->role
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la liste des utilisateurs',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Vérifier la disponibilité pour l'utilisateur connecté
     */
    public function verifierDisponibilite(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }
            
            $request->validate([
                'quantite' => 'required|numeric|min:0'
            ]);
            
            $disponible = Stockhe::verifierDisponibilite(
                $request->quantite, 
                $user->id
            );
            
            $stockDetaille = Stockhe::getStockDetaille($user->id);
            
            return response()->json([
                'success' => true,
                'disponible' => $disponible,
                'quantite_demandee' => $request->quantite,
                'stock_utilisateur' => $stockDetaille['utilisateur']['stock_disponible'] ?? 0,
                'stock_global' => $stockDetaille['global']['stock_disponible'] ?? 0,
                'total_disponible' => $stockDetaille['total_disponible'],
                'utilisateur' => [
                    'id' => $user->id,
                    'nom_complet' => $user->nom . ' ' . $user->prenom
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la vérification',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Version simple pour compatibilité (stock global seulement)
     */
    public function getEtatStockSimple(): JsonResponse
    {
        try {
            $stockGlobal = Stockhe::whereNull('utilisateur_id')
                ->where('niveau_stock', 'global')
                ->first();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'stock_total' => $stockGlobal ? $stockGlobal->stock_total : 0,
                    'stock_disponible' => $stockGlobal ? $stockGlobal->stock_disponible : 0,
                    'stock_utilise' => $stockGlobal ? $stockGlobal->stock_total - $stockGlobal->stock_disponible : 0,
                    'note' => 'Stock global (commun à tous les utilisateurs)'
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du stock global',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}