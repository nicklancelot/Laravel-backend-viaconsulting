<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Distillation\Distillation;
use App\Models\Distillation\Expedition;
use App\Models\Distillation\Transport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DistillationDashController extends Controller
{
    /**
     * Récupérer les statistiques du stock d'huile essentielle
     */
    public function getStockHuileEssentielle(Request $request): JsonResponse
    {
        try {
            $usineFilter = $request->query('usine', 'Toutes');
            
            // Récupérer les distillations terminées
            $query = Distillation::where('statut', 'termine')
                ->whereNotNull('quantite_resultat')
                ->whereNotNull('type_he');
            
            if ($usineFilter !== 'Toutes') {
                $query->where('usine', $usineFilter);
            }
            
            $distillations = $query->get();
            
            // Initialiser les statistiques pour chaque type d'HE
            $typesHe = ['HE Feuille', 'HE Griffe', 'HE Clous'];
            $statistiques = [];
            
            foreach ($typesHe as $type) {
                $statistiques[$type] = [
                    'production' => 0,
                    'stock_disponible' => 0,
                    'pourcentage_total' => 0
                ];
            }
            
            // Calculer la production par type
            foreach ($distillations as $distillation) {
                $type = $distillation->type_he;
                if (in_array($type, $typesHe)) {
                    $statistiques[$type]['production'] += $distillation->quantite_resultat;
                }
            }
            
            // Calculer le stock disponible (production - transports)
            foreach ($typesHe as $type) {
                $production = $statistiques[$type]['production'];
                
                // Récupérer les transports pour ce type d'HE
                $queryTransports = Transport::whereHas('distillation', function($q) use ($type, $usineFilter) {
                    $q->where('type_he', $type);
                    
                    if ($usineFilter !== 'Toutes') {
                        $q->where('usine', $usineFilter);
                    }
                });
                
                $transports = $queryTransports->sum('quantite_a_livrer');
                
                $statistiques[$type]['stock_disponible'] = max(0, $production - $transports);
            }
            
            // Calculer le stock total disponible
            $stockTotalDisponible = array_sum(array_column($statistiques, 'stock_disponible'));
            
            // Calculer les pourcentages
            if ($stockTotalDisponible > 0) {
                foreach ($typesHe as $type) {
                    $statistiques[$type]['pourcentage_total'] = 
                        number_format(($statistiques[$type]['stock_disponible'] / $stockTotalDisponible) * 100, 1);
                }
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'statistiques' => $statistiques,
                    'totaux' => [
                        'production_totale' => array_sum(array_column($statistiques, 'production')),
                        'stock_total_disponible' => $stockTotalDisponible,
                        'usine_selectionnee' => $usineFilter
                    ]
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du calcul du stock d\'huile essentielle',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Récupérer le résumé de production (stock matière première)
     */
    public function getResumeProduction(Request $request): JsonResponse
    {
        try {
            $usineFilter = $request->query('usine', 'Toutes');
            
            // Récupérer les données d'expédition
            $query = DB::table('expeditions as e')
                ->join('fiche_livraisons as fl', 'e.fiche_livraison_id', '=', 'fl.id')
                ->whereIn('e.type_matiere', ['FG', 'CG', 'GG'])
                ->select(
                    'e.type_matiere',
                    DB::raw('COALESCE(SUM(e.quantite_expediee), 0) as quantite_expediee'),
                    DB::raw('COALESCE(SUM(e.quantite_recue), 0) as quantite_recue')
                );
            
            // Filtrer par usine si nécessaire
            if ($usineFilter !== 'Toutes') {
                $query->leftJoin('distillations as d', 'e.id', '=', 'd.expedition_id')
                    ->where('d.usine', $usineFilter);
            }
            
            $resultats = $query->groupBy('e.type_matiere')->get();
            
            // Mapper les types de matière première
            $typeMapping = [
                'FG' => 'feuilles',
                'CG' => 'clous',
                'GG' => 'griffes'
            ];
            
            $resume = [];
            foreach ($typeMapping as $code => $nom) {
                $resume[$nom] = [
                    'stock_restant' => 0,
                    'quantite_expediee' => 0,
                    'quantite_recue' => 0,
                    'pourcentage_total' => 0
                ];
            }
            
            // Remplir avec les données réelles
            foreach ($resultats as $resultat) {
                if (isset($typeMapping[$resultat->type_matiere])) {
                    $type = $typeMapping[$resultat->type_matiere];
                    $resume[$type]['quantite_expediee'] = (float)$resultat->quantite_expediee;
                    $resume[$type]['quantite_recue'] = (float)$resultat->quantite_recue;
                    $resume[$type]['stock_restant'] = max(0, $resultat->quantite_expediee - $resultat->quantite_recue);
                }
            }
            
            // Calculer le stock total
            $stockTotal = array_sum(array_column($resume, 'stock_restant'));
            
            // Calculer les pourcentages
            if ($stockTotal > 0) {
                foreach ($resume as $type => &$stats) {
                    $stats['pourcentage_total'] = number_format(($stats['stock_restant'] / $stockTotal) * 100, 1);
                }
            }
            
            // Calculer le stock total d'huile essentielle
            $stockHe = $this->calculerStockHeTotal($usineFilter);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'resume' => $resume,
                    'totaux' => [
                        'total_stock_huile_essentielle' => $stockHe,
                        'total_stock_matiere_premiere' => $stockTotal,
                        'usine_selectionnee' => $usineFilter
                    ]
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du calcul du résumé de production',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Récupérer la liste des usines disponibles
     */
    public function getUsinesDisponibles(): JsonResponse
    {
        try {
            $usines = Distillation::select('usine')
                ->whereNotNull('usine')
                ->where('usine', '!=', '')
                ->distinct()
                ->orderBy('usine')
                ->pluck('usine')
                ->toArray();
            
            // Ajouter "Toutes" comme première option
            array_unshift($usines, 'Toutes');
            
            return response()->json([
                'success' => true,
                'data' => $usines
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des usines',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Récupérer le dashboard complet
     */
    public function getDashboardComplet(Request $request): JsonResponse
    {
        try {
            $usine = $request->query('usine', 'Toutes');
            
            // Récupérer toutes les données
            $stockHeData = $this->getStockHuileEssentielle($request)->getData();
            $resumeData = $this->getResumeProduction($request)->getData();
            $usinesData = $this->getUsinesDisponibles()->getData();
            
            if (!$stockHeData->success || !$resumeData->success || !$usinesData->success) {
                throw new \Exception('Erreur dans la récupération des données');
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'stock_huile_essentielle' => $stockHeData->data,
                    'resume_production' => $resumeData->data,
                    'usines_disponibles' => $usinesData->data,
                    'filtre_actuel' => $usine,
                    'date_actualisation' => Carbon::now()->format('d/m/Y H:i:s')
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du dashboard complet',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Récupérer les statistiques en temps réel
     */
    public function getStatistiquesTempsReel(Request $request): JsonResponse
    {
        try {
            $usineFilter = $request->query('usine', 'Toutes');
            $dateDebut = Carbon::now()->subDay();
            
            // Production des dernières 24h
            $queryProduction = Distillation::where('statut', 'termine')
                ->where('created_at', '>=', $dateDebut);
                
            if ($usineFilter !== 'Toutes') {
                $queryProduction->where('usine', $usineFilter);
            }
            
            $production24h = $queryProduction->sum('quantite_resultat');
            
            // Expéditions des dernières 24h
            $queryExpeditions = Expedition::where('created_at', '>=', $dateDebut);
            
            if ($usineFilter !== 'Toutes') {
                $queryExpeditions->whereHas('ficheLivraison.distilleur.siteCollecte', function($q) use ($usineFilter) {
                    $q->where('Nom', 'LIKE', '%' . $usineFilter . '%');
                });
            }
            
            $expeditions24h = $queryExpeditions->sum('quantite_expediee');
            
            // Distillations en cours
            $queryDistillations = Distillation::where('statut', 'en_cours');
            
            if ($usineFilter !== 'Toutes') {
                $queryDistillations->where('usine', $usineFilter);
            }
            
            $distillationsEnCours = $queryDistillations->count();
            
            // Transports en cours
            $queryTransports = Transport::where('statut', 'en_cours');
            
            if ($usineFilter !== 'Toutes') {
                $queryTransports->whereHas('distillation', function($q) use ($usineFilter) {
                    $q->where('usine', $usineFilter);
                });
            }
            
            $transportsEnCours = $queryTransports->count();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'production_24h' => $production24h,
                    'expeditions_24h' => $expeditions24h,
                    'distillations_en_cours' => $distillationsEnCours,
                    'transports_en_cours' => $transportsEnCours,
                    'heure_actualisation' => Carbon::now()->format('H:i:s'),
                    'usine_filtre' => $usineFilter
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques temps réel',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Calculer le rendement moyen par type d'HE
     */
    public function getRendementMoyen(Request $request): JsonResponse
    {
        try {
            $usineFilter = $request->query('usine', 'Toutes');
            
            $query = Distillation::where('statut', 'termine')
                ->whereNotNull('quantite_traitee')
                ->whereNotNull('quantite_resultat')
                ->where('quantite_traitee', '>', 0);
                
            if ($usineFilter !== 'Toutes') {
                $query->where('usine', $usineFilter);
            }
            
            $distillations = $query->get();
            
            $rendements = [
                'HE Feuille' => ['total' => 0, 'count' => 0, 'moyen' => 0],
                'HE Griffe' => ['total' => 0, 'count' => 0, 'moyen' => 0],
                'HE Clous' => ['total' => 0, 'count' => 0, 'moyen' => 0]
            ];
            
            foreach ($distillations as $distillation) {
                $type = $distillation->type_he;
                if (isset($rendements[$type])) {
                    $rendement = ($distillation->quantite_resultat / $distillation->quantite_traitee) * 100;
                    $rendements[$type]['total'] += $rendement;
                    $rendements[$type]['count']++;
                }
            }
            
            // Calculer les moyennes
            foreach ($rendements as $type => &$data) {
                if ($data['count'] > 0) {
                    $data['moyen'] = number_format($data['total'] / $data['count'], 2);
                }
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'rendements' => $rendements,
                    'usine_selectionnee' => $usineFilter
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du calcul des rendements',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Calculer le stock total d'huile essentielle
     */
    private function calculerStockHeTotal($usineFilter): float
    {
        $query = Distillation::where('statut', 'termine')
            ->whereNotNull('quantite_resultat');
            
        if ($usineFilter !== 'Toutes') {
            $query->where('usine', $usineFilter);
        }
        
        $productionTotale = $query->sum('quantite_resultat');
        
        $queryTransports = Transport::query();
        
        if ($usineFilter !== 'Toutes') {
            $queryTransports->whereHas('distillation', function($q) use ($usineFilter) {
                $q->where('usine', $usineFilter);
            });
        }
        
        $transportsTotaux = $queryTransports->sum('quantite_a_livrer');
        
        return max(0, $productionTotale - $transportsTotaux);
    }
    
    /**
     * Récupérer les tendances de production sur les 7 derniers jours
     */
    public function getTendancesProduction(Request $request): JsonResponse
    {
        try {
            $usineFilter = $request->query('usine', 'Toutes');
            $jours = 7;
            
            $tendances = [];
            $dates = [];
            
            for ($i = $jours - 1; $i >= 0; $i--) {
                $date = Carbon::now()->subDays($i)->format('Y-m-d');
                $dates[] = Carbon::parse($date)->format('d/m');
                
                $query = Distillation::where('statut', 'termine')
                    ->whereDate('date_fin', $date);
                    
                if ($usineFilter !== 'Toutes') {
                    $query->where('usine', $usineFilter);
                }
                
                $production = $query->sum('quantite_resultat');
                $tendances[] = $production;
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'labels' => $dates,
                    'donnees' => $tendances,
                    'periode' => '7 derniers jours',
                    'usine_selectionnee' => $usineFilter
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des tendances',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Récupérer les statistiques par distilleur
     */
    public function getStatistiquesParDistilleur(Request $request): JsonResponse
    {
        try {
            $usineFilter = $request->query('usine', 'Toutes');
            
            $query = DB::table('distillations as d')
                ->join('expeditions as e', 'd.expedition_id', '=', 'e.id')
                ->join('fiche_livraisons as fl', 'e.fiche_livraison_id', '=', 'fl.id')
                ->join('utilisateurs as u', 'fl.distilleur_id', '=', 'u.id')
                ->where('d.statut', 'termine')
                ->select(
                    'u.id as distilleur_id',
                    'u.nom',
                    'u.prenom',
                    DB::raw('COUNT(DISTINCT d.id) as nombre_distillations'),
                    DB::raw('SUM(d.quantite_resultat) as quantite_produite'),
                    DB::raw('AVG((d.quantite_resultat / d.quantite_traitee) * 100) as rendement_moyen')
                );
            
            if ($usineFilter !== 'Toutes') {
                $query->where('d.usine', $usineFilter);
            }
            
            $statistiques = $query->groupBy('u.id', 'u.nom', 'u.prenom')
                ->orderByDesc('quantite_produite')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'statistiques' => $statistiques,
                    'usine_selectionnee' => $usineFilter,
                    'nombre_distilleurs' => $statistiques->count()
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques par distilleur',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Récupérer les alertes stock bas
     */
    public function getAlertesStockBas(Request $request): JsonResponse
    {
        try {
            $usineFilter = $request->query('usine', 'Toutes');
            $seuilStockBas = 10; // 10 kg comme seuil d'alerte
            
            // Alertes pour huile essentielle
            $stockHe = $this->getStockHuileEssentielle($request)->getData();
            $alertesHe = [];
            
            if ($stockHe->success) {
                foreach ($stockHe->data->statistiques as $type => $stats) {
                    if ($stats['stock_disponible'] < $seuilStockBas) {
                        $alertesHe[] = [
                            'type' => $type,
                            'stock_actuel' => $stats['stock_disponible'],
                            'seuil' => $seuilStockBas,
                            'message' => "Stock bas pour $type : {$stats['stock_disponible']} kg (seuil: {$seuilStockBas} kg)"
                        ];
                    }
                }
            }
            
            // Alertes pour matière première
            $resume = $this->getResumeProduction($request)->getData();
            $alertesMp = [];
            
            if ($resume->success) {
                foreach ($resume->data->resume as $type => $stats) {
                    if ($stats['stock_restant'] < $seuilStockBas) {
                        $alertesMp[] = [
                            'type' => $type,
                            'stock_actuel' => $stats['stock_restant'],
                            'seuil' => $seuilStockBas,
                            'message' => "Stock bas pour matière première $type : {$stats['stock_restant']} kg"
                        ];
                    }
                }
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'alertes_huile_essentielle' => $alertesHe,
                    'alertes_matiere_premiere' => $alertesMp,
                    'nombre_alertes' => count($alertesHe) + count($alertesMp),
                    'usine_selectionnee' => $usineFilter,
                    'date_verification' => Carbon::now()->format('d/m/Y H:i:s')
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des alertes',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }
}