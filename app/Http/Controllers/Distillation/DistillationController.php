<?php

namespace App\Http\Controllers\Distillation;

use App\Http\Controllers\Controller;
use App\Models\Distillation\Distillation;
use App\Models\Distillation\StockADistiller;
use App\Models\Distillation\Stock;
use App\Models\SoldeUser;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class DistillationController extends Controller
{
    /**
     * Récupérer la liste des stocks disponibles et des distillations
     * GET /api/distillations
     */
    public function index(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!in_array($user->role, ['distilleur', 'admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès réservé aux distillateurs et administrateurs'
                ], 403);
            }
            
            // Construire la requête selon le rôle
            $queryStocks = StockADistiller::query();
            $queryDistillations = Distillation::query();
            
            if ($user->role === 'distilleur') {
                $queryStocks->where('distilleur_id', $user->id);
                $queryDistillations->where('created_by', $user->id);
            }
            
            // 1. Récupérer tous les stocks (agrégés par type)
            $stocks = $queryStocks
                ->orderBy('type_matiere')
                ->get()
                ->map(function ($stock) {
                    return [
                        'id' => $stock->id,
                        'type_matiere' => $stock->type_matiere,
                        'quantite_initiale' => $stock->quantite_initiale,
                        'quantite_utilisee' => $stock->quantite_utilisee,
                        'quantite_restante' => $stock->quantite_restante,
                        'taux_humidite_moyen' => $stock->taux_humidite_moyen,
                        'taux_dessiccation_moyen' => $stock->taux_dessiccation_moyen,
                        'statut' => $stock->statut,
                        'numero_pv_reference' => $stock->numero_pv_reference,
                        'peut_distiller' => $stock->estDisponible(),
                        'est_disponible' => $stock->estDisponible(),
                        'est_en_distillation' => $stock->estEnDistillation(),
                        'est_epuise' => $stock->estEpuise(),
                        'distilleur' => [
                            'id' => $stock->distilleur_id,
                            'nom_complet' => $stock->distilleur->nom . ' ' . $stock->distilleur->prenom
                        ],
                        'created_at' => $stock->created_at,
                        'updated_at' => $stock->updated_at
                    ];
                });
            
            // 2. Récupérer toutes les distillations
            $distillations = $queryDistillations
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($distillation) {
                    return $this->formatDistillation($distillation);
                });
            
            // 3. Statistiques
            $stats = [
                'stocks' => [
                    'total' => $stocks->count(),
                    'disponible' => $stocks->where('est_disponible', true)->count(),
                    'en_distillation' => $stocks->where('est_en_distillation', true)->count(),
                    'epuise' => $stocks->where('est_epuise', true)->count(),
                    'quantite_totale_restante' => $stocks->sum('quantite_restante')
                ],
                'distillations' => [
                    'total' => $distillations->count(),
                    'en_attente' => $distillations->where('statut', 'en_attente')->count(),
                    'en_cours' => $distillations->where('statut', 'en_cours')->count(),
                    'terminees' => $distillations->where('statut', 'termine')->count(),
                    'quantite_resultat_totale' => $distillations->where('statut', 'termine')->sum('quantite_resultat')
                ]
            ];
            
            // 4. Grouper les stocks par type de matière
            $stocksParType = [];
            $stocks->each(function ($stock) use (&$stocksParType) {
                $type = $stock['type_matiere'];
                if (!isset($stocksParType[$type])) {
                    $stocksParType[$type] = [
                        'type_matiere' => $type,
                        'total_stocks' => 0,
                        'quantite_initiale' => 0,
                        'quantite_utilisee' => 0,
                        'quantite_restante' => 0,
                        'stocks' => []
                    ];
                }
                $stocksParType[$type]['total_stocks']++;
                $stocksParType[$type]['quantite_initiale'] += $stock['quantite_initiale'];
                $stocksParType[$type]['quantite_utilisee'] += $stock['quantite_utilisee'];
                $stocksParType[$type]['quantite_restante'] += $stock['quantite_restante'];
                $stocksParType[$type]['stocks'][] = $stock;
            });
            
            return response()->json([
                'success' => true,
                'message' => 'Liste des stocks disponibles et distillations',
                'data' => [
                    'stocks' => $stocks,
                    'stocks_par_type' => array_values($stocksParType),
                    'distillations' => $distillations
                ],
                'stats' => $stats,
                'user_info' => [
                    'id' => $user->id,
                    'nom_complet' => $user->nom . ' ' . $user->prenom,
                    'role' => $user->role,
                    'site_collecte' => $user->siteCollecte->Nom ?? 'Non défini'
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erreur récupération distillations: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des données',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }
    
   public function demarrerDistillation(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if ($user->role !== 'distilleur') {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès réservé aux distillateurs'
                ], 403);
            }
            
            // Validation
            $validator = Validator::make($request->all(), [
                'stock_id' => 'required|exists:stock_a_distillers,id',
                'quantite_a_distiller' => 'required|numeric|min:0.1',
                'id_ambalic' => 'required|string|max:50',
                'date_debut' => 'required|date',
                'usine' => 'required|string|max:100',
                'duree_distillation' => 'required|integer|min:1',
                // Bois de chauffage
                'quantite_bois_chauffage' => 'required|numeric|min:0',
                'prix_bois_chauffage' => 'required|numeric|min:0',
                // Carburant
                'quantite_carburant' => 'required|numeric|min:0',
                'prix_carburant' => 'required|numeric|min:0',
                // Main d'œuvre
                'nombre_ouvriers' => 'required|integer|min:1',
                'heures_travail_par_ouvrier' => 'required|numeric|min:1',
                'prix_heure_main_oeuvre' => 'required|numeric|min:0',
                'observations' => 'nullable|string|max:500'
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            // 1. Vérifier que le stock appartient au distilleur
            $stock = StockADistiller::where('id', $request->stock_id)
                ->where('distilleur_id', $user->id)
                ->first();
            
            if (!$stock) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stock non trouvé ou non autorisé'
                ], 404);
            }
            
            // 2. Vérifier que le stock a de la quantité disponible (CORRIGÉ)
            if ($stock->quantite_restante <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stock épuisé. Quantité restante: ' . $stock->quantite_restante,
                    'stock_info' => [
                        'id' => $stock->id,
                        'type_matiere' => $stock->type_matiere,
                        'quantite_restante' => $stock->quantite_restante,
                        'statut' => $stock->statut
                    ]
                ], 400);
            }
            
            // 3. Vérifier que la quantité demandée est disponible
            if ($request->quantite_a_distiller > $stock->quantite_restante) {
                return response()->json([
                    'success' => false,
                    'message' => 'Quantité insuffisante dans le stock',
                    'details' => [
                        'quantite_demandee' => $request->quantite_a_distiller,
                        'quantite_disponible' => $stock->quantite_restante,
                        'manque' => $request->quantite_a_distiller - $stock->quantite_restante
                    ],
                    'stock_info' => [
                        'id' => $stock->id,
                        'type_matiere' => $stock->type_matiere,
                        'quantite_initiale' => $stock->quantite_initiale,
                        'quantite_utilisee' => $stock->quantite_utilisee,
                        'quantite_restante' => $stock->quantite_restante
                    ]
                ], 400);
            }
            
            // 4. Vérifier le solde du distilleur
            $soldeUser = SoldeUser::where('utilisateur_id', $user->id)->first();
            
            if (!$soldeUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun compte solde trouvé pour cet utilisateur'
                ], 400);
            }
            
            // 5. Calculer le coût total de la distillation
            $coutBoisChauffage = $request->quantite_bois_chauffage * $request->prix_bois_chauffage;
            $coutCarburant = $request->quantite_carburant * $request->prix_carburant;
            $coutMainOeuvre = $request->nombre_ouvriers * $request->heures_travail_par_ouvrier * $request->prix_heure_main_oeuvre;
            $coutTotal = $coutBoisChauffage + $coutCarburant + $coutMainOeuvre;
            
            if ($soldeUser->solde < $coutTotal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solde insuffisant pour démarrer la distillation',
                    'details' => [
                        'solde_disponible' => $soldeUser->solde,
                        'solde_disponible_formate' => number_format($soldeUser->solde, 2) . ' MGA',
                        'cout_total_requis' => $coutTotal,
                        'cout_total_formate' => number_format($coutTotal, 2) . ' MGA',
                        'manque' => $coutTotal - $soldeUser->solde,
                        'manque_formate' => number_format($coutTotal - $soldeUser->solde, 2) . ' MGA'
                    ],
                    'cout_details' => [
                        'bois_chauffage' => [
                            'quantite' => $request->quantite_bois_chauffage,
                            'prix_unitaire' => $request->prix_bois_chauffage,
                            'total' => $coutBoisChauffage,
                            'total_formate' => number_format($coutBoisChauffage, 2) . ' MGA'
                        ],
                        'carburant' => [
                            'quantite' => $request->quantite_carburant,
                            'prix_unitaire' => $request->prix_carburant,
                            'total' => $coutCarburant,
                            'total_formate' => number_format($coutCarburant, 2) . ' MGA'
                        ],
                        'main_oeuvre' => [
                            'nombre' => $request->nombre_ouvriers,
                            'heures_par_ouvrier' => $request->heures_travail_par_ouvrier,
                            'prix_heure' => $request->prix_heure_main_oeuvre,
                            'total' => $coutMainOeuvre,
                            'total_formate' => number_format($coutMainOeuvre, 2) . ' MGA'
                        ]
                    ]
                ], 400);
            }
            
            DB::beginTransaction();
            
            try {
                // 6. Débiter le solde du distilleur
                $ancienSolde = $soldeUser->solde;
                $soldeUser->solde -= $coutTotal;
                
                if (!$soldeUser->save()) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Erreur lors du débit du solde'
                    ], 500);
                }
                
                // 7. Réserver la quantité dans le stock
                if (!$stock->reserverPourDistillation($request->quantite_a_distiller)) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Erreur lors de la réservation de la quantité dans le stock'
                    ], 500);
                }
                
                // 8. Créer la distillation
                $distillation = Distillation::create([
                    'type_matiere_premiere' => $stock->type_matiere,
                    'numero_pv' => $stock->numero_pv_reference ?? 'PV-DIST-' . now()->format('Ymd'),
                    'quantite_recue' => $stock->quantite_restante,
                    'taux_humidite' => $stock->taux_humidite_moyen,
                    'taux_dessiccation' => $stock->taux_dessiccation_moyen,
                    'poids_distiller' => $request->quantite_a_distiller,
                    'statut' => 'en_cours',
                    'id_ambalic' => $request->id_ambalic,
                    'date_debut' => $request->date_debut,
                    'usine' => $request->usine,
                    'duree_distillation' => $request->duree_distillation,
                    'quantite_bois_chauffage' => $request->quantite_bois_chauffage,
                    'prix_bois_chauffage' => $request->prix_bois_chauffage,
                    'quantite_carburant' => $request->quantite_carburant,
                    'prix_carburant' => $request->prix_carburant,
                    'nombre_ouvriers' => $request->nombre_ouvriers,
                    'heures_travail_par_ouvrier' => $request->heures_travail_par_ouvrier,
                    'prix_heure_main_oeuvre' => $request->prix_heure_main_oeuvre,
                    'prix_main_oeuvre' => $coutMainOeuvre,
                    'created_by' => $user->id,
                    'observations' => $request->observations ?? 'Distillation démarrée avec ' . $request->quantite_a_distiller . ' kg de ' . $stock->type_matiere
                ]);
                
                DB::commit();
                
                // 9. Formater la réponse
                $distillationFormatee = $this->formatDistillation($distillation);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Distillation démarrée avec succès',
                    'data' => $distillationFormatee,
                    'solde_info' => [
                        'ancien_solde' => $ancienSolde,
                        'ancien_solde_formate' => number_format($ancienSolde, 2) . ' MGA',
                        'nouveau_solde' => $soldeUser->solde,
                        'nouveau_solde_formate' => number_format($soldeUser->solde, 2) . ' MGA',
                        'montant_debite' => $coutTotal,
                        'montant_debite_formate' => number_format($coutTotal, 2) . ' MGA',
                        'cout_details' => [
                            'bois_chauffage' => [
                                'total' => $coutBoisChauffage,
                                'total_formate' => number_format($coutBoisChauffage, 2) . ' MGA'
                            ],
                            'carburant' => [
                                'total' => $coutCarburant,
                                'total_formate' => number_format($coutCarburant, 2) . ' MGA'
                            ],
                            'main_oeuvre' => [
                                'total' => $coutMainOeuvre,
                                'total_formate' => number_format($coutMainOeuvre, 2) . ' MGA'
                            ],
                            'total' => $coutTotal,
                            'total_formate' => number_format($coutTotal, 2) . ' MGA'
                        ]
                    ],
                    'stock_mis_a_jour' => [
                        'id' => $stock->id,
                        'type_matiere' => $stock->type_matiere,
                        'quantite_initiale' => $stock->quantite_initiale,
                        'quantite_utilisee' => $stock->quantite_utilisee,
                        'quantite_restante' => $stock->quantite_restante,
                        'statut' => $stock->statut
                    ],
                    'distilleur_info' => [
                        'id' => $user->id,
                        'nom_complet' => $user->nom . ' ' . $user->prenom,
                        'site_collecte' => $user->siteCollecte->Nom ?? 'Non défini'
                    ]
                ]);
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
            
        } catch (\Exception $e) {
            Log::error('Erreur démarrage distillation: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du démarrage de la distillation',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    
    /**
     * Terminer une distillation
     * POST /api/distillations/{id}/terminer
     */
    public function terminerDistillation(Request $request, $id): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if ($user->role !== 'distilleur') {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès réservé aux distillateurs'
                ], 403);
            }
            
            // Validation
            $validator = Validator::make($request->all(), [
                'reference' => 'required|string|max:50',
                'matiere' => 'required|string|max:100',
                'site' => 'required|string|max:100',
                'quantite_traitee' => 'required|numeric|min:0.1',
                'date_fin' => 'required|date|after_or_equal:date_debut',
                'type_he' => 'required|string|max:50',
                'quantite_resultat' => 'required|numeric|min:0.1',
                'observations' => 'nullable|string'
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            // 1. Vérifier que la distillation existe et appartient au distilleur
            $distillation = Distillation::where('id', $id)
                ->where('created_by', $user->id)
                ->first();
            
            if (!$distillation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Distillation non trouvée ou non autorisée'
                ], 404);
            }
            
            // 2. Vérifier que la distillation est en cours
            if ($distillation->statut !== 'en_cours') {
                return response()->json([
                    'success' => false,
                    'message' => 'La distillation n\'est pas en cours. Statut actuel: ' . $distillation->statut
                ], 400);
            }
  if ($request->quantite_traitee < $distillation->poids_distiller) {
        $quantiteNonUtilisee = $distillation->poids_distiller - $request->quantite_traitee;
        
        // Trouver le stock correspondant
        $stock = StockADistiller::where('distilleur_id', $user->id)
            ->where('type_matiere', $distillation->type_matiere_premiere)
            ->first();
        
        if ($stock) {
            $stock->libererQuantite($quantiteNonUtilisee);
        }
    }
            
            DB::beginTransaction();
            
            try {
                // 4. Mettre à jour la distillation
                $distillation->update([
                    'statut' => 'termine',
                    'reference' => $request->reference,
                    'matiere' => $request->matiere,
                    'site' => $request->site,
                    'quantite_traitee' => $request->quantite_traitee,
                    'date_fin' => $request->date_fin,
                    'type_he' => $request->type_he,
                    'quantite_resultat' => $request->quantite_resultat,
                    'observations' => $request->observations ?? 'Distillation terminée avec succès'
                ]);
                
                // 5. Créer le stock de produit fini
                $stockProduitFini = Stock::create([
                    'distillation_id' => $distillation->id,
                    'distilleur_id' => $user->id,
                    'type_produit' => $request->type_he,
                    'reference' => $request->reference,
                    'matiere' => $request->matiere,
                    'site_production' => $request->site,
                    'quantite_initiale' => $request->quantite_resultat,
                    'quantite_disponible' => $request->quantite_resultat,
                    'quantite_reservee' => 0,
                    'quantite_sortie' => 0,
                    'date_entree' => now()->format('Y-m-d'),
                    'date_production' => $request->date_fin,
                    'statut' => 'disponible',
                    'observations' => 'Produit créé depuis distillation #' . $distillation->id
                ]);
                
      // Changer quantite_utilisee en poids_distiller
if ($request->quantite_traitee < $distillation->poids_distiller) {
    $quantiteNonUtilisee = $distillation->poids_distiller - $request->quantite_traitee;
    
    // Trouver le stock correspondant
    $stock = StockADistiller::where('distilleur_id', $user->id)
        ->where('type_matiere', $distillation->type_matiere_premiere)
        ->first();
    
    if ($stock) {
        $stock->libererQuantite($quantiteNonUtilisee);
    }
}
                
                DB::commit();
                
                // 7. Formater la réponse
                $distillationFormatee = $this->formatDistillation($distillation);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Distillation terminée avec succès',
                    'data' => $distillationFormatee,
                    'stock_creé' => [
                        'id' => $stockProduitFini->id,
                        'type_produit' => $stockProduitFini->type_produit,
                        'reference' => $stockProduitFini->reference,
                        'quantite_initiale' => $stockProduitFini->quantite_initiale,
                        'quantite_disponible' => $stockProduitFini->quantite_disponible,
                        'statut' => $stockProduitFini->statut,
                        'date_entree' => $stockProduitFini->date_entree
                    ],
                    'rendement' => [
                        'quantite_traitee' => $distillation->quantite_traitee,
                        'quantite_resultat' => $distillation->quantite_resultat,
                        'rendement_pourcentage' => $distillation->rendement,
                        'rendement_formate' => $distillation->rendement_formate
                    ],
                    'duree' => [
                        'duree_estimee' => $distillation->duree_distillation . ' jour(s)',
                        'duree_reelle' => $distillation->duree_reelle_formate,
                        'difference' => $distillation->difference_duree_formate
                    ],
                    'couts' => [
                        'total' => $distillation->cout_total_distillation_formate,
                        'par_litre' => $distillation->cout_par_produit_formate,
                        'details' => [
                            'bois_chauffage' => $distillation->cout_bois_chauffage_formate,
                            'carburant' => $distillation->cout_carburant_formate,
                            'main_oeuvre' => $distillation->cout_main_oeuvre_formate
                        ]
                    ],
                    'distilleur_info' => [
                        'id' => $user->id,
                        'nom_complet' => $user->nom . ' ' . $user->prenom,
                        'site_collecte' => $user->siteCollecte->Nom ?? 'Non défini'
                    ]
                ]);
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
            
        } catch (\Exception $e) {
            Log::error('Erreur terminaison distillation: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la terminaison de la distillation',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Récupérer les détails d'une distillation
     * GET /api/distillations/{id}
     */
    public function show($id): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!in_array($user->role, ['distilleur', 'admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès réservé aux distillateurs et administrateurs'
                ], 403);
            }
            
            $query = Distillation::where('id', $id);
            
            if ($user->role === 'distilleur') {
                $query->where('created_by', $user->id);
            }
            
            $distillation = $query->first();
            
            if (!$distillation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Distillation non trouvée ou non autorisée'
                ], 404);
            }
            
            $distillationFormatee = $this->formatDistillation($distillation, true);
            
            return response()->json([
                'success' => true,
                'data' => $distillationFormatee,
                'user_info' => [
                    'id' => $user->id,
                    'nom_complet' => $user->nom . ' ' . $user->prenom,
                    'role' => $user->role
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erreur récupération distillation: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la distillation',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Récupérer les stocks disponibles pour distillation
     * GET /api/distillations/stocks-disponibles
     */
    public function getStocksDisponibles(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if ($user->role !== 'distilleur') {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès réservé aux distillateurs'
                ], 403);
            }
            
            $stocks = StockADistiller::where('distilleur_id', $user->id)
                ->where('statut', 'disponible')
                ->whereRaw('quantite_initiale - quantite_utilisee > 0')
                ->orderBy('type_matiere')
                ->get()
                ->map(function ($stock) {
                    return [
                        'id' => $stock->id,
                        'type_matiere' => $stock->type_matiere,
                        'quantite_restante' => $stock->quantite_restante,
                        'quantite_initiale' => $stock->quantite_initiale,
                        'quantite_utilisee' => $stock->quantite_utilisee,
                        'taux_humidite_moyen' => $stock->taux_humidite_moyen,
                        'taux_dessiccation_moyen' => $stock->taux_dessiccation_moyen,
                        'numero_pv_reference' => $stock->numero_pv_reference,
                        'peut_distiller' => true,
                        'statut' => $stock->statut,
                        'distilleur' => [
                            'id' => $stock->distilleur_id,
                            'nom_complet' => $stock->distilleur->nom . ' ' . $stock->distilleur->prenom
                        ]
                    ];
                });
            
            return response()->json([
                'success' => true,
                'data' => $stocks,
                'count' => $stocks->count(),
                'quantite_totale_disponible' => $stocks->sum('quantite_restante'),
                'distilleur_info' => [
                    'id' => $user->id,
                    'nom_complet' => $user->nom . ' ' . $user->prenom,
                    'site_collecte' => $user->siteCollecte->Nom ?? 'Non défini'
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erreur récupération stocks disponibles: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des stocks disponibles',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Annuler une distillation
     * POST /api/distillations/{id}/annuler
     */
    public function annulerDistillation(Request $request, $id): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if ($user->role !== 'distilleur') {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès réservé aux distillateurs'
                ], 403);
            }
            
            $request->validate([
                'raison_annulation' => 'required|string|max:500'
            ]);
            
            // 1. Vérifier que la distillation existe et appartient au distilleur
            $distillation = Distillation::where('id', $id)
                ->where('created_by', $user->id)
                ->first();
            
            if (!$distillation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Distillation non trouvée ou non autorisée'
                ], 404);
            }
            
            // 2. Vérifier que la distillation est en cours ou en attente
            if (!in_array($distillation->statut, ['en_attente', 'en_cours'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'La distillation ne peut pas être annulée. Statut actuel: ' . $distillation->statut
                ], 400);
            }
            
            DB::beginTransaction();
            
            try {
                // 3. Libérer la quantité dans le stock
                $stock = StockADistiller::where('distilleur_id', $user->id)
                    ->where('type_matiere', $distillation->type_matiere_premiere)
                    ->first();
                
                if ($stock && $distillation->quantite_utilisee > 0) {
                    $stock->libererQuantite($distillation->quantite_utilisee);
                }
                
                // 4. Rembourser le solde (si distillation en cours avec coûts)
                if ($distillation->statut === 'en_cours') {
                    $coutTotal = $distillation->cout_total_distillation;
                    $soldeUser = SoldeUser::where('utilisateur_id', $user->id)->first();
                    
                    if ($soldeUser && $coutTotal > 0) {
                        $soldeUser->solde += $coutTotal;
                        $soldeUser->save();
                    }
                }
                
                // 5. Mettre à jour la distillation
                $distillation->update([
                    'statut' => 'annule',
                    'observations' => 'Annulée: ' . $request->raison_annulation . '. ' . ($distillation->observations ?? '')
                ]);
                
                DB::commit();
                
                return response()->json([
                    'success' => true,
                    'message' => 'Distillation annulée avec succès',
                    'data' => $this->formatDistillation($distillation),
                    'distilleur_info' => [
                        'id' => $user->id,
                        'nom_complet' => $user->nom . ' ' . $user->prenom
                    ]
                ]);
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
            
        } catch (\Exception $e) {
            Log::error('Erreur annulation distillation: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'annulation de la distillation',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Formater une distillation pour l'API
     */
    private function formatDistillation(Distillation $distillation, $details = false): array
    {
        $data = [
            'id' => $distillation->id,
            'type_matiere_premiere' => $distillation->type_matiere_premiere,
            'quantite_utilisee' => $distillation->quantite_utilisee,
            'statut' => $distillation->statut,
            'id_ambalic' => $distillation->id_ambalic,
            'date_debut' => $distillation->date_debut,
            'date_fin' => $distillation->date_fin,
            'usine' => $distillation->usine,
            'duree_distillation' => $distillation->duree_distillation,
            'reference' => $distillation->reference,
            'matiere' => $distillation->matiere,
            'site' => $distillation->site,
            'quantite_traitee' => $distillation->quantite_traitee,
            'type_he' => $distillation->type_he,
            'quantite_resultat' => $distillation->quantite_resultat,
            'observations' => $distillation->observations,
            'created_by' => $distillation->created_by,
            'created_at' => $distillation->created_at,
            'updated_at' => $distillation->updated_at,
            'peut_terminer' => $distillation->statut === 'en_cours',
            'peut_annuler' => in_array($distillation->statut, ['en_attente', 'en_cours'])
        ];
        
        // Ajouter les calculs si la distillation est terminée
        if ($distillation->statut === 'termine') {
            $data['rendement'] = [
                'pourcentage' => $distillation->rendement,
                'formate' => $distillation->rendement_formate
            ];
            
            $data['duree'] = [
                'reelle' => $distillation->duree_reelle,
                'reelle_formate' => $distillation->duree_reelle_formate,
                'difference' => $distillation->difference_duree,
                'difference_formate' => $distillation->difference_duree_formate
            ];
            
            $data['couts'] = [
                'bois_chauffage' => [
                    'total' => $distillation->cout_bois_chauffage,
                    'formate' => $distillation->cout_bois_chauffage_formate
                ],
                'carburant' => [
                    'total' => $distillation->cout_carburant,
                    'formate' => $distillation->cout_carburant_formate
                ],
                'main_oeuvre' => [
                    'total' => $distillation->cout_main_oeuvre,
                    'formate' => $distillation->cout_main_oeuvre_formate,
                    'heures_totales' => $distillation->heures_travail_totales,
                    'heures_totales_formate' => $distillation->heures_travail_totales_formate
                ],
                'total' => $distillation->cout_total_distillation,
                'total_formate' => $distillation->cout_total_distillation_formate,
                'par_produit' => $distillation->cout_par_produit,
                'par_produit_formate' => $distillation->cout_par_produit_formate
            ];
        }
        
        // Ajouter les informations détaillées si demandé
        if ($details) {
            // Informations du créateur
            $data['createur'] = [
                'id' => $distillation->created_by,
                'nom_complet' => $distillation->createdBy->nom . ' ' . $distillation->createdBy->prenom ?? 'Non défini'
            ];
            
            // Stock produit fini associé
            if ($distillation->stock) {
                $data['stock_produit_fini'] = [
                    'id' => $distillation->stock->id,
                    'type_produit' => $distillation->stock->type_produit,
                    'quantite_disponible' => $distillation->stock->quantite_disponible,
                    'statut' => $distillation->stock->statut
                ];
            }
            
            // Transports associés
            if ($distillation->transports) {
                $data['transports'] = $distillation->transports->map(function ($transport) {
                    return [
                        'id' => $transport->id,
                        'quantite_a_livrer' => $transport->quantite_a_livrer,
                        'statut' => $transport->statut,
                        'date_transport' => $transport->date_transport
                    ];
                });
            }
        }
        
        return $data;
    }
}