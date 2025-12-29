<?php

namespace App\Http\Controllers\Distillation;

use App\Http\Controllers\Controller;
use App\Models\Distillation\Distillation;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Models\SoldeUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DistillationController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $query = Distillation::query();
            
            if ($user->role === 'distilleur') {
                $query->whereHas('expedition.ficheLivraison', function($query) use ($user) {
                    $query->where('distilleur_id', $user->id);
                });
            } 
            
            $distillations = $query
                ->with(['expedition.ficheLivraison.stockpv', 'expedition.ficheLivraison.livreur', 'expedition.ficheLivraison.distilleur.siteCollecte'])
                ->orderBy('created_at', 'desc')
                ->get();

            $distillations->each(function ($distillation) use ($user) {
                $distillation->peut_demarrer = $distillation->estEnAttente();
                $distillation->peut_terminer = $distillation->estEnCours();
                $distillation->rendement_formate = $distillation->rendement_formate;
                
                if ($distillation->estTerminee()) {
                    $distillation->duree_reelle = $distillation->duree_reelle;
                    $distillation->duree_reelle_formate = $distillation->duree_reelle_formate;
                    $distillation->difference_duree_formate = $distillation->difference_duree_formate;
                }
                
                // Calculs des coûts avec heures de travail
                $distillation->calculs_couts = [
                    'bois_chauffage' => [
                        'quantite' => $distillation->quantite_bois_chauffage,
                        'prix_unitaire' => $distillation->prix_bois_chauffage,
                        'total' => $distillation->cout_bois_chauffage,
                        'total_formate' => $distillation->cout_bois_chauffage_formate
                    ],
                    'carburant' => [
                        'quantite' => $distillation->quantite_carburant,
                        'prix_unitaire' => $distillation->prix_carburant,
                        'total' => $distillation->cout_carburant,
                        'total_formate' => $distillation->cout_carburant_formate
                    ],
                    'main_oeuvre' => [
                        'nombre_ouvriers' => $distillation->nombre_ouvriers,
                        'heures_par_ouvrier' => $distillation->heures_travail_par_ouvrier,
                        'prix_heure' => $distillation->prix_heure_main_oeuvre,
                        'heures_totales' => $distillation->heures_travail_totales,
                        'heures_totales_formate' => $distillation->heures_travail_totales_formate,
                        'total' => $distillation->cout_main_oeuvre,
                        'total_formate' => $distillation->cout_main_oeuvre_formate,
                        'cout_horaire_moyen' => $distillation->cout_horaire_moyen_formate,
                        'cout_par_heure' => $distillation->cout_par_heure_travail_formate
                    ],
                    'total_distillation' => $distillation->cout_total_distillation_formate,
                    'cout_par_produit' => $distillation->cout_par_produit_formate
                ];
                
                if ($user->role === 'admin' && $distillation->expedition->ficheLivraison->distilleur) {
                    $distillation->distilleur_info = [
                        'id' => $distillation->expedition->ficheLivraison->distilleur->id,
                        'nom_complet' => $distillation->expedition->ficheLivraison->distilleur->nom . ' ' . $distillation->expedition->ficheLivraison->distilleur->prenom,
                        'site_collecte' => $distillation->expedition->ficheLivraison->distilleur->siteCollecte->Nom ?? 'Non défini'
                    ];
                }
            });

            $stats = [
                'total' => $distillations->count(),
                'en_attente' => $distillations->where('statut', 'en_attente')->count(),
                'en_cours' => $distillations->where('statut', 'en_cours')->count(),
                'terminees' => $distillations->where('statut', 'termine')->count(),
                'quantite_recue_totale' => $distillations->sum('quantite_recue'),
                'quantite_resultat_totale' => $distillations->where('statut', 'termine')->sum('quantite_resultat'),
                'heures_travail_totales' => $distillations->where('statut', 'termine')->sum(function ($d) {
                    return $d->heures_travail_totales;
                })
            ];

            $response = [
                'success' => true,
                'message' => $user->role === 'admin' ? 'Liste de toutes les distillations' : 'Liste de toutes vos distillations',
                'data' => $distillations,
                'stats' => $stats,
                'count' => $distillations->count(),
                'user_role' => $user->role,
                'user_info' => [
                    'id' => $user->id,
                    'nom_complet' => $user->nom . ' ' . $user->prenom,
                    'role' => $user->role
                ]
            ];

            if ($user->role === 'distilleur') {
                $response['distilleur_info'] = [
                    'id' => $user->id,
                    'nom_complet' => $user->nom . ' ' . $user->prenom,
                    'site_collecte' => $user->siteCollecte->Nom ?? 'Non défini'
                ];
            }

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Erreur récupération distillations: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des distillations',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function demarrerDistillation(Request $request, $distillationId): JsonResponse
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
                'id_ambalic' => 'required|string|max:50',
                'date_debut' => 'required|date',
                'poids_distiller' => 'required|numeric|min:0',
                'usine' => 'required|string|max:100',
                'duree_distillation' => 'required|integer|min:1',
                'quantite_bois_chauffage' => 'required|numeric|min:0',
                'prix_bois_chauffage' => 'required|numeric|min:0',
                'quantite_carburant' => 'required|numeric|min:0',
                'prix_carburant' => 'required|numeric|min:0',
                'nombre_ouvriers' => 'required|numeric|min:1',
                'heures_travail_par_ouvrier' => 'required|numeric|min:1',
                'prix_heure_main_oeuvre' => 'required|numeric|min:0'
            ]);

            $soldeUser = SoldeUser::where('utilisateur_id', $user->id)->first();
            
            if (!$soldeUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun compte solde trouvé'
                ], 400);
            }

            // Calcul coût total
            $coutBoisChauffage = $request->quantite_bois_chauffage * $request->prix_bois_chauffage;
            $coutCarburant = $request->quantite_carburant * $request->prix_carburant;
            $coutMainOeuvre = $request->nombre_ouvriers * $request->heures_travail_par_ouvrier * $request->prix_heure_main_oeuvre;
            $coutTotal = $coutBoisChauffage + $coutCarburant + $coutMainOeuvre;

            if ($soldeUser->solde < $coutTotal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solde insuffisant',
                    'details' => [
                        'solde_disponible' => $soldeUser->solde,
                        'cout_total_requis' => $coutTotal,
                        'manque' => $coutTotal - $soldeUser->solde
                    ]
                ], 400);
            }

            $distillation = Distillation::where('id', $distillationId)
                ->whereHas('expedition.ficheLivraison', function($query) use ($user) {
                    $query->where('distilleur_id', $user->id);
                })
                ->first();
            
            if (!$distillation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Distillation non trouvée'
                ], 404);
            }

            if (!$distillation->estEnAttente()) {
                return response()->json([
                    'success' => false,
                    'message' => 'La distillation n\'est pas en attente. Statut: ' . $distillation->statut
                ], 400);
            }

            $ancienSolde = $soldeUser->solde;

            if (!$soldeUser->debiter($coutTotal)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur lors du débit du solde'
                ], 500);
            }

            $distillation->demarrer([
                'id_ambalic' => $request->id_ambalic,
                'date_debut' => $request->date_debut,
                'poids_distiller' => $request->poids_distiller,
                'usine' => $request->usine,
                'duree_distillation' => $request->duree_distillation,
                'quantite_bois_chauffage' => $request->quantite_bois_chauffage,
                'prix_bois_chauffage' => $request->prix_bois_chauffage,
                'quantite_carburant' => $request->quantite_carburant,
                'prix_carburant' => $request->prix_carburant,
                'nombre_ouvriers' => $request->nombre_ouvriers,
                'heures_travail_par_ouvrier' => $request->heures_travail_par_ouvrier,
                'prix_heure_main_oeuvre' => $request->prix_heure_main_oeuvre
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Distillation démarrée avec succès',
                'data' => $distillation->load(['expedition.ficheLivraison.stockpv']),
                'solde_info' => [
                    'ancien_solde' => $ancienSolde,
                    'nouveau_solde' => $soldeUser->solde,
                    'montant_debite' => $coutTotal,
                    'cout_details' => [
                        'bois_chauffage' => $coutBoisChauffage,
                        'carburant' => $coutCarburant,
                        'main_oeuvre' => $coutMainOeuvre,
                        'total' => $coutTotal
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur démarrage distillation: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du démarrage de la distillation',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function terminerDistillation(Request $request, $distillationId): JsonResponse
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
                'reference' => 'required|string|max:50',
                'matiere' => 'required|string|max:100',
                'site' => 'required|string|max:100',
                'quantite_traitee' => 'required|numeric|min:0',
                'date_fin' => 'required|date',
                'type_he' => 'required|string|max:50',
                'quantite_resultat' => 'required|numeric|min:0',
                'observations' => 'nullable|string'
            ]);

            $distillation = Distillation::where('id', $distillationId)
                ->whereHas('expedition.ficheLivraison', function($query) use ($user) {
                    $query->where('distilleur_id', $user->id);
                })
                ->first();
            
            if (!$distillation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Distillation non trouvée ou non autorisée'
                ], 404);
            }

            if (!$distillation->estEnCours()) {
                return response()->json([
                    'success' => false,
                    'message' => 'La distillation n\'est pas en cours. Statut actuel: ' . $distillation->statut
                ], 400);
            }

            DB::beginTransaction();
            
            try {
                $distillation->terminer([
                    'reference' => $request->reference,
                    'matiere' => $request->matiere,
                    'site' => $request->site,
                    'quantite_traitee' => $request->quantite_traitee,
                    'date_fin' => $request->date_fin,
                    'type_he' => $request->type_he,
                    'quantite_resultat' => $request->quantite_resultat,
                    'observations' => $request->observations
                ]);

                $stock = $distillation->stock()->first();
                
                if (!$stock) {
                    throw new \Exception('Le stock n\'a pas été créé');
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Distillation terminée avec succès et stock créé',
                    'data' => $distillation->load(['expedition.ficheLivraison.stockpv', 'stock']),
                    'stock_creé' => $stock,
                    'rendement' => $distillation->rendement_formate,
                    'duree_calculs' => [
                        'duree_estimee' => $distillation->duree_distillation . ' jour(s)',
                        'duree_reelle' => $distillation->duree_reelle_formate,
                        'difference' => $distillation->difference_duree_formate
                    ]
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Erreur finalisation distillation: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la finalisation de la distillation: ' . $e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }
}