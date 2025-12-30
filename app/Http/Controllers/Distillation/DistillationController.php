<?php

namespace App\Http\Controllers\Distillation;

use App\Http\Controllers\Controller;
use App\Models\Distillation\Distillation;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Models\SoldeUser;

class DistillationController extends Controller
{
    /**
     * Récupérer toutes les distillations du distilleur connecté
     */

public function index(): JsonResponse
{
    try {
        $user = Auth::user();
        
        // Construire la requête selon le rôle
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

        // Ajouter des informations formatées
        $distillations->each(function ($distillation) use ($user) {
            $distillation->peut_demarrer = $distillation->estEnAttente();
            $distillation->peut_terminer = $distillation->estEnCours();
            $distillation->rendement_formate = $distillation->rendement_formate;
            
            // Ajouter les calculs de durée pour les distillations terminées
            if ($distillation->estTerminee()) {
                $distillation->duree_reelle = $distillation->duree_reelle;
                $distillation->duree_reelle_formate = $distillation->duree_reelle_formate;
                $distillation->difference_duree_formate = $distillation->difference_duree_formate;
            }
            
            // AJOUTER LES CALCULS DES COÛTS
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
                    'nombre' => $distillation->nombre_ouvriers,
                    'prix_unitaire' => $distillation->prix_main_oeuvre,
                    'total' => $distillation->cout_main_oeuvre,
                    'total_formate' => $distillation->cout_main_oeuvre_formate
                ],
                'total_distillation' => $distillation->cout_total_distillation_formate,
                'cout_par_produit' => $distillation->cout_par_produit_formate
            ];
            
            // Ajouter info distilleur pour admin
            if ($user->role === 'admin' && $distillation->expedition->ficheLivraison->distilleur) {
                $distillation->distilleur_info = [
                    'id' => $distillation->expedition->ficheLivraison->distilleur->id,
                    'nom_complet' => $distillation->expedition->ficheLivraison->distilleur->nom . ' ' . $distillation->expedition->ficheLivraison->distilleur->prenom,
                    'site_collecte' => $distillation->expedition->ficheLivraison->distilleur->siteCollecte->Nom ?? 'Non défini'
                ];
            }
        });

        // Statistiques
        $stats = [
            'total' => $distillations->count(),
            'en_attente' => $distillations->where('statut', 'en_attente')->count(),
            'en_cours' => $distillations->where('statut', 'en_cours')->count(),
            'terminees' => $distillations->where('statut', 'termine')->count(),
            'quantite_recue_totale' => $distillations->sum('quantite_recue'),
            'quantite_resultat_totale' => $distillations->where('statut', 'termine')->sum('quantite_resultat')
        ];

        // Ajouter les statistiques des coûts
        $stats['cout_total_bois_chauffage'] = $distillations->sum('cout_bois_chauffage');
        $stats['cout_total_carburant'] = $distillations->sum('cout_carburant');
        $stats['cout_total_main_oeuvre'] = $distillations->sum('cout_main_oeuvre');
        $stats['cout_total_distillation'] = $distillations->sum('cout_total_distillation');
        
        // Formater les statistiques monétaires
        $stats['cout_total_bois_chauffage_formate'] = number_format($stats['cout_total_bois_chauffage'], 2) . ' MGA';
        $stats['cout_total_carburant_formate'] = number_format($stats['cout_total_carburant'], 2) . ' MGA';
        $stats['cout_total_main_oeuvre_formate'] = number_format($stats['cout_total_main_oeuvre'], 2) . ' MGA';
        $stats['cout_total_distillation_formate'] = number_format($stats['cout_total_distillation'], 2) . ' MGA';

        // Préparer la réponse selon le rôle
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

        // Ajouter info spécifique au distilleur
        if ($user->role === 'distilleur') {
            $response['distilleur_info'] = [
                'id' => $user->id,
                'nom_complet' => $user->nom . ' ' . $user->prenom,
                'site_collecte' => $user->siteCollecte->Nom ?? 'Non défini'
            ];
        }

        return response()->json($response);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la récupération des distillations',
            'error' => env('APP_DEBUG') ? $e->getMessage() : null
        ], 500);
    }
}
    /**
     * Démarrer une distillation (pour le distilleur connecté)
     */
    /**
 * Démarrer une distillation (pour le distilleur connecté)
 */
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
            'nombre_ouvriers' => 'required|numeric|min:0',
            'prix_main_oeuvre' => 'required|numeric|min:0'
        ]);

        // Récupérer le solde de l'utilisateur
        $soldeUser = SoldeUser::where('utilisateur_id', $user->id)->first();
        
        if (!$soldeUser) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun compte solde trouvé pour cet utilisateur. Veuillez contacter l\'administrateur.'
            ], 400);
        }

        // Calculer le coût total de la distillation
        $coutBoisChauffage = $request->quantite_bois_chauffage * $request->prix_bois_chauffage;
        $coutCarburant = $request->quantite_carburant * $request->prix_carburant;
        $coutMainOeuvre = $request->nombre_ouvriers * $request->prix_main_oeuvre;
        $coutTotal = $coutBoisChauffage + $coutCarburant + $coutMainOeuvre;

        // Vérifier si le solde est suffisant
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
                        'prix_unitaire' => $request->prix_main_oeuvre,
                        'total' => $coutMainOeuvre,
                        'total_formate' => number_format($coutMainOeuvre, 2) . ' MGA'
                    ],
                    'total' => $coutTotal,
                    'total_formate' => number_format($coutTotal, 2) . ' MGA'
                ]
            ], 400);
        }

        // Récupérer la distillation
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

        // Vérifier que la distillation est en attente
        if (!$distillation->estEnAttente()) {
            return response()->json([
                'success' => false,
                'message' => 'La distillation n\'est pas en attente. Statut actuel: ' . $distillation->statut
            ], 400);
        }

        // Sauvegarder l'ancien solde pour la réponse
        $ancienSolde = $soldeUser->solde;

        // Débiter le solde de l'utilisateur
        if (!$soldeUser->debiter($coutTotal)) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du débit du solde. Veuillez réessayer.'
            ], 500);
        }

        // Démarrer la distillation
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
            'prix_main_oeuvre' => $request->prix_main_oeuvre
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Distillation démarrée avec succès',
            'data' => $distillation->load(['expedition.ficheLivraison.stockpv']),
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
            'distilleur_info' => [
                'id' => $user->id,
                'nom_complet' => $user->nom . ' ' . $user->prenom,
                'site_collecte' => $user->siteCollecte->Nom ?? 'Non défini'
            ]
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors du démarrage de la distillation',
            'error' => env('APP_DEBUG') ? $e->getMessage() : null
        ], 500);
    }
}

    /**
     * Terminer une distillation (pour le distilleur connecté)
     */
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

            // Vérifier que la distillation appartient au distilleur connecté
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

            // Vérifier que la distillation est en cours
            if (!$distillation->estEnCours()) {
                return response()->json([
                    'success' => false,
                    'message' => 'La distillation n\'est pas en cours. Statut actuel: ' . $distillation->statut
                ], 400);
            }

            // Terminer la distillation
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

            return response()->json([
                'success' => true,
                'message' => 'Distillation terminée avec succès',
                'data' => $distillation->load(['expedition.ficheLivraison.stockpv']),
                'rendement' => $distillation->rendement_formate,
                'duree_calculs' => [
                    'duree_estimee' => $distillation->duree_distillation . ' jour(s)',
                    'duree_reelle' => $distillation->duree_reelle_formate,
                    'difference' => $distillation->difference_duree_formate
                ],
                'distilleur_info' => [
                    'id' => $user->id,
                    'nom_complet' => $user->nom . ' ' . $user->prenom,
                    'site_collecte' => $user->siteCollecte->Nom ?? 'Non défini'
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la finalisation de la distillation',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }
}