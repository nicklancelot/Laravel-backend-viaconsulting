<?php

namespace App\Http\Controllers\Distillation;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Models\Distillation\Distillation;
use App\Models\Distillation\Expedition;

class StatistiqueController extends Controller
{
    /**
     * Récupérer les statistiques pour le distilleur connecté
     */
    public function index(): JsonResponse
    {
        try {
            $user = Auth::user();
            
     if (!in_array($user->role, ['admin', 'collecteur', 'vendeur', 'distilleur'])) {
    return response()->json([
        'success' => false,
        'message' => 'Accès non autorisé'
    ], 403);
}


            // Récupérer toutes les distillations du distilleur
            $distillations = Distillation::whereHas('expedition.ficheLivraison', function($query) use ($user) {
                    $query->where('distilleur_id', $user->id);
                })
                ->get();

            // Récupérer toutes les expéditions du distilleur
            $expeditions = Expedition::whereHas('ficheLivraison', function($query) use ($user) {
                    $query->where('distilleur_id', $user->id);
                })
                ->get();

            // Statistiques pour les distillations
            $statsDistillation = [
                'he_feuilles' => [
                    'total_quantite' => 0,
                    'nombre' => 0,
                    'distillations' => []
                ],
                'he_clous' => [
                    'total_quantite' => 0,
                    'nombre' => 0,
                    'distillations' => []
                ],
                'he_griffes' => [
                    'total_quantite' => 0,
                    'nombre' => 0,
                    'distillations' => []
                ]
            ];

            // Calculer les totaux par type d'HE pour les distillations terminées
            foreach ($distillations as $distillation) {
                if ($distillation->statut === 'termine' && $distillation->type_he) {
                    $type_he = strtolower($distillation->type_he);
                    $quantite = $distillation->quantite_resultat ?? 0;
                    
                    // Détecter le type d'HE par son nom
                    if (str_contains($type_he, 'feuille') || str_contains($type_he, 'leaf') || str_contains($type_he, 'fg')) {
                        $statsDistillation['he_feuilles']['total_quantite'] += $quantite;
                        $statsDistillation['he_feuilles']['nombre']++;
                        $statsDistillation['he_feuilles']['distillations'][] = [
                            'id' => $distillation->id,
                            'quantite' => $quantite,
                            'quantite_kg' => number_format($quantite, 2) . ' kg',
                            'date_fin' => $distillation->date_fin,
                            'type_matiere_premiere' => $distillation->type_matiere_premiere,
                            'type_he' => $distillation->type_he
                        ];
                    } elseif (str_contains($type_he, 'clou') || str_contains($type_he, 'nail') || str_contains($type_he, 'cg')) {
                        $statsDistillation['he_clous']['total_quantite'] += $quantite;
                        $statsDistillation['he_clous']['nombre']++;
                        $statsDistillation['he_clous']['distillations'][] = [
                            'id' => $distillation->id,
                            'quantite' => $quantite,
                            'quantite_kg' => number_format($quantite, 2) . ' kg',
                            'date_fin' => $distillation->date_fin,
                            'type_matiere_premiere' => $distillation->type_matiere_premiere,
                            'type_he' => $distillation->type_he
                        ];
                    } elseif (str_contains($type_he, 'griffe') || str_contains($type_he, 'claw') || str_contains($type_he, 'gg')) {
                        $statsDistillation['he_griffes']['total_quantite'] += $quantite;
                        $statsDistillation['he_griffes']['nombre']++;
                        $statsDistillation['he_griffes']['distillations'][] = [
                            'id' => $distillation->id,
                            'quantite' => $quantite,
                            'quantite_kg' => number_format($quantite, 2) . ' kg',
                            'date_fin' => $distillation->date_fin,
                            'type_matiere_premiere' => $distillation->type_matiere_premiere,
                            'type_he' => $distillation->type_he
                        ];
                    }
                }
            }

            // Statistiques pour les expéditions
            $statsExpedition = [
                'feuilles_recues' => [
                    'total_quantite' => 0,
                    'nombre' => 0,
                    'expeditions' => []
                ],
                'clous_recus' => [
                    'total_quantite' => 0,
                    'nombre' => 0,
                    'expeditions' => []
                ],
                'griffes_recues' => [
                    'total_quantite' => 0,
                    'nombre' => 0,
                    'expeditions' => []
                ]
            ];

            // Calculer les totaux par type de matière première reçue
            foreach ($expeditions as $expedition) {
                if ($expedition->statut === 'receptionne') {
                    $type_matiere = strtolower($expedition->type_matiere);
                    $quantite = $expedition->quantite_recue ?? 0;
                    
                    // Détecter le type de matière par son code
                    if ($type_matiere === 'fg' || str_contains($type_matiere, 'feuille')) {
                        $statsExpedition['feuilles_recues']['total_quantite'] += $quantite;
                        $statsExpedition['feuilles_recues']['nombre']++;
                        $statsExpedition['feuilles_recues']['expeditions'][] = [
                            'id' => $expedition->id,
                            'quantite' => $quantite,
                            'quantite_kg' => number_format($quantite, 2) . ' kg',
                            'date_reception' => $expedition->date_reception,
                            'type_matiere' => $expedition->type_matiere
                        ];
                    } elseif ($type_matiere === 'cg' || str_contains($type_matiere, 'clou')) {
                        $statsExpedition['clous_recus']['total_quantite'] += $quantite;
                        $statsExpedition['clous_recus']['nombre']++;
                        $statsExpedition['clous_recus']['expeditions'][] = [
                            'id' => $expedition->id,
                            'quantite' => $quantite,
                            'quantite_kg' => number_format($quantite, 2) . ' kg',
                            'date_reception' => $expedition->date_reception,
                            'type_matiere' => $expedition->type_matiere
                        ];
                    } elseif ($type_matiere === 'gg' || str_contains($type_matiere, 'griffe')) {
                        $statsExpedition['griffes_recues']['total_quantite'] += $quantite;
                        $statsExpedition['griffes_recues']['nombre']++;
                        $statsExpedition['griffes_recues']['expeditions'][] = [
                            'id' => $expedition->id,
                            'quantite' => $quantite,
                            'quantite_kg' => number_format($quantite, 2) . ' kg',
                            'date_reception' => $expedition->date_reception,
                            'type_matiere' => $expedition->type_matiere
                        ];
                    }
                }
            }

            // Arrondir les totaux et formater
            $statsDistillation['he_feuilles']['total_quantite_formate'] = number_format($statsDistillation['he_feuilles']['total_quantite'], 2) . ' kg';
            $statsDistillation['he_clous']['total_quantite_formate'] = number_format($statsDistillation['he_clous']['total_quantite'], 2) . ' kg';
            $statsDistillation['he_griffes']['total_quantite_formate'] = number_format($statsDistillation['he_griffes']['total_quantite'], 2) . ' kg';
            
            $statsExpedition['feuilles_recues']['total_quantite_formate'] = number_format($statsExpedition['feuilles_recues']['total_quantite'], 2) . ' kg';
            $statsExpedition['clous_recus']['total_quantite_formate'] = number_format($statsExpedition['clous_recus']['total_quantite'], 2) . ' kg';
            $statsExpedition['griffes_recues']['total_quantite_formate'] = number_format($statsExpedition['griffes_recues']['total_quantite'], 2) . ' kg';

            // Totaux généraux
            $totaux = [
                'distillation' => [
                    'total_he_feuilles' => $statsDistillation['he_feuilles']['total_quantite_formate'],
                    'total_he_clous' => $statsDistillation['he_clous']['total_quantite_formate'],
                    'total_he_griffes' => $statsDistillation['he_griffes']['total_quantite_formate'],
                    'total_he_tous_types' => number_format(
                        $statsDistillation['he_feuilles']['total_quantite'] + 
                        $statsDistillation['he_clous']['total_quantite'] + 
                        $statsDistillation['he_griffes']['total_quantite'], 
                        2
                    ) . ' kg',
                    'nombre_distillations_terminees' => $distillations->where('statut', 'termine')->count(),
                    'nombre_par_type' => [
                        'feuilles' => $statsDistillation['he_feuilles']['nombre'],
                        'clous' => $statsDistillation['he_clous']['nombre'],
                        'griffes' => $statsDistillation['he_griffes']['nombre']
                    ]
                ],
                'expedition' => [
                    'total_feuilles_recues' => $statsExpedition['feuilles_recues']['total_quantite_formate'],
                    'total_clous_recus' => $statsExpedition['clous_recus']['total_quantite_formate'],
                    'total_griffes_recues' => $statsExpedition['griffes_recues']['total_quantite_formate'],
                    'total_matiere_recue' => number_format(
                        $statsExpedition['feuilles_recues']['total_quantite'] + 
                        $statsExpedition['clous_recus']['total_quantite'] + 
                        $statsExpedition['griffes_recues']['total_quantite'], 
                        2
                    ) . ' kg',
                    'nombre_expeditions_receptionnees' => $expeditions->where('statut', 'receptionne')->count(),
                    'nombre_par_type' => [
                        'feuilles' => $statsExpedition['feuilles_recues']['nombre'],
                        'clous' => $statsExpedition['clous_recus']['nombre'],
                        'griffes' => $statsExpedition['griffes_recues']['nombre']
                    ]
                ],
                'rendements' => [
                    'rendement_feuilles' => $statsExpedition['feuilles_recues']['total_quantite'] > 0 ? 
                        number_format(($statsDistillation['he_feuilles']['total_quantite'] / $statsExpedition['feuilles_recues']['total_quantite']) * 100, 2) . '%' : '0%',
                    'rendement_clous' => $statsExpedition['clous_recus']['total_quantite'] > 0 ? 
                        number_format(($statsDistillation['he_clous']['total_quantite'] / $statsExpedition['clous_recus']['total_quantite']) * 100, 2) . '%' : '0%',
                    'rendement_griffes' => $statsExpedition['griffes_recues']['total_quantite'] > 0 ? 
                        number_format(($statsDistillation['he_griffes']['total_quantite'] / $statsExpedition['griffes_recues']['total_quantite']) * 100, 2) . '%' : '0%',
                    'rendement_global' => ($statsExpedition['feuilles_recues']['total_quantite'] + 
                                         $statsExpedition['clous_recus']['total_quantite'] + 
                                         $statsExpedition['griffes_recues']['total_quantite']) > 0 ? 
                        number_format(
                            (($statsDistillation['he_feuilles']['total_quantite'] + 
                              $statsDistillation['he_clous']['total_quantite'] + 
                              $statsDistillation['he_griffes']['total_quantite']) / 
                             ($statsExpedition['feuilles_recues']['total_quantite'] + 
                              $statsExpedition['clous_recus']['total_quantite'] + 
                              $statsExpedition['griffes_recues']['total_quantite'])) * 100, 
                            2
                        ) . '%' : '0%'
                ]
            ];

            return response()->json([
                'success' => true,
                'message' => 'Statistiques récupérées avec succès',
                'data' => [
                    'distillations' => $statsDistillation,
                    'expeditions' => $statsExpedition,
                    'totaux' => $totaux,
                    'details' => [
                        'distillations_count' => $distillations->count(),
                        'expeditions_count' => $expeditions->count(),
                        'distillations_terminees' => $distillations->where('statut', 'termine')->count(),
                        'expeditions_receptionnees' => $expeditions->where('statut', 'receptionne')->count(),
                        'distillations_en_attente' => $distillations->where('statut', 'en_attente')->count(),
                        'distillations_en_cours' => $distillations->where('statut', 'en_cours')->count(),
                        'expeditions_en_attente' => $expeditions->where('statut', 'en_attente')->count()
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
                'message' => 'Erreur lors de la récupération des statistiques',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Statistiques par période (optionnel)
     */
    public function parPeriode(Request $request): JsonResponse
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
                'date_debut' => 'required|date',
                'date_fin' => 'required|date|after_or_equal:date_debut'
            ]);

            $dateDebut = $request->date_debut;
            $dateFin = $request->date_fin;

            // Récupérer les distillations dans la période
            $distillations = Distillation::whereHas('expedition.ficheLivraison', function($query) use ($user) {
                    $query->where('distilleur_id', $user->id);
                })
                ->where(function($query) use ($dateDebut, $dateFin) {
                    $query->whereBetween('date_debut', [$dateDebut, $dateFin])
                          ->orWhereBetween('date_fin', [$dateDebut, $dateFin])
                          ->orWhereBetween('created_at', [$dateDebut, $dateFin]);
                })
                ->get();

            // Récupérer les expéditions dans la période
            $expeditions = Expedition::whereHas('ficheLivraison', function($query) use ($user) {
                    $query->where('distilleur_id', $user->id);
                })
                ->where(function($query) use ($dateDebut, $dateFin) {
                    $query->whereBetween('date_expedition', [$dateDebut, $dateFin])
                          ->orWhereBetween('date_reception', [$dateDebut, $dateFin])
                          ->orWhereBetween('created_at', [$dateDebut, $dateFin]);
                })
                ->get();

            // Statistiques simplifiées pour la période
            $stats = [
                'periode' => [
                    'date_debut' => $dateDebut,
                    'date_fin' => $dateFin
                ],
                'distillation' => [
                    'he_feuilles' => [
                        'quantite' => 0,
                        'nombre' => 0
                    ],
                    'he_clous' => [
                        'quantite' => 0,
                        'nombre' => 0
                    ],
                    'he_griffes' => [
                        'quantite' => 0,
                        'nombre' => 0
                    ]
                ],
                'expedition' => [
                    'feuilles_recues' => [
                        'quantite' => 0,
                        'nombre' => 0
                    ],
                    'clous_recus' => [
                        'quantite' => 0,
                        'nombre' => 0
                    ],
                    'griffes_recues' => [
                        'quantite' => 0,
                        'nombre' => 0
                    ]
                ]
            ];

            // Calculer les distillations par type d'HE
            foreach ($distillations as $distillation) {
                if ($distillation->statut === 'termine' && $distillation->type_he) {
                    $type_he = strtolower($distillation->type_he);
                    $quantite = $distillation->quantite_resultat ?? 0;
                    
                    if (str_contains($type_he, 'feuille') || str_contains($type_he, 'fg')) {
                        $stats['distillation']['he_feuilles']['quantite'] += $quantite;
                        $stats['distillation']['he_feuilles']['nombre']++;
                    } elseif (str_contains($type_he, 'clou') || str_contains($type_he, 'cg')) {
                        $stats['distillation']['he_clous']['quantite'] += $quantite;
                        $stats['distillation']['he_clous']['nombre']++;
                    } elseif (str_contains($type_he, 'griffe') || str_contains($type_he, 'gg')) {
                        $stats['distillation']['he_griffes']['quantite'] += $quantite;
                        $stats['distillation']['he_griffes']['nombre']++;
                    }
                }
            }

            // Calculer les expéditions par type de matière
            foreach ($expeditions as $expedition) {
                if ($expedition->statut === 'receptionne') {
                    $type_matiere = strtolower($expedition->type_matiere);
                    $quantite = $expedition->quantite_recue ?? 0;
                    
                    if ($type_matiere === 'fg') {
                        $stats['expedition']['feuilles_recues']['quantite'] += $quantite;
                        $stats['expedition']['feuilles_recues']['nombre']++;
                    } elseif ($type_matiere === 'cg') {
                        $stats['expedition']['clous_recus']['quantite'] += $quantite;
                        $stats['expedition']['clous_recus']['nombre']++;
                    } elseif ($type_matiere === 'gg') {
                        $stats['expedition']['griffes_recues']['quantite'] += $quantite;
                        $stats['expedition']['griffes_recues']['nombre']++;
                    }
                }
            }

            // Formater les résultats
            $stats['distillation']['he_feuilles']['quantite_formate'] = number_format($stats['distillation']['he_feuilles']['quantite'], 2) . ' kg';
            $stats['distillation']['he_clous']['quantite_formate'] = number_format($stats['distillation']['he_clous']['quantite'], 2) . ' kg';
            $stats['distillation']['he_griffes']['quantite_formate'] = number_format($stats['distillation']['he_griffes']['quantite'], 2) . ' kg';
            
            $stats['expedition']['feuilles_recues']['quantite_formate'] = number_format($stats['expedition']['feuilles_recues']['quantite'], 2) . ' kg';
            $stats['expedition']['clous_recus']['quantite_formate'] = number_format($stats['expedition']['clous_recus']['quantite'], 2) . ' kg';
            $stats['expedition']['griffes_recues']['quantite_formate'] = number_format($stats['expedition']['griffes_recues']['quantite'], 2) . ' kg';

            return response()->json([
                'success' => true,
                'message' => 'Statistiques par période récupérées',
                'data' => $stats,
                'counts' => [
                    'distillations' => $distillations->count(),
                    'expeditions' => $expeditions->count(),
                    'distillations_terminees' => $distillations->where('statut', 'termine')->count(),
                    'expeditions_receptionnees' => $expeditions->where('statut', 'receptionne')->count()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques par période',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }
}