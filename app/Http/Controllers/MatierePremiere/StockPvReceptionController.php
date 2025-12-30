<?php

namespace App\Http\Controllers\MatierePremiere;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class StockPvReceptionController extends Controller
{
    public function getEtatStock(): JsonResponse
    {
        $user = Auth::user();
        
        // Récupérer TOUS les stocks 
        $stocks = DB::table('stockpvs')->get();
        $stocksGlobaux = $stocks->whereNull('utilisateur_id')->where('niveau_stock', 'global');
            
        $stocksUtilisateur = $user ? 
            $stocks->where('utilisateur_id', $user->id)
                ->where('niveau_stock', 'utilisateur') : 
            collect();
        
        $resultGlobal = [];
        $resultUtilisateur = [];
        
        foreach (['FG', 'CG', 'GG'] as $type) {

            $stockGlobal = $stocksGlobaux->firstWhere('type_matiere', $type);
            $resultGlobal[$type] = [
                'total_entree' => $stockGlobal ? (float) $stockGlobal->stock_total : 0,
                'total_disponible' => $stockGlobal ? (float) $stockGlobal->stock_disponible : 0,
                'total_utilise' => $stockGlobal ? 
                    (float) $stockGlobal->stock_total - (float) $stockGlobal->stock_disponible : 0,
                'nombre_lots' => $stockGlobal ? 1 : 0,
                'niveau' => 'global'
            ];
            
            // Stock utilisateur (un seul par type par utilisateur)
            $stockUser = $stocksUtilisateur->firstWhere('type_matiere', $type);
            $resultUtilisateur[$type] = [
                'total_entree' => $stockUser ? (float) $stockUser->stock_total : 0,
                'total_disponible' => $stockUser ? (float) $stockUser->stock_disponible : 0,
                'total_utilise' => $stockUser ? 
                    (float) $stockUser->stock_total - (float) $stockUser->stock_disponible : 0,
                'nombre_lots' => $stockUser ? 1 : 0,
                'niveau' => 'utilisateur'
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'stocks_globaux' => $resultGlobal,
                'stocks_utilisateur' => $resultUtilisateur,
                'explications' => [
                    'stock_global' => 'Stock commun à tous les utilisateurs',
                    'stock_utilisateur' => 'Stock personnel de l\'utilisateur connecté',
                    'note' => 'Les totaux NE doivent PAS être additionnés car ils représentent des stocks différents'
                ]
            ],
            'user_id' => $user ? $user->id : null,
            'user_role' => $user ? $user->role : null
        ]);
    }
    
    // Version 2 : Avec interprétation CORRECTE des totaux
    public function getEtatStockV2(): JsonResponse
    {
        $user = Auth::user();
        
        // Récupérer TOUS les stocks
        $stocks = DB::table('stockpvs')->get();
        
        // Séparer les stocks
        $stocksGlobaux = $stocks->whereNull('utilisateur_id')
            ->where('niveau_stock', 'global');
            
        $stocksUtilisateur = $user ? 
            $stocks->where('utilisateur_id', $user->id)
                ->where('niveau_stock', 'utilisateur') : 
            collect();
        
        // Calculer les VRAIS totaux
        // Stock total réel = stock global + stock de tous les utilisateurs
        $totalReelParType = [
            'FG' => ['global' => 0, 'utilisateurs' => 0, 'total' => 0],
            'CG' => ['global' => 0, 'utilisateurs' => 0, 'total' => 0],
            'GG' => ['global' => 0, 'utilisateurs' => 0, 'total' => 0]
        ];
        
        foreach (['FG', 'CG', 'GG'] as $type) {
            // Stock global
            $stockGlobal = $stocksGlobaux->firstWhere('type_matiere', $type);
            $totalGlobal = $stockGlobal ? (float) $stockGlobal->stock_disponible : 0;
            
            // Stock de l'utilisateur connecté
            $stockUser = $stocksUtilisateur->firstWhere('type_matiere', $type);
            $totalUser = $stockUser ? (float) $stockUser->stock_disponible : 0;
            
            // Stock de TOUS les utilisateurs (pour le total réel)
            $totalTousUtilisateurs = $stocks->where('type_matiere', $type)
                ->whereNotNull('utilisateur_id')
                ->where('niveau_stock', 'utilisateur')
                ->sum('stock_disponible');
            
            $totalReelParType[$type] = [
                'global' => $totalGlobal,
                'utilisateur_connecte' => $totalUser,
                'tous_utilisateurs' => $totalTousUtilisateurs,
                'total_reel_systeme' => $totalGlobal + $totalTousUtilisateurs,
                'total_acces_utilisateur' => $totalGlobal + $totalUser
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'totaux_par_type' => $totalReelParType,
                'stock_global' => 'Stock commun accessible par tous',
                'stock_utilisateur' => 'Stock personnel (privé)',
                'interpretation_correcte' => [
                    'total_acces_utilisateur' => 'Quantité que cet utilisateur peut utiliser (global + son stock)',
                    'total_reel_systeme' => 'Quantité totale dans tout le système',
                    'attention' => 'NE PAS additionner global + utilisateur_connecte pour avoir le total système'
                ]
            ],
            'user_id' => $user ? $user->id : null
        ]);
    }
    
    // Version 3 : Plus simple et claire
    public function getEtatStockSimple(): JsonResponse
    {
        $user = Auth::user();
        
        // 1. Stock global (accessible par tous)
        $stockGlobal = DB::table('stockpvs')
            ->whereNull('utilisateur_id')
            ->where('niveau_stock', 'global')
            ->get()
            ->keyBy('type_matiere');
        
        // 2. Stock de l'utilisateur connecté (son stock personnel)
        $stockUtilisateur = $user ? 
            DB::table('stockpvs')
                ->where('utilisateur_id', $user->id)
                ->where('niveau_stock', 'utilisateur')
                ->get()
                ->keyBy('type_matiere') : 
            collect();
        
        // 3. Calculer ce que l'utilisateur peut utiliser
        $resultat = [];
        
        foreach (['FG', 'CG', 'GG'] as $type) {
            $global = $stockGlobal->get($type);
            $perso = $stockUtilisateur->get($type);
            
            $quantiteGlobal = $global ? (float) $global->stock_disponible : 0;
            $quantitePerso = $perso ? (float) $perso->stock_disponible : 0;
            
            $resultat[$type] = [
                'stock_global_disponible' => $quantiteGlobal,
                'stock_personnel' => $quantitePerso,
                'total_disponible_pour_utilisateur' => $quantiteGlobal + $quantitePerso,
                'detail' => [
                    'global_id' => $global ? $global->id : null,
                    'personnel_id' => $perso ? $perso->id : null
                ]
            ];
        }
        
        // 4. Stock total dans le système (admin seulement)
        $stockTotalSysteme = null;
        if ($user && $user->role === 'admin') {
            $stockTotalSysteme = [];
            foreach (['FG', 'CG', 'GG'] as $type) {
                $totalGlobal = $stockGlobal->get($type) ? (float) $stockGlobal->get($type)->stock_disponible : 0;
                
                $totalUtilisateurs = DB::table('stockpvs')
                    ->where('type_matiere', $type)
                    ->whereNotNull('utilisateur_id')
                    ->where('niveau_stock', 'utilisateur')
                    ->sum('stock_disponible');
                
                $stockTotalSysteme[$type] = [
                    'global' => $totalGlobal,
                    'tous_utilisateurs' => $totalUtilisateurs,
                    'total_systeme' => $totalGlobal + $totalUtilisateurs
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'stock_disponible' => $resultat,
                'stock_total_systeme' => $stockTotalSysteme,
                'explication' => [
                    'stock_global_disponible' => 'Stock que vous pouvez utiliser (commun à tous)',
                    'stock_personnel' => 'Votre stock personnel (que vous seul pouvez utiliser)',
                    'total_disponible_pour_utilisateur' => 'Quantité totale que vous pouvez utiliser maintenant'
                ]
            ],
            'user_id' => $user ? $user->id : null,
            'user_role' => $user ? $user->role : null
        ]);
    }
    
    // Pour la livraison : obtenir les stocks disponibles pour un utilisateur
    public function getStocksDisponiblesPourLivraison(): JsonResponse
    {
        $user = Auth::user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non connecté'
            ], 401);
        }
        
        // Stocks que cet utilisateur peut utiliser :
        // 1. Stock global
        // 2. Son stock personnel
        
        $stocksDisponibles = [];
        
        foreach (['FG', 'CG', 'GG'] as $type) {
            // Stock global
            $stockGlobal = DB::table('stockpvs')
                ->select('id', 'stock_disponible', DB::raw("'global' as source"))
                ->where('type_matiere', $type)
                ->whereNull('utilisateur_id')
                ->where('niveau_stock', 'global')
                ->where('stock_disponible', '>', 0)
                ->first();
            
            // Stock personnel
            $stockPersonnel = DB::table('stockpvs')
                ->select('id', 'stock_disponible', DB::raw("'personnel' as source"))
                ->where('type_matiere', $type)
                ->where('utilisateur_id', $user->id)
                ->where('niveau_stock', 'utilisateur')
                ->where('stock_disponible', '>', 0)
                ->first();
            
            $options = [];
            
            if ($stockGlobal) {
                $options[] = [
                    'id' => $stockGlobal->id,
                    'source' => 'global',
                    'quantite_disponible' => (float) $stockGlobal->stock_disponible,
                    'description' => 'Stock global - accessible par tous'
                ];
            }
            
            if ($stockPersonnel) {
                $options[] = [
                    'id' => $stockPersonnel->id,
                    'source' => 'personnel',
                    'quantite_disponible' => (float) $stockPersonnel->stock_disponible,
                    'description' => 'Stock personnel'
                ];
            }
            
            $stocksDisponibles[$type] = [
                'options' => $options,
                'total_disponible' => ($stockGlobal ? (float) $stockGlobal->stock_disponible : 0) + 
                                    ($stockPersonnel ? (float) $stockPersonnel->stock_disponible : 0)
            ];
        }
        
        return response()->json([
            'success' => true,
            'data' => $stocksDisponibles,
            'user_id' => $user->id,
            'recommandation' => 'Pour les livraisons, prioriser d\'abord le stock personnel, puis le stock global'
        ]);
    }
}