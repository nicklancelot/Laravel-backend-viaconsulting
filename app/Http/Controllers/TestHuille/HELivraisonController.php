<?php

namespace App\Http\Controllers\TestHuille;

use App\Http\Controllers\Controller;
use App\Models\TestHuille\FicheReception;
use App\Models\TestHuille\HEFicheLivraison;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HELivraisonController extends Controller
{
    // SUPPRIMER la méthode demarrerLivraison - plus nécessaire
    
    public function terminerLivraison($fiche_reception_id)
    {
        try {
            DB::beginTransaction();

            $fiche = FicheReception::find($fiche_reception_id);

            if (!$fiche) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fiche de réception non trouvée'
                ], 404);
            }

            $ficheLivraison = HEFicheLivraison::where('fiche_reception_id', $fiche_reception_id)
                ->orderBy('created_at', 'desc')
                ->first();
            
            if (!$ficheLivraison) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune fiche de livraison trouvée pour cette fiche de réception'
                ], 404);
            }

            // Vérifier que la fiche est en attente de livraison
            if ($fiche->statut !== 'En attente de livraison') {
                return response()->json([
                    'success' => false,
                    'message' => 'La fiche doit être en statut "En attente de livraison" pour terminer la livraison. Statut actuel: ' . $fiche->statut
                ], 400);
            }

            $quantiteALivrer = $ficheLivraison->quantite_a_livrer;
            $quantiteRestanteAvant = $fiche->quantite_restante;

            // Validation supplémentaire de la quantité à livrer
            if ($quantiteALivrer <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'La quantité à livrer doit être positive. Quantité: ' . $quantiteALivrer,
                    'quantite_a_livrer' => $quantiteALivrer
                ], 400);
            }

            // Vérifier que la quantité à livrer ne dépasse pas la quantité totale
            if ($quantiteALivrer > $fiche->quantite_totale) {
                return response()->json([
                    'success' => false,
                    'message' => 'La quantité à livrer (' . $quantiteALivrer . 
                                 ') ne peut pas être supérieure à la quantité totale (' . $fiche->quantite_totale . ')',
                    'quantite_totale' => $fiche->quantite_totale,
                    'quantite_max_autorisée' => $fiche->quantite_totale
                ], 400);
            }

            // Vérifier qu'il y a assez de quantité restante
            if ($quantiteALivrer > $quantiteRestanteAvant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Quantité insuffisante. Quantité demandée: ' . $quantiteALivrer . 
                                 ', Quantité restante disponible: ' . $quantiteRestanteAvant,
                    'quantite_demandee' => $quantiteALivrer,
                    'quantite_disponible' => $quantiteRestanteAvant,
                    'quantite_manquante' => $quantiteALivrer - $quantiteRestanteAvant,
                    'suggestion' => 'Veuillez ajuster la quantité à livrer ou vérifier la quantité restante'
                ], 400);
            }

            // Calculer la nouvelle quantité restante
            $nouvelleQuantiteRestante = $quantiteRestanteAvant - $quantiteALivrer;

            // S'assurer que la quantité n'est pas négative (sécurité supplémentaire)
            if ($nouvelleQuantiteRestante < 0) {
                $nouvelleQuantiteRestante = 0;
            }

            // Mettre à jour la fiche de réception
            $fiche->update([
                'quantite_restante' => $nouvelleQuantiteRestante
            ]);

            // Mettre à jour la fiche de livraison avec la NOUVELLE quantité restante
            $ficheLivraison->update([
                'quantite_restante' => $nouvelleQuantiteRestante
            ]);

            // Déterminer le statut final (directement livré ou partiellement livré)
            $statutFinal = $this->determinerStatutLivraison($fiche);

            $fiche->update(['statut' => $statutFinal]);

            DB::commit();

            $fiche->load(['fournisseur', 'siteCollecte', 'ficheLivraison']);

            return response()->json([
                'success' => true,
                'message' => 'Livraison terminée avec succès',
                'data' => $fiche,
                'nouveau_statut' => $statutFinal,
                'details_livraison' => [
                    'quantite_totale' => $fiche->quantite_totale,
                    'quantite_livree_ce_tour' => $quantiteALivrer,
                    'quantite_restante_avant' => $quantiteRestanteAvant,
                    'quantite_restante_apres' => $nouvelleQuantiteRestante,
                    'quantite_livree_totale' => $fiche->quantite_totale - $nouvelleQuantiteRestante,
                    'pourcentage_total_livre' => round((($fiche->quantite_totale - $nouvelleQuantiteRestante) / $fiche->quantite_totale) * 100, 2),
                    'pourcentage_livre_ce_tour' => round(($quantiteALivrer / $fiche->quantite_totale) * 100, 2),
                    'validation' => [
                        'quantite_positive' => $quantiteALivrer > 0,
                        'quantite_≤_totale' => $quantiteALivrer <= $fiche->quantite_totale,
                        'quantite_≤_restante' => $quantiteALivrer <= $quantiteRestanteAvant,
                        'quantite_restante_≥_0' => $nouvelleQuantiteRestante >= 0,
                        'calcul_correct' => $nouvelleQuantiteRestante == ($quantiteRestanteAvant - $quantiteALivrer)
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la finalisation de la livraison',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function determinerStatutLivraison(FicheReception $fiche)
    {
        if ($fiche->quantite_restante == 0) {
            return 'livré';
        } else {
            return 'partiellement_livre';
        }
    }

    public function peutCreerNouvelleLivraison($fiche_reception_id)
    {
        try {
            $fiche = FicheReception::find($fiche_reception_id);

            if (!$fiche) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fiche de réception non trouvée'
                ], 404);
            }

            // Mettre à jour les statuts autorisés
            $statutsAutorises = ['partiellement_livre', 'payé'];
            $peutCreer = in_array($fiche->statut, $statutsAutorises) &&
                         $fiche->quantite_restante > 0 &&
                         $fiche->statut !== 'En attente de livraison'; // Enlever vérification en cours de livraison

            return response()->json([
                'success' => true,
                'peut_creer_nouvelle_livraison' => $peutCreer,
                'raison' => $peutCreer ? 
                    'Peut créer une nouvelle livraison' : 
                    'Ne peut pas créer de nouvelle livraison',
                'details' => [
                    'statut_actuel' => $fiche->statut,
                    'quantite_restante' => $fiche->quantite_restante,
                    'quantite_totale' => $fiche->quantite_totale,
                    'quantite_deja_livree' => $fiche->quantite_totale - $fiche->quantite_restante,
                    'pourcentage_livre' => round((($fiche->quantite_totale - $fiche->quantite_restante) / $fiche->quantite_totale) * 100, 2),
                    'conditions_remplies' => [
                        'statut_autorise' => in_array($fiche->statut, $statutsAutorises),
                        'quantite_restante_positive' => $fiche->quantite_restante > 0,
                        'pas_en_attente_livraison' => $fiche->statut !== 'En attente de livraison' // Mise à jour
                    ],
                    'statuts_autorises' => $statutsAutorises
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

    public function getEnAttenteLivraison()
    {
        try {
            $fiches = FicheReception::with(['fournisseur', 'siteCollecte', 'ficheLivraison'])
                ->where('statut', 'En attente de livraison')
                ->where('quantite_restante', '>', 0)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Fiches en attente de livraison',
                'data' => $fiches->map(function($fiche) {
                    $ficheLivraison = $fiche->ficheLivraison;
                    return [
                        'id' => $fiche->id,
                        'numero_document' => $fiche->numero_document,
                        'fournisseur' => $fiche->fournisseur,
                        'site_collecte' => $fiche->siteCollecte,
                        'quantite_totale' => $fiche->quantite_totale,
                        'quantite_restante' => $fiche->quantite_restante,
                        'quantite_a_livrer' => $ficheLivraison ? $ficheLivraison->quantite_a_livrer : null,
                        'date_reception' => $fiche->date_reception,
                        'statut' => $fiche->statut,
                        'fiche_livraison' => $ficheLivraison ? [
                            'id' => $ficheLivraison->id,
                            'livreur' => $ficheLivraison->livreur,
                            'destinateur' => $ficheLivraison->destinateur,
                            'destination' => $ficheLivraison->destination
                        ] : null
                    ];
                }),
                'count' => $fiches->count(),
                'total_quantite_en_attente' => $fiches->sum('quantite_restante')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des fiches en attente de livraison',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getPartiellementLivrees()
    {
        try {
            $fiches = FicheReception::with(['fournisseur', 'siteCollecte', 'ficheLivraison'])
                ->where('statut', 'partiellement_livre')
                ->where('quantite_restante', '>', 0)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Fiches partiellement livrées (peuvent recevoir une nouvelle livraison)',
                'data' => $fiches->map(function($fiche) {
                    $quantiteLivree = $fiche->quantite_totale - $fiche->quantite_restante;
                    $ficheLivraison = $fiche->ficheLivraison;
                    return [
                        'id' => $fiche->id,
                        'numero_document' => $fiche->numero_document,
                        'fournisseur' => $fiche->fournisseur,
                        'site_collecte' => $fiche->siteCollecte,
                        'quantite_totale' => $fiche->quantite_totale,
                        'quantite_livree' => $quantiteLivree,
                        'quantite_restante' => $fiche->quantite_restante,
                        'pourcentage_livre' => round(($quantiteLivree / $fiche->quantite_totale) * 100, 2),
                        'date_reception' => $fiche->date_reception,
                        'statut' => $fiche->statut,
                        'peut_creer_nouvelle_livraison' => $this->peutCreerNouvelleLivraisonPourFiche($fiche),
                        'historique_livraisons' => HEFicheLivraison::where('fiche_reception_id', $fiche->id)
                            ->count(),
                        'fiche_livraison_active' => $ficheLivraison ? [
                            'id' => $ficheLivraison->id,
                            'livreur' => $ficheLivraison->livreur,
                            'destinateur' => $ficheLivraison->destinateur,
                            'destination' => $ficheLivraison->destination,
                            'quantite_a_livrer' => $ficheLivraison->quantite_a_livrer,
                            'date_heure_livraison' => $ficheLivraison->date_heure_livraison
                        ] : null
                    ];
                }),
                'count' => $fiches->count(),
                'statistiques' => [
                    'total_quantite_restante' => $fiches->sum('quantite_restante'),
                    'total_quantite_livree' => $fiches->sum(function($fiche) {
                        return $fiche->quantite_totale - $fiche->quantite_restante;
                    }),
                    'nombre_fiches_relivrables' => $fiches->where('quantite_restante', '>', 0)->count(),
                    'moyenne_pourcentage_livre' => $fiches->count() > 0 ? 
                        round($fiches->avg(function($fiche) {
                            $quantiteLivree = $fiche->quantite_totale - $fiche->quantite_restante;
                            return ($quantiteLivree / $fiche->quantite_totale) * 100;
                        }), 2) : 0
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des fiches partiellement livrées',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // SUPPRIMER la méthode getEnCoursLivraison() - plus nécessaire
    
    public function getLivrees()
    {
        try {
            $fiches = FicheReception::with(['fournisseur', 'siteCollecte', 'ficheLivraison'])
                ->where('statut', 'livré')
                ->where('quantite_restante', 0)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Fiches complètement livrées',
                'data' => $fiches->map(function($fiche) {
                    $ficheLivraison = $fiche->ficheLivraison;
                    return [
                        'id' => $fiche->id,
                        'numero_document' => $fiche->numero_document,
                        'fournisseur' => $fiche->fournisseur,
                        'site_collecte' => $fiche->siteCollecte,
                        'quantite_totale' => $fiche->quantite_totale,
                        'quantite_livree' => $fiche->quantite_totale,
                        'quantite_restante' => 0,
                        'pourcentage_livre' => 100,
                        'date_reception' => $fiche->date_reception,
                        'date_livraison_finale' => $fiche->updated_at,
                        'statut' => $fiche->statut,
                        'historique_livraisons' => HEFicheLivraison::where('fiche_reception_id', $fiche->id)
                            ->count(),
                        'fiche_livraison_finale' => $ficheLivraison ? [
                            'id' => $ficheLivraison->id,
                            'livreur' => $ficheLivraison->livreur,
                            'destinateur' => $ficheLivraison->destinateur,
                            'destination' => $ficheLivraison->destination,
                            'date_heure_livraison' => $ficheLivraison->date_heure_livraison,
                            'quantite_a_livrer' => $ficheLivraison->quantite_a_livrer
                        ] : null
                    ];
                }),
                'count' => $fiches->count(),
                'statistiques' => [
                    'total_quantite_livree' => $fiches->sum('quantite_totale'),
                    'nombre_total_livraisons' => $fiches->sum(function($fiche) {
                        return HEFicheLivraison::where('fiche_reception_id', $fiche->id)->count();
                    })
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des fiches livrées',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getHistoriqueLivraisons($fiche_reception_id)
    {
        try {
            $fiche = FicheReception::find($fiche_reception_id);
            
            if (!$fiche) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fiche de réception non trouvée'
                ], 404);
            }

            $livraisons = HEFicheLivraison::with(['livreur', 'destinateur'])
                ->where('fiche_reception_id', $fiche_reception_id)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Historique des livraisons',
                'fiche' => [
                    'id' => $fiche->id,
                    'numero_document' => $fiche->numero_document,
                    'quantite_totale' => $fiche->quantite_totale,
                    'quantite_restante' => $fiche->quantite_restante,
                    'quantite_livree_totale' => $fiche->quantite_totale - $fiche->quantite_restante,
                    'statut' => $fiche->statut
                ],
                'livraisons' => $livraisons->map(function($livraison, $index) use ($fiche) {
                    return [
                        'numero' => $index + 1,
                        'id' => $livraison->id,
                        'date_heure_livraison' => $livraison->date_heure_livraison,
                        'livreur' => $livraison->livreur,
                        'destinateur' => $livraison->destinateur,
                        'destination' => $livraison->destination,
                        'quantite_a_livrer' => $livraison->quantite_a_livrer,
                        'quantite_restante_apres' => $livraison->quantite_restante,
                        'ristourne_regionale' => $livraison->ristourne_regionale,
                        'ristourne_communale' => $livraison->ristourne_communale,
                        'created_at' => $livraison->created_at
                    ];
                }),
                'count' => $livraisons->count(),
                'resume' => [
                    'nombre_livraisons' => $livraisons->count(),
                    'quantite_totale_livree' => $fiche->quantite_totale - $fiche->quantite_restante,
                    'quantite_restante' => $fiche->quantite_restante,
                    'pourcentage_total_livre' => round((($fiche->quantite_totale - $fiche->quantite_restante) / $fiche->quantite_totale) * 100, 2),
                    'peut_creer_nouvelle_livraison' => $fiche->quantite_restante > 0 && 
                                                      in_array($fiche->statut, ['partiellement_livre', 'payé'])
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l\'historique',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function peutCreerNouvelleLivraisonPourFiche(FicheReception $fiche)
    {
        $statutsAutorises = ['partiellement_livre', 'payé'];
        return in_array($fiche->statut, $statutsAutorises) &&
               $fiche->quantite_restante > 0 &&
               $fiche->statut !== 'En attente de livraison'; 
    }
}