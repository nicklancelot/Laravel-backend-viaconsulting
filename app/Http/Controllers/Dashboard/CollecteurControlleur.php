<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\MatierePremiere\PVReception;
use App\Models\MatierePremiere\Stockpv;
use App\Models\MatierePremiere\Facturation;
use App\Models\MatierePremiere\Impaye;
use App\Models\Localisation;
use App\Models\Utilisateur;
use Carbon\Carbon;

class CollecteurControlleur extends Controller
{
    /**
     * Tableau de bord 1 - Analyse des performances
     */
    public function tableauDeBord(Request $request)
    {
        try {
            $periode = $request->input('periode', 'mois'); // semaine, mois, annee
            
            // 1. ANALYSE DES PERFORMANCES - Totaux par type (FG, CG, GG, HE)
            $performances = $this->getAnalysePerformances($periode);
            
            // 2. MEILLEUR PERFORMER - Localisations avec plus de production
            $meilleursPerformers = $this->getMeilleursPerformers($periode);
            
            // 3. TENDANCES
            $tendances = $this->getTendances($periode);
            
            // 4. TOTAUX PAR LOCALISATION
            $totauxParLocalisation = $this->getTotauxParLocalisation();
            
            // 5. STOCK GLOBAL (selon votre table stockpvs)
            $stockGlobal = $this->getStockGlobal();
            
            // 6. VALEUR MARCHANDE (basé sur les prix des PV)
            $valeurMarchande = $this->getValeurMarchande();
            
            // 7. STATISTIQUES FINANCIÈRES (Facturations et Impayés)
            $statistiquesFinancieres = $this->getStatistiquesFinancieres($periode);
            
            return response()->json([
                'success' => true,
                'periode' => $periode,
                'periode_label' => $this->getPeriodeLabel($periode),
                'date_debut' => $this->getDateDebutPeriode($periode),
                'date_fin' => Carbon::now()->format('Y-m-d'),
                'data' => [
                    'analyse_performances' => $performances,
                    'meilleurs_performers' => $meilleursPerformers,
                    'tendances' => $tendances,
                    'totaux_par_localisation' => $totauxParLocalisation,
                    'stock_global' => $stockGlobal,
                    'valeur_marchande' => $valeurMarchande,
                    'statistiques_financieres' => $statistiquesFinancieres
                ],
                'timestamp' => now()->format('Y-m-d H:i:s')
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la génération du tableau de bord',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * 1. ANALYSE DES PERFORMANCES - Totaux par type (FG, CG, GG, HE)
     * Seulement pour les fiches payées
     */
    private function getAnalysePerformances($periode)
    {
        $dateDebut = $this->getDateDebutPeriode($periode);
        
        // 1.1. STATISTIQUES PV (MATIÈRES PREMIÈRES) - Types: FG, CG, GG
        $resultatsPV = $this->getStatistiquesPV($dateDebut);
        
        // 1.2. STATISTIQUES HUILE ESSENTIELLE (HE)
        $resultatsHE = $this->getStatistiquesHuileEssentielle($dateDebut);
        
        // Combiner tous les résultats
        $allTypes = collect();
        
        // Ajouter les PV (Matières Premières)
        foreach ($resultatsPV as $pv) {
            $allTypes->push((object)[
                'type' => $pv->type,
                'nom' => $this->getNomType($pv->type),
                'categorie' => 'matiere_premiere',
                'total_poids_kg' => $pv->total_poids_kg,
                'nombre_pv' => $pv->nombre_pv,
                'total_valeur_ar' => $pv->total_valeur_ar
            ]);
        }
        
        // Ajouter les Huiles Essentielles (HE)
        if ($resultatsHE->count() > 0) {
            $allTypes->push((object)[
                'type' => 'HE',
                'nom' => 'Huile Essentielle - Feuilles',
                'categorie' => 'huile_essentielle',
                'total_poids_kg' => $resultatsHE->sum('total_poids_kg'),
                'nombre_pv' => $resultatsHE->sum('nombre_fiches'),
                'total_valeur_ar' => $resultatsHE->sum('total_valeur_ar')
            ]);
        }
        
        // Calculer les totaux
        $totalGeneral = [
            'poids_kg' => $allTypes->sum('total_poids_kg'),
            'valeur_ar' => $allTypes->sum('total_valeur_ar'),
            'nombre_fiches' => $allTypes->sum('nombre_pv')
        ];
        
        $data = [];
        
        foreach ($allTypes as $typeData) {
            $code = $typeData->type;
            
            // Obtenir le prix moyen pour ce type
            $prixMoyen = $this->getPrixMoyenParType($code, $dateDebut);
            
            $data[$code] = [
                'nom' => $typeData->nom,
                'code' => $code,
                'categorie' => $typeData->categorie,
                'poids_kg' => (float)$typeData->total_poids_kg,
                'poids_formate' => number_format($typeData->total_poids_kg, 1) . ' kg',
                'nombre_fiches' => $typeData->nombre_pv,
                'valeur_ar' => (float)$typeData->total_valeur_ar,
                'valeur_formate' => number_format($typeData->total_valeur_ar, 0, ',', ' ') . ' Ar',
                'prix_moyen_kg' => number_format($prixMoyen, 0, ',', ' ') . ' Ar/kg',
                'prix_moyen_numerique' => $prixMoyen
            ];
        }
        
        // Calcul des pourcentages
        foreach ($data as $code => $info) {
            if ($totalGeneral['poids_kg'] > 0) {
                $pourcentage = ($info['poids_kg'] / $totalGeneral['poids_kg']) * 100;
                $data[$code]['pourcentage_poids'] = round($pourcentage, 1) . '%';
                $data[$code]['pourcentage_poids_numerique'] = round($pourcentage, 1);
            } else {
                $data[$code]['pourcentage_poids'] = '0%';
                $data[$code]['pourcentage_poids_numerique'] = 0;
            }
            
            if ($totalGeneral['valeur_ar'] > 0) {
                $pourcentageValeur = ($info['valeur_ar'] / $totalGeneral['valeur_ar']) * 100;
                $data[$code]['pourcentage_valeur'] = round($pourcentageValeur, 1) . '%';
            } else {
                $data[$code]['pourcentage_valeur'] = '0%';
            }
        }
        
        // Calculer les totaux par catégorie
        $parCategorie = [
            'matiere_premiere' => [
                'poids_kg' => 0,
                'valeur_ar' => 0,
                'nombre_fiches' => 0
            ],
            'huile_essentielle' => [
                'poids_kg' => 0,
                'valeur_ar' => 0,
                'nombre_fiches' => 0
            ]
        ];
        
        foreach ($data as $code => $info) {
            $categorie = $info['categorie'];
            if (isset($parCategorie[$categorie])) {
                $parCategorie[$categorie]['poids_kg'] += $info['poids_kg'];
                $parCategorie[$categorie]['valeur_ar'] += $info['valeur_ar'];
                $parCategorie[$categorie]['nombre_fiches'] += $info['nombre_fiches'];
            }
        }
        
        return [
            'types' => $data,
            'total_general' => [
                'poids_kg' => $totalGeneral['poids_kg'],
                'poids_formate' => number_format($totalGeneral['poids_kg'], 1) . ' kg',
                'valeur_ar' => $totalGeneral['valeur_ar'],
                'valeur_formate' => number_format($totalGeneral['valeur_ar'], 0, ',', ' ') . ' Ar',
                'nombre_fiches' => $totalGeneral['nombre_fiches']
            ],
            'par_categorie' => $parCategorie,
            'periode' => $periode
        ];
    }
    
    /**
     * Statistiques PV (Matières Premières)
     */
    private function getStatistiquesPV($dateDebut)
    {
        return PVReception::select([
                'type',
                DB::raw('SUM(poids_net) as total_poids_kg'),
                DB::raw('COUNT(*) as nombre_pv'),
                DB::raw('SUM(prix_total) as total_valeur_ar')
            ])
            ->where('statut', 'paye')
            ->whereDate('date_reception', '>=', $dateDebut)
            ->groupBy('type')
            ->get();
    }
    
    /**
     * Statistiques Huile Essentielle (HE)
     */
    private function getStatistiquesHuileEssentielle($dateDebut)
    {
        // Vérifier si la table des fiches de réception huile essentielle existe
        // Selon vos migrations, c'est 'fiche_receptions' ou 'h_e_fiche_receptions'
        $tableName = 'fiche_receptions'; // Modifier selon votre base de données
        
        if (DB::getSchemaBuilder()->hasTable($tableName)) {
            return DB::table($tableName)
                ->select([
                    DB::raw('SUM(poids_net) as total_poids_kg'),
                    DB::raw('COUNT(*) as nombre_fiches'),
                    DB::raw('SUM(prix_total) as total_valeur_ar')
                ])
                ->where('statut', 'payé')
                ->whereDate('date_reception', '>=', $dateDebut)
                ->get();
        }
        
        // Retourner une collection vide si la table n'existe pas
        return collect([(object)[
            'total_poids_kg' => 0,
            'nombre_fiches' => 0,
            'total_valeur_ar' => 0
        ]]);
    }
    
    /**
     * 2. MEILLEUR PERFORMER - Localisations avec plus de production (tous types)
     */
    private function getMeilleursPerformers($periode)
    {
        $dateDebut = $this->getDateDebutPeriode($periode);
        
        // Récupérer les localisations avec leurs totaux de production (tous types confondus)
        $performers = DB::table('p_v_receptions as pv')
            ->join('utilisateurs as u', 'pv.utilisateur_id', '=', 'u.id')
            ->join('localisations as l', 'u.localisation_id', '=', 'l.id')
            ->select([
                'l.id',
                'l.Nom as localisation',
                DB::raw('SUM(pv.poids_net) as total_poids_kg'),
                DB::raw('COUNT(DISTINCT u.id) as nombre_collecteurs'),
                DB::raw('COUNT(pv.id) as nombre_pv'),
                DB::raw('SUM(pv.prix_total) as total_valeur_ar')
            ])
            ->where('pv.statut', 'paye')
            ->whereDate('pv.date_reception', '>=', $dateDebut)
            ->groupBy('l.id', 'l.Nom')
            ->orderBy('total_poids_kg', 'DESC')
            ->limit(5)
            ->get();
        
        // Ajouter aussi les performances des huiles essentielles si disponibles
        $performersHE = $this->getPerformersHuileEssentielle($dateDebut);
        
        // Fusionner les résultats
        $allPerformers = collect();
        
        foreach ($performers as $performer) {
            $allPerformers->push((object)[
                'id' => $performer->id,
                'localisation' => $performer->localisation,
                'total_poids_kg' => $performer->total_poids_kg,
                'nombre_collecteurs' => $performer->nombre_collecteurs,
                'nombre_pv' => $performer->nombre_pv,
                'total_valeur_ar' => $performer->total_valeur_ar
            ]);
        }
        
        $data = [];
        foreach ($allPerformers as $performer) {
            $data[] = [
                'localisation_id' => $performer->id,
                'localisation' => $performer->localisation,
                'total_poids_kg' => (float)$performer->total_poids_kg,
                'total_poids_formate' => number_format($performer->total_poids_kg, 1) . ' kg',
                'nombre_collecteurs' => $performer->nombre_collecteurs,
                'nombre_pv' => $performer->nombre_pv,
                'total_valeur_ar' => (float)$performer->total_valeur_ar,
                'total_valeur_formate' => number_format($performer->total_valeur_ar, 0, ',', ' ') . ' Ar'
            ];
        }
        
        // Classement
        foreach ($data as $index => $item) {
            $data[$index]['classement'] = $index + 1;
            $data[$index]['medaille'] = $this->getMedaille($index + 1);
        }
        
        return [
            'performers' => $data,
            'nombre_total' => count($data)
        ];
    }
    
    private function getPerformersHuileEssentielle($dateDebut)
    {
        $tableName = 'fiche_receptions'; // Modifier selon votre base de données
        
        if (!DB::getSchemaBuilder()->hasTable($tableName)) {
            return collect();
        }
        
        return DB::table($tableName . ' as f')
            ->join('utilisateurs as u', 'f.utilisateur_id', '=', 'u.id')
            ->join('localisations as l', 'u.localisation_id', '=', 'l.id')
            ->select([
                'l.id',
                'l.Nom as localisation',
                DB::raw('SUM(f.poids_net) as total_poids_kg'),
                DB::raw('COUNT(DISTINCT u.id) as nombre_collecteurs'),
                DB::raw('COUNT(f.id) as nombre_pv'),
                DB::raw('SUM(f.prix_total) as total_valeur_ar')
            ])
            ->where('f.statut', 'payé')
            ->whereDate('f.date_reception', '>=', $dateDebut)
            ->groupBy('l.id', 'l.Nom')
            ->get();
    }
    
    /**
     * 3. TENDANCES (tous types confondus)
     */
    private function getTendances($periode)
    {
        $tendances = [];
        
        // Tendance mensuelle (12 derniers mois)
        $tendances['mensuelle'] = $this->getTendanceMensuelle();
        
        // Tendance hebdomadaire (8 dernières semaines)
        $tendances['hebdomadaire'] = $this->getTendanceHebdomadaire();
        
        // Tendance journalière (30 derniers jours)
        $tendances['journaliere'] = $this->getTendanceJournaliere();
        
        // Statistiques de tendance
        $dateDebut = $this->getDateDebutPeriode($periode);
        $tendances['statistiques'] = $this->getStatistiquesTendance($dateDebut);
        
        return $tendances;
    }
    
    private function getTendanceMensuelle()
    {
        $tendances = [];
        $date = Carbon::now()->subMonths(11)->startOfMonth();
        
        for ($i = 0; $i < 12; $i++) {
            $debutMois = $date->copy();
            $finMois = $date->copy()->endOfMonth();
            
            // PV Matières Premières
            $statsPV = DB::table('p_v_receptions')
                ->select([
                    DB::raw('SUM(poids_net) as poids_kg'),
                    DB::raw('COUNT(*) as nombre_pv'),
                    DB::raw('SUM(prix_total) as valeur_ar'),
                    DB::raw('COUNT(DISTINCT utilisateur_id) as nombre_collecteurs')
                ])
                ->where('statut', 'paye')
                ->whereBetween('date_reception', [$debutMois, $finMois])
                ->first();
            
            // Huile Essentielle
            $statsHE = $this->getStatsTendanceHuileEssentielle($debutMois, $finMois);
            
            $poidsTotal = ($statsPV->poids_kg ?? 0) + ($statsHE->poids_kg ?? 0);
            $nombreFichesTotal = ($statsPV->nombre_pv ?? 0) + ($statsHE->nombre_fiches ?? 0);
            $valeurTotal = ($statsPV->valeur_ar ?? 0) + ($statsHE->valeur_ar ?? 0);
            
            $tendances[] = [
                'periode' => $date->format('M Y'),
                'mois' => $date->month,
                'annee' => $date->year,
                'poids_kg' => (float)$poidsTotal,
                'nombre_fiches' => $nombreFichesTotal,
                'valeur_ar' => (float)$valeurTotal,
                'nombre_collecteurs' => $statsPV->nombre_collecteurs ?? 0,
                'debut' => $debutMois->format('Y-m-d'),
                'fin' => $finMois->format('Y-m-d'),
                'details' => [
                    'matiere_premiere' => [
                        'poids_kg' => (float)($statsPV->poids_kg ?? 0),
                        'nombre_fiches' => $statsPV->nombre_pv ?? 0,
                        'valeur_ar' => (float)($statsPV->valeur_ar ?? 0)
                    ],
                    'huile_essentielle' => [
                        'poids_kg' => (float)($statsHE->poids_kg ?? 0),
                        'nombre_fiches' => $statsHE->nombre_fiches ?? 0,
                        'valeur_ar' => (float)($statsHE->valeur_ar ?? 0)
                    ]
                ]
            ];
            
            $date->addMonth();
        }
        
        return $tendances;
    }
    
    private function getStatsTendanceHuileEssentielle($debut, $fin)
    {
        $tableName = 'fiche_receptions'; // Modifier selon votre base de données
        
        if (!DB::getSchemaBuilder()->hasTable($tableName)) {
            return (object)[
                'poids_kg' => 0,
                'nombre_fiches' => 0,
                'valeur_ar' => 0
            ];
        }
        
        return DB::table($tableName)
            ->select([
                DB::raw('SUM(poids_net) as poids_kg'),
                DB::raw('COUNT(*) as nombre_fiches'),
                DB::raw('SUM(prix_total) as valeur_ar')
            ])
            ->where('statut', 'payé')
            ->whereBetween('date_reception', [$debut, $fin])
            ->first() ?? (object)[
                'poids_kg' => 0,
                'nombre_fiches' => 0,
                'valeur_ar' => 0
            ];
    }
    
    private function getTendanceHebdomadaire()
    {
        $tendances = [];
        $date = Carbon::now()->subWeeks(7)->startOfWeek();
        
        for ($i = 0; $i < 8; $i++) {
            $debutSemaine = $date->copy();
            $finSemaine = $date->copy()->endOfWeek();
            
            // PV Matières Premières
            $statsPV = DB::table('p_v_receptions')
                ->select([
                    DB::raw('SUM(poids_net) as poids_kg'),
                    DB::raw('COUNT(*) as nombre_pv'),
                    DB::raw('SUM(prix_total) as valeur_ar')
                ])
                ->where('statut', 'paye')
                ->whereBetween('date_reception', [$debutSemaine, $finSemaine])
                ->first();
            
            // Huile Essentielle
            $statsHE = $this->getStatsTendanceHuileEssentielle($debutSemaine, $finSemaine);
            
            $poidsTotal = ($statsPV->poids_kg ?? 0) + ($statsHE->poids_kg ?? 0);
            $nombreFichesTotal = ($statsPV->nombre_pv ?? 0) + ($statsHE->nombre_fiches ?? 0);
            $valeurTotal = ($statsPV->valeur_ar ?? 0) + ($statsHE->valeur_ar ?? 0);
            
            $tendances[] = [
                'periode' => 'Semaine ' . ($i + 1),
                'numero_semaine' => $date->weekOfYear,
                'poids_kg' => (float)$poidsTotal,
                'nombre_fiches' => $nombreFichesTotal,
                'valeur_ar' => (float)$valeurTotal,
                'debut' => $debutSemaine->format('Y-m-d'),
                'fin' => $finSemaine->format('Y-m-d'),
                'label_court' => $debutSemaine->format('d/m') . ' - ' . $finSemaine->format('d/m')
            ];
            
            $date->addWeek();
        }
        
        return $tendances;
    }
    
    private function getTendanceJournaliere()
    {
        $tendances = [];
        $date = Carbon::now()->subDays(29);
        
        for ($i = 0; $i < 30; $i++) {
            $jour = $date->copy();
            
            // PV Matières Premières
            $statsPV = DB::table('p_v_receptions')
                ->select([
                    DB::raw('SUM(poids_net) as poids_kg'),
                    DB::raw('COUNT(*) as nombre_pv'),
                    DB::raw('SUM(prix_total) as valeur_ar')
                ])
                ->where('statut', 'paye')
                ->whereDate('date_reception', $jour)
                ->first();
            
            // Huile Essentielle
            $statsHE = $this->getStatsTendanceHuileEssentielleJournalier($jour);
            
            $poidsTotal = ($statsPV->poids_kg ?? 0) + ($statsHE->poids_kg ?? 0);
            $nombreFichesTotal = ($statsPV->nombre_pv ?? 0) + ($statsHE->nombre_fiches ?? 0);
            $valeurTotal = ($statsPV->valeur_ar ?? 0) + ($statsHE->valeur_ar ?? 0);
            
            $tendances[] = [
                'date' => $jour->format('Y-m-d'),
                'jour' => $jour->format('d/m'),
                'nom_jour' => $jour->locale('fr')->dayName,
                'poids_kg' => (float)$poidsTotal,
                'nombre_fiches' => $nombreFichesTotal,
                'valeur_ar' => (float)$valeurTotal,
                'a_activite' => $poidsTotal > 0,
                'details' => [
                    'matiere_premiere' => [
                        'poids_kg' => (float)($statsPV->poids_kg ?? 0),
                        'nombre_fiches' => $statsPV->nombre_pv ?? 0,
                        'valeur_ar' => (float)($statsPV->valeur_ar ?? 0)
                    ],
                    'huile_essentielle' => [
                        'poids_kg' => (float)($statsHE->poids_kg ?? 0),
                        'nombre_fiches' => $statsHE->nombre_fiches ?? 0,
                        'valeur_ar' => (float)($statsHE->valeur_ar ?? 0)
                    ]
                ]
            ];
            
            $date->addDay();
        }
        
        return $tendances;
    }
    
    private function getStatsTendanceHuileEssentielleJournalier($jour)
    {
        $tableName = 'fiche_receptions'; // Modifier selon votre base de données
        
        if (!DB::getSchemaBuilder()->hasTable($tableName)) {
            return (object)[
                'poids_kg' => 0,
                'nombre_fiches' => 0,
                'valeur_ar' => 0
            ];
        }
        
        return DB::table($tableName)
            ->select([
                DB::raw('SUM(poids_net) as poids_kg'),
                DB::raw('COUNT(*) as nombre_fiches'),
                DB::raw('SUM(prix_total) as valeur_ar')
            ])
            ->where('statut', 'payé')
            ->whereDate('date_reception', $jour)
            ->first() ?? (object)[
                'poids_kg' => 0,
                'nombre_fiches' => 0,
                'valeur_ar' => 0
            ];
    }
    
    private function getStatistiquesTendance($dateDebut)
    {
        $periodePrecedenteDebut = Carbon::parse($dateDebut)->subDays(30);
        
        // Période actuelle
        $statsPVActuel = DB::table('p_v_receptions')
            ->select([
                DB::raw('SUM(poids_net) as poids_kg'),
                DB::raw('COUNT(*) as nombre_pv'),
                DB::raw('SUM(prix_total) as valeur_ar'),
                DB::raw('AVG(prix_unitaire) as prix_moyen_kg')
            ])
            ->where('statut', 'paye')
            ->whereDate('date_reception', '>=', $dateDebut)
            ->first();
        
        $statsHEActuel = $this->getStatsHuileEssentiellePeriode($dateDebut, Carbon::now());
        
        $poidsActuel = ($statsPVActuel->poids_kg ?? 0) + ($statsHEActuel->poids_kg ?? 0);
        $valeurActuel = ($statsPVActuel->valeur_ar ?? 0) + ($statsHEActuel->valeur_ar ?? 0);
        $nombreFichesActuel = ($statsPVActuel->nombre_pv ?? 0) + ($statsHEActuel->nombre_fiches ?? 0);
        
        // Période précédente
        $statsPVPrecedent = DB::table('p_v_receptions')
            ->select([
                DB::raw('SUM(poids_net) as poids_kg'),
                DB::raw('COUNT(*) as nombre_pv'),
                DB::raw('SUM(prix_total) as valeur_ar'),
                DB::raw('AVG(prix_unitaire) as prix_moyen_kg')
            ])
            ->where('statut', 'paye')
            ->whereBetween('date_reception', [$periodePrecedenteDebut, Carbon::parse($dateDebut)->subDay()])
            ->first();
        
        $statsHEPrecedent = $this->getStatsHuileEssentiellePeriode($periodePrecedenteDebut, Carbon::parse($dateDebut)->subDay());
        
        $poidsPrecedent = ($statsPVPrecedent->poids_kg ?? 0) + ($statsHEPrecedent->poids_kg ?? 0);
        
        $variation = 0;
        if ($poidsPrecedent > 0) {
            $variation = (($poidsActuel - $poidsPrecedent) / $poidsPrecedent) * 100;
        }
        
        return [
            'periode_actuelle' => [
                'poids_kg' => $poidsActuel,
                'nombre_fiches' => $nombreFichesActuel,
                'valeur_ar' => $valeurActuel,
                'prix_moyen_kg' => $nombreFichesActuel > 0 ? $valeurActuel / $poidsActuel : 0
            ],
            'periode_precedente' => [
                'poids_kg' => $poidsPrecedent,
                'nombre_fiches' => ($statsPVPrecedent->nombre_pv ?? 0) + ($statsHEPrecedent->nombre_fiches ?? 0),
                'valeur_ar' => ($statsPVPrecedent->valeur_ar ?? 0) + ($statsHEPrecedent->valeur_ar ?? 0),
                'prix_moyen_kg' => ($statsPVPrecedent->prix_moyen_kg ?? 0)
            ],
            'variation_pourcentage' => round($variation, 1),
            'variation_absolue_kg' => round($poidsActuel - $poidsPrecedent, 1),
            'variation_absolue_valeur' => round($valeurActuel - (($statsPVPrecedent->valeur_ar ?? 0) + ($statsHEPrecedent->valeur_ar ?? 0)), 0),
            'tendance' => $variation > 0 ? 'hausse' : ($variation < 0 ? 'baisse' : 'stable')
        ];
    }
    
    private function getStatsHuileEssentiellePeriode($debut, $fin)
    {
        $tableName = 'fiche_receptions'; // Modifier selon votre base de données
        
        if (!DB::getSchemaBuilder()->hasTable($tableName)) {
            return (object)[
                'poids_kg' => 0,
                'nombre_fiches' => 0,
                'valeur_ar' => 0
            ];
        }
        
        return DB::table($tableName)
            ->select([
                DB::raw('SUM(poids_net) as poids_kg'),
                DB::raw('COUNT(*) as nombre_fiches'),
                DB::raw('SUM(prix_total) as valeur_ar')
            ])
            ->where('statut', 'payé')
            ->whereBetween('date_reception', [$debut, $fin])
            ->first() ?? (object)[
                'poids_kg' => 0,
                'nombre_fiches' => 0,
                'valeur_ar' => 0
            ];
    }
    
    /**
     * 4. TOTAUX PAR LOCALISATION
     */
    private function getTotauxParLocalisation()
    {
        $localisations = Localisation::orderBy('Nom')->get();
        
        $data = [];
        
        foreach ($localisations as $localisation) {
            // Nombre de collecteurs dans cette localisation
            $nombreCollecteurs = Utilisateur::where('localisation_id', $localisation->id)
                ->where('role', 'collecteur')
                ->count();
            
            // Récupérer les statistiques pour cette localisation (PV Matières Premières)
            $statsPV = DB::table('p_v_receptions as pv')
                ->join('utilisateurs as u', 'pv.utilisateur_id', '=', 'u.id')
                ->select([
                    DB::raw('SUM(pv.poids_net) as poids_total_kg'),
                    DB::raw('SUM(pv.prix_total) as valeur_total_ar'),
                    DB::raw('COUNT(pv.id) as nombre_pv'),
                    DB::raw('COUNT(DISTINCT u.id) as collecteurs_actifs')
                ])
                ->where('u.localisation_id', $localisation->id)
                ->where('pv.statut', 'paye')
                ->first();
            
            // Récupérer les statistiques pour cette localisation (Huile Essentielle)
            $statsHE = $this->getStatsLocalisationHuileEssentielle($localisation->id);
            
            // Récupérer les détails par type (PV Matières Premières)
            $typesPV = DB::table('p_v_receptions as pv')
                ->join('utilisateurs as u', 'pv.utilisateur_id', '=', 'u.id')
                ->select([
                    'pv.type',
                    DB::raw('SUM(pv.poids_net) as poids_kg'),
                    DB::raw('AVG(pv.prix_unitaire) as prix_moyen')
                ])
                ->where('u.localisation_id', $localisation->id)
                ->where('pv.statut', 'paye')
                ->groupBy('pv.type')
                ->get()
                ->keyBy('type');
            
            $poidsTotal = ($statsPV->poids_total_kg ?? 0) + ($statsHE->poids_total_kg ?? 0);
            $valeurTotal = ($statsPV->valeur_total_ar ?? 0) + ($statsHE->valeur_total_ar ?? 0);
            $nombreFichesTotal = ($statsPV->nombre_pv ?? 0) + ($statsHE->nombre_fiches ?? 0);
            $collecteursActifs = $statsPV->collecteurs_actifs ?? 0;
            
            $statut = $collecteursActifs > 0 || ($statsHE->nombre_fiches ?? 0) > 0 ? 'Actif' : 'Inactif';
            
            $detailsTypes = [
                'FG' => [
                    'nom' => 'Feuilles',
                    'poids_kg' => (float)($typesPV->get('FG')->poids_kg ?? 0),
                    'poids_formate' => number_format($typesPV->get('FG')->poids_kg ?? 0, 1) . ' kg',
                    'prix_moyen' => (float)($typesPV->get('FG')->prix_moyen ?? 0),
                    'prix_formate' => number_format($typesPV->get('FG')->prix_moyen ?? 0, 0, ',', ' ') . ' Ar/kg'
                ],
                'CG' => [
                    'nom' => 'Griffes',
                    'poids_kg' => (float)($typesPV->get('CG')->poids_kg ?? 0),
                    'poids_formate' => number_format($typesPV->get('CG')->poids_kg ?? 0, 1) . ' kg',
                    'prix_moyen' => (float)($typesPV->get('CG')->prix_moyen ?? 0),
                    'prix_formate' => number_format($typesPV->get('CG')->prix_moyen ?? 0, 0, ',', ' ') . ' Ar/kg'
                ],
                'GG' => [
                    'nom' => 'Clous',
                    'poids_kg' => (float)($typesPV->get('GG')->poids_kg ?? 0),
                    'poids_formate' => number_format($typesPV->get('GG')->poids_kg ?? 0, 1) . ' kg',
                    'prix_moyen' => (float)($typesPV->get('GG')->prix_moyen ?? 0),
                    'prix_formate' => number_format($typesPV->get('GG')->prix_moyen ?? 0, 0, ',', ' ') . ' Ar/kg'
                ]
            ];
            
            // Ajouter les huiles essentielles aux détails
            if (($statsHE->poids_total_kg ?? 0) > 0) {
                $detailsTypes['HE'] = [
                    'nom' => 'Huile Essentielle - Feuilles',
                    'poids_kg' => (float)($statsHE->poids_total_kg ?? 0),
                    'poids_formate' => number_format($statsHE->poids_total_kg ?? 0, 1) . ' kg',
                    'prix_moyen' => ($statsHE->poids_total_kg ?? 0) > 0 ? 
                        (float)($statsHE->valeur_total_ar ?? 0) / (float)($statsHE->poids_total_kg ?? 0) : 0,
                    'prix_formate' => ($statsHE->poids_total_kg ?? 0) > 0 ? 
                        number_format(($statsHE->valeur_total_ar ?? 0) / ($statsHE->poids_total_kg ?? 0), 0, ',', ' ') . ' Ar/kg' : '0 Ar/kg'
                ];
            }
            
            $data[] = [
                'localisation_id' => $localisation->id,
                'nom' => $localisation->Nom,
                'statut' => $statut,
                'nombre_collecteurs' => $nombreCollecteurs,
                'collecteurs_actifs' => $collecteursActifs,
                'total_poids_kg' => (float)$poidsTotal,
                'total_poids_formate' => number_format($poidsTotal, 1) . ' kg',
                'total_valeur_ar' => (float)$valeurTotal,
                'total_valeur_formate' => number_format($valeurTotal, 0, ',', ' ') . ' Ar',
                'nombre_fiches' => $nombreFichesTotal,
                'details_types' => $detailsTypes,
                'details_categories' => [
                    'matiere_premiere' => [
                        'poids_kg' => (float)($statsPV->poids_total_kg ?? 0),
                        'valeur_ar' => (float)($statsPV->valeur_total_ar ?? 0),
                        'nombre_fiches' => $statsPV->nombre_pv ?? 0
                    ],
                    'huile_essentielle' => [
                        'poids_kg' => (float)($statsHE->poids_total_kg ?? 0),
                        'valeur_ar' => (float)($statsHE->valeur_total_ar ?? 0),
                        'nombre_fiches' => $statsHE->nombre_fiches ?? 0
                    ]
                ]
            ];
        }
        
        return $data;
    }
    
    private function getStatsLocalisationHuileEssentielle($localisationId)
    {
        $tableName = 'fiche_receptions'; // Modifier selon votre base de données
        
        if (!DB::getSchemaBuilder()->hasTable($tableName)) {
            return (object)[
                'poids_total_kg' => 0,
                'valeur_total_ar' => 0,
                'nombre_fiches' => 0,
                'collecteurs_actifs' => 0
            ];
        }
        
        return DB::table($tableName . ' as f')
            ->join('utilisateurs as u', 'f.utilisateur_id', '=', 'u.id')
            ->select([
                DB::raw('SUM(f.poids_net) as poids_total_kg'),
                DB::raw('SUM(f.prix_total) as valeur_total_ar'),
                DB::raw('COUNT(f.id) as nombre_fiches'),
                DB::raw('COUNT(DISTINCT u.id) as collecteurs_actifs')
            ])
            ->where('u.localisation_id', $localisationId)
            ->where('f.statut', 'payé')
            ->first() ?? (object)[
                'poids_total_kg' => 0,
                'valeur_total_ar' => 0,
                'nombre_fiches' => 0,
                'collecteurs_actifs' => 0
            ];
    }
    
    /**
     * 5. STOCK GLOBAL (selon votre table stockpvs)
     */
    private function getStockGlobal()
    {
        // Récupérer le stock global (utilisateur_id = null, niveau_stock = 'global')
        $stocks = Stockpv::whereNull('utilisateur_id')
            ->where('niveau_stock', 'global')
            ->get()
            ->keyBy('type_matiere');
        
        // Les types sont dynamiques, on prend ceux qui existent dans la base
        $typesExistants = $stocks->keys()->toArray();
        
        // Ajouter le type HE s'il n'existe pas dans stocks mais existe dans les fiches
        $tableName = 'fiche_receptions';
        if (DB::getSchemaBuilder()->hasTable($tableName)) {
            $hasHE = DB::table($tableName)
                ->where('statut', 'payé')
                ->exists();
            
            if ($hasHE && !in_array('HE', $typesExistants)) {
                $typesExistants[] = 'HE';
            }
        }
        
        $data = [];
        $totalGeneral = 0;
        
        foreach ($typesExistants as $code) {
            $stock = $stocks->get($code);
            
            if ($code === 'HE') {
                // Pour HE, on calcule le stock disponible depuis les fiches de réception
                $quantite = $this->getStockHuileEssentielle();
                $stockTotal = $quantite;
            } else {
                $quantite = $stock ? (float)$stock->stock_disponible : 0;
                $stockTotal = $stock ? (float)$stock->stock_total : 0;
            }
            
            $data[$code] = [
                'nom' => $this->getNomType($code),
                'code' => $code,
                'quantite_kg' => $quantite,
                'quantite_formate' => number_format($quantite, 1) . ' kg',
                'stock_total' => $stockTotal,
                'stock_total_formate' => number_format($stockTotal, 1) . ' kg',
                'stock_disponible' => $quantite,
                'stock_disponible_formate' => number_format($quantite, 1) . ' kg',
                'stock_utilise' => $stockTotal - $quantite,
                'stock_id' => $stock ? $stock->id : null,
                'derniere_mise_a_jour' => $stock ? $stock->updated_at->format('d/m/Y H:i') : null
            ];
            
            $totalGeneral += $quantite;
        }
        
        // Calcul des pourcentages
        foreach ($data as $code => $info) {
            if ($totalGeneral > 0) {
                $pourcentage = ($info['quantite_kg'] / $totalGeneral) * 100;
                $data[$code]['pourcentage'] = round($pourcentage, 1) . '%';
                $data[$code]['pourcentage_numerique'] = round($pourcentage, 1);
            } else {
                $data[$code]['pourcentage'] = '0%';
                $data[$code]['pourcentage_numerique'] = 0;
            }
        }
        
        return [
            'stocks' => $data,
            'total_general' => [
                'quantite_kg' => $totalGeneral,
                'quantite_formate' => number_format($totalGeneral, 1) . ' kg',
                'nombre_types' => count($typesExistants)
            ]
        ];
    }
    
    private function getStockHuileEssentielle()
    {
        $tableName = 'fiche_receptions'; // Modifier selon votre base de données
        
        if (!DB::getSchemaBuilder()->hasTable($tableName)) {
            return 0;
        }
        
        // Pour HE, on peut calculer le stock comme la somme des poids nets des fiches payées
        // qui n'ont pas été utilisées dans des livraisons
        return DB::table($tableName)
            ->where('statut', 'payé')
            ->sum('poids_net') ?? 0;
    }
    
    /**
     * 6. VALEUR MARCHANDE (basé sur les prix actuels)
     */
    private function getValeurMarchande()
    {
        // Récupérer les prix moyens actuels depuis les derniers PV
        $prixMoyensPV = DB::table('p_v_receptions')
            ->select([
                'type',
                DB::raw('AVG(prix_unitaire) as prix_moyen_kg')
            ])
            ->where('statut', 'paye')
            ->whereDate('date_reception', '>=', Carbon::now()->subMonth())
            ->groupBy('type')
            ->get()
            ->keyBy('type');
        
        // Récupérer le prix moyen pour HE
        $prixMoyenHE = $this->getPrixMoyenHuileEssentielle();
        
        // Récupérer les stocks globaux
        $stocks = Stockpv::whereNull('utilisateur_id')
            ->where('niveau_stock', 'global')
            ->get()
            ->keyBy('type_matiere');
        
        // Les types sont dynamiques
        $typesExistants = $stocks->keys()->toArray();
        
        // Ajouter HE s'il existe des fiches
        $tableName = 'fiche_receptions';
        if (DB::getSchemaBuilder()->hasTable($tableName)) {
            $hasHE = DB::table($tableName)
                ->where('statut', 'payé')
                ->exists();
            
            if ($hasHE && !in_array('HE', $typesExistants)) {
                $typesExistants[] = 'HE';
            }
        }
        
        $data = [];
        $totalValeur = 0;
        $totalQuantite = 0;
        
        foreach ($typesExistants as $code) {
            if ($code === 'HE') {
                $quantite = $this->getStockHuileEssentielle();
                $prix = $prixMoyenHE;
            } else {
                $stock = $stocks->get($code);
                $quantite = $stock ? (float)$stock->stock_disponible : 0;
                $prix = $prixMoyensPV->get($code) ? (float)$prixMoyensPV->get($code)->prix_moyen_kg : $this->getPrixParDefaut($code);
            }
            
            $valeur = $quantite * $prix;
            
            $data[$code] = [
                'nom' => $this->getNomType($code),
                'code' => $code,
                'quantite_kg' => $quantite,
                'quantite_formate' => number_format($quantite, 1) . ' kg',
                'prix_unitaire' => number_format($prix, 0, ',', ' ') . ' Ar/kg',
                'prix_unitaire_numerique' => $prix,
                'valeur_totale' => $valeur,
                'valeur_formate' => number_format($valeur, 0, ',', ' ') . ' Ar'
            ];
            
            $totalValeur += $valeur;
            $totalQuantite += $quantite;
        }
        
        // Calcul des pourcentages de valeur
        foreach ($data as $code => $info) {
            if ($totalValeur > 0) {
                $pourcentage = ($info['valeur_totale'] / $totalValeur) * 100;
                $data[$code]['pourcentage_valeur'] = round($pourcentage, 1) . '%';
                $data[$code]['pourcentage_valeur_numerique'] = round($pourcentage, 1);
            } else {
                $data[$code]['pourcentage_valeur'] = '0%';
                $data[$code]['pourcentage_valeur_numerique'] = 0;
            }
        }
        
        return [
            'valeurs' => $data,
            'total_general' => [
                'valeur_totale' => $totalValeur,
                'valeur_formate' => number_format($totalValeur, 0, ',', ' ') . ' Ar',
                'quantite_totale' => $totalQuantite,
                'quantite_formate' => number_format($totalQuantite, 1) . ' kg',
                'prix_moyen_global' => $totalQuantite > 0 ? round($totalValeur / $totalQuantite, 0) : 0,
                'prix_moyen_global_formate' => $totalQuantite > 0 ? number_format(round($totalValeur / $totalQuantite, 0), 0, ',', ' ') . ' Ar/kg' : '0 Ar/kg'
            ]
        ];
    }
    
    private function getPrixMoyenHuileEssentielle()
    {
        $tableName = 'fiche_receptions'; // Modifier selon votre base de données
        
        if (!DB::getSchemaBuilder()->hasTable($tableName)) {
            return 300; // Prix par défaut
        }
        
        $result = DB::table($tableName)
            ->select([
                DB::raw('AVG(prix_unitaire) as prix_moyen_kg')
            ])
            ->where('statut', 'payé')
            ->whereDate('date_reception', '>=', Carbon::now()->subMonth())
            ->first();
        
        return $result ? (float)$result->prix_moyen_kg : 300;
    }
    
    /**
     * 7. STATISTIQUES FINANCIÈRES
     */
    private function getStatistiquesFinancieres($periode)
    {
        $dateDebut = $this->getDateDebutPeriode($periode);
        
        // Facturations
        $facturations = Facturation::select([
                DB::raw('SUM(montant_total) as total_facture'),
                DB::raw('SUM(montant_paye) as total_paye'),
                DB::raw('COUNT(*) as nombre_factures'),
                DB::raw('AVG(montant_total) as moyenne_facture')
            ])
            ->whereDate('date_facturation', '>=', $dateDebut)
            ->first();
        
        // Impayés
        $impayes = Impaye::select([
                DB::raw('SUM(montant_total) as total_impaye'),
                DB::raw('SUM(montant_paye) as total_paye_impaye'),
                DB::raw('COUNT(*) as nombre_impayes'),
                DB::raw('AVG(montant_total) as moyenne_impaye')
            ])
            ->whereDate('date_facturation', '>=', $dateDebut)
            ->first();
        
        // PV non payés
        $pvNonPayes = PVReception::where('statut', 'non_paye')
            ->whereDate('date_reception', '>=', $dateDebut)
            ->select([
                DB::raw('SUM(prix_total) as total_dette'),
                DB::raw('SUM(dette_fournisseur) as dette_restante'),
                DB::raw('COUNT(*) as nombre_pv_non_payes')
            ])
            ->first();
        
        return [
            'facturations' => [
                'total_facture' => (float)($facturations->total_facture ?? 0),
                'total_facture_formate' => number_format($facturations->total_facture ?? 0, 0, ',', ' ') . ' Ar',
                'total_paye' => (float)($facturations->total_paye ?? 0),
                'total_paye_formate' => number_format($facturations->total_paye ?? 0, 0, ',', ' ') . ' Ar',
                'reste_a_payer' => (float)($facturations->total_facture ?? 0) - (float)($facturations->total_paye ?? 0),
                'nombre_factures' => $facturations->nombre_factures ?? 0,
                'moyenne_facture' => (float)($facturations->moyenne_facture ?? 0),
                'moyenne_facture_formate' => number_format($facturations->moyenne_facture ?? 0, 0, ',', ' ') . ' Ar'
            ],
            'impayes' => [
                'total_impaye' => (float)($impayes->total_impaye ?? 0),
                'total_impaye_formate' => number_format($impayes->total_impaye ?? 0, 0, ',', ' ') . ' Ar',
                'total_paye' => (float)($impayes->total_paye_impaye ?? 0),
                'total_paye_formate' => number_format($impayes->total_paye_impaye ?? 0, 0, ',', ' ') . ' Ar',
                'reste_a_payer' => (float)($impayes->total_impaye ?? 0) - (float)($impayes->total_paye_impaye ?? 0),
                'nombre_impayes' => $impayes->nombre_impayes ?? 0,
                'moyenne_impaye' => (float)($impayes->moyenne_impaye ?? 0)
            ],
            'dettes' => [
                'total_dette' => (float)($pvNonPayes->total_dette ?? 0),
                'total_dette_formate' => number_format($pvNonPayes->total_dette ?? 0, 0, ',', ' ') . ' Ar',
                'dette_restante' => (float)($pvNonPayes->dette_restante ?? 0),
                'dette_restante_formate' => number_format($pvNonPayes->dette_restante ?? 0, 0, ',', ' ') . ' Ar',
                'nombre_pv_non_payes' => $pvNonPayes->nombre_pv_non_payes ?? 0
            ],
            'periode' => $periode
        ];
    }
    
    /**
     * Méthodes utilitaires
     */
    private function getDateDebutPeriode($periode)
    {
        switch ($periode) {
            case 'semaine':
                return Carbon::now()->subWeek()->startOfDay()->format('Y-m-d');
            case 'mois':
                return Carbon::now()->subMonth()->startOfDay()->format('Y-m-d');
            case 'annee':
                return Carbon::now()->subYear()->startOfDay()->format('Y-m-d');
            default:
                return Carbon::now()->subMonth()->startOfDay()->format('Y-m-d');
        }
    }
    
    private function getPeriodeLabel($periode)
    {
        $labels = [
            'semaine' => 'Semaine dernière',
            'mois' => 'Mois dernier',
            'annee' => 'Année dernière'
        ];
        
        return $labels[$periode] ?? 'Période personnalisée';
    }
    
    private function getMedaille($position)
    {
        switch ($position) {
            case 1: return '🥇';
            case 2: return '🥈';
            case 3: return '🥉';
            default: return '🏅';
        }
    }
    
    private function getPrixParDefaut($type)
    {
        $prix = [
            'FG' => 150,   // Feuilles
            'CG' => 200,   // Griffes
            'GG' => 180,   // Clous
            'HE' => 300    // Huile Essentielle
        ];
        
        return $prix[$type] ?? 100;
    }
    
    private function getNomType($type)
    {
        $noms = [
            'FG' => 'Feuilles',
            'CG' => 'Griffes',
            'GG' => 'Clous',
            'HE' => 'Huile Essentielle - Feuilles'
        ];
        
        return $noms[$type] ?? $type;
    }
    
    private function getPrixMoyenParType($type, $dateDebut)
    {
        if ($type === 'HE') {
            return $this->getPrixMoyenHuileEssentielle();
        }
        
        $result = DB::table('p_v_receptions')
            ->select([
                DB::raw('AVG(prix_unitaire) as prix_moyen_kg')
            ])
            ->where('statut', 'paye')
            ->where('type', $type)
            ->whereDate('date_reception', '>=', $dateDebut)
            ->first();
        
        return $result ? (float)$result->prix_moyen_kg : $this->getPrixParDefaut($type);
    }
    
    /**
     * Méthode pour obtenir des statistiques rapides
     */
    public function statsRapides()
    {
        try {
            // Date d'aujourd'hui
            $aujourdhui = Carbon::today();
            
            // Total PV payés aujourd'hui
            $pvsAujourdhui = PVReception::where('statut', 'paye')
                ->whereDate('date_reception', $aujourdhui)
                ->count();
            
            // Poids total aujourd'hui
            $poidsAujourdhui = PVReception::where('statut', 'paye')
                ->whereDate('date_reception', $aujourdhui)
                ->sum('poids_net');
            
            // Valeur totale aujourd'hui
            $valeurAujourdhui = PVReception::where('statut', 'paye')
                ->whereDate('date_reception', $aujourdhui)
                ->sum('prix_total');
            
            // Nombre de collecteurs actifs (ceux qui ont créé des PV aujourd'hui)
            $collecteursActifsAujourdhui = DB::table('p_v_receptions')
                ->whereDate('date_reception', $aujourdhui)
                ->where('statut', 'paye')
                ->distinct()
                ->count('utilisateur_id');
            
            // Nombre total de collecteurs
            $collecteursTotaux = Utilisateur::where('role', 'collecteur')->count();
            
            // Localisations actives aujourd'hui
            $localisationsActivesAujourdhui = DB::table('p_v_receptions as pv')
                ->join('utilisateurs as u', 'pv.utilisateur_id', '=', 'u.id')
                ->join('localisations as l', 'u.localisation_id', '=', 'l.id')
                ->whereDate('pv.date_reception', $aujourdhui)
                ->where('pv.statut', 'paye')
                ->distinct()
                ->count('l.id');
            
            // Facturations aujourd'hui
            $facturationsAujourdhui = Facturation::whereDate('date_facturation', $aujourdhui)
                ->count();
            
            // Montant des facturations aujourd'hui
            $montantFacturationsAujourdhui = Facturation::whereDate('date_facturation', $aujourdhui)
                ->sum('montant_total');
            
            return response()->json([
                'success' => true,
                'date' => $aujourdhui->format('d/m/Y'),
                'stats' => [
                    'pvs_aujourdhui' => $pvsAujourdhui,
                    'poids_aujourdhui' => [
                        'kg' => (float)$poidsAujourdhui,
                        'formate' => number_format($poidsAujourdhui, 1) . ' kg'
                    ],
                    'valeur_aujourdhui' => [
                        'ar' => (float)$valeurAujourdhui,
                        'formate' => number_format($valeurAujourdhui, 0, ',', ' ') . ' Ar'
                    ],
                    'collecteurs_actifs_aujourdhui' => $collecteursActifsAujourdhui,
                    'collecteurs_totaux' => $collecteursTotaux,
                    'pourcentage_collecteurs_actifs' => $collecteursTotaux > 0 ? 
                        round(($collecteursActifsAujourdhui / $collecteursTotaux) * 100, 1) : 0,
                    'localisations_actives_aujourdhui' => $localisationsActivesAujourdhui,
                    'facturations_aujourdhui' => $facturationsAujourdhui,
                    'montant_facturations_aujourdhui' => [
                        'ar' => (float)$montantFacturationsAujourdhui,
                        'formate' => number_format($montantFacturationsAujourdhui, 0, ',', ' ') . ' Ar'
                    ]
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des stats rapides',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Méthode pour obtenir les détails d'une localisation spécifique
     */
    public function detailsLocalisation($localisationId)
    {
        try {
            $localisation = Localisation::findOrFail($localisationId);
            
            // Récupérer directement les données avec des requêtes brutes
            // 1. Collecteurs de cette localisation avec leurs statistiques
            $collecteurs = DB::table('utilisateurs as u')
                ->leftJoin('p_v_receptions as pv', function($join) {
                    $join->on('u.id', '=', 'pv.utilisateur_id')
                         ->where('pv.statut', 'paye');
                })
                ->select([
                    'u.id',
                    'u.nom',
                    'u.prenom',
                    'u.numero',
                    'u.role',
                    'u.code_collecteur',
                    DB::raw('COUNT(pv.id) as pv_payes'),
                    DB::raw('COALESCE(SUM(pv.poids_net), 0) as poids_total'),
                    DB::raw('COALESCE(SUM(pv.prix_total), 0) as valeur_total')
                ])
                ->where('u.localisation_id', $localisationId)
                ->where('u.role', 'collecteur')
                ->groupBy('u.id', 'u.nom', 'u.prenom', 'u.numero', 'u.role', 'u.code_collecteur')
                ->orderBy('poids_total', 'DESC')
                ->get();
            
            // 2. Statistiques par type pour cette localisation
            $statsParType = DB::table('p_v_receptions as pv')
                ->join('utilisateurs as u', 'pv.utilisateur_id', '=', 'u.id')
                ->select([
                    'pv.type',
                    DB::raw('COALESCE(SUM(pv.poids_net), 0) as poids_kg'),
                    DB::raw('COALESCE(SUM(pv.prix_total), 0) as valeur_ar'),
                    DB::raw('COUNT(pv.id) as nombre_pv'),
                    DB::raw('COALESCE(AVG(pv.prix_unitaire), 0) as prix_moyen')
                ])
                ->where('u.localisation_id', $localisationId)
                ->where('pv.statut', 'paye')
                ->groupBy('pv.type')
                ->get();
            
            // 3. Statistiques pour HE dans cette localisation
            $statsHE = $this->getStatsLocalisationHuileEssentielleDetails($localisationId);
            
            // 4. Tendance mensuelle pour cette localisation (6 derniers mois)
            $tendanceMensuelle = DB::table('p_v_receptions as pv')
                ->join('utilisateurs as u', 'pv.utilisateur_id', '=', 'u.id')
                ->select([
                    DB::raw("DATE_FORMAT(pv.date_reception, '%Y-%m') as mois"),
                    DB::raw('COALESCE(SUM(pv.poids_net), 0) as poids_kg'),
                    DB::raw('COUNT(pv.id) as nombre_pv'),
                    DB::raw('COALESCE(SUM(pv.prix_total), 0) as valeur_ar')
                ])
                ->where('u.localisation_id', $localisationId)
                ->where('pv.statut', 'paye')
                ->where('pv.date_reception', '>=', Carbon::now()->subMonths(5)->startOfMonth())
                ->groupBy(DB::raw("DATE_FORMAT(pv.date_reception, '%Y-%m')"))
                ->orderBy('mois', 'ASC')
                ->get();
            
            // 5. Statistiques générales de la localisation
            $statsGenerales = DB::table('p_v_receptions as pv')
                ->join('utilisateurs as u', 'pv.utilisateur_id', '=', 'u.id')
                ->select([
                    DB::raw('COALESCE(SUM(pv.poids_net), 0) as total_poids'),
                    DB::raw('COALESCE(SUM(pv.prix_total), 0) as total_valeur'),
                    DB::raw('COUNT(pv.id) as total_pv'),
                    DB::raw('COALESCE(AVG(pv.prix_unitaire), 0) as prix_moyen_global'),
                    DB::raw('MIN(pv.date_reception) as date_premier_pv'),
                    DB::raw('MAX(pv.date_reception) as date_dernier_pv')
                ])
                ->where('u.localisation_id', $localisationId)
                ->where('pv.statut', 'paye')
                ->first();
            
            // 6. Meilleurs collecteurs de cette localisation (top 3)
            $meilleursCollecteurs = DB::table('p_v_receptions as pv')
                ->join('utilisateurs as u', 'pv.utilisateur_id', '=', 'u.id')
                ->select([
                    'u.id',
                    'u.nom',
                    'u.prenom',
                    DB::raw('COALESCE(SUM(pv.poids_net), 0) as poids_total'),
                    DB::raw('COALESCE(SUM(pv.prix_total), 0) as valeur_total'),
                    DB::raw('COUNT(pv.id) as nombre_pv')
                ])
                ->where('u.localisation_id', $localisationId)
                ->where('pv.statut', 'paye')
                ->groupBy('u.id', 'u.nom', 'u.prenom')
                ->orderBy('poids_total', 'DESC')
                ->limit(3)
                ->get();
            
            // 7. Types de matière avec leurs pourcentages
            $totalPoidsLocalisation = ($statsGenerales->total_poids ?? 0) + ($statsHE->poids_kg ?? 0);
            $typesAvecPourcentages = [];
            
            foreach ($statsParType as $stat) {
                $pourcentage = $totalPoidsLocalisation > 0 ? 
                    round(($stat->poids_kg / $totalPoidsLocalisation) * 100, 1) : 0;
                
                $typesAvecPourcentages[] = [
                    'type' => $stat->type,
                    'nom' => $this->getNomType($stat->type),
                    'poids_kg' => (float)$stat->poids_kg,
                    'poids_formate' => number_format($stat->poids_kg, 1) . ' kg',
                    'valeur_ar' => (float)$stat->valeur_ar,
                    'valeur_formate' => number_format($stat->valeur_ar, 0, ',', ' ') . ' Ar',
                    'nombre_pv' => $stat->nombre_pv,
                    'prix_moyen' => (float)$stat->prix_moyen,
                    'prix_moyen_formate' => number_format($stat->prix_moyen, 0, ',', ' ') . ' Ar/kg',
                    'pourcentage' => $pourcentage . '%',
                    'pourcentage_numerique' => $pourcentage
                ];
            }
            
            // Ajouter HE aux types
            if (($statsHE->poids_kg ?? 0) > 0) {
                $pourcentageHE = $totalPoidsLocalisation > 0 ? 
                    round(($statsHE->poids_kg / $totalPoidsLocalisation) * 100, 1) : 0;
                
                $typesAvecPourcentages[] = [
                    'type' => 'HE',
                    'nom' => 'Huile Essentielle - Feuilles',
                    'poids_kg' => (float)($statsHE->poids_kg ?? 0),
                    'poids_formate' => number_format($statsHE->poids_kg ?? 0, 1) . ' kg',
                    'valeur_ar' => (float)($statsHE->valeur_ar ?? 0),
                    'valeur_formate' => number_format($statsHE->valeur_ar ?? 0, 0, ',', ' ') . ' Ar',
                    'nombre_pv' => $statsHE->nombre_fiches ?? 0,
                    'prix_moyen' => ($statsHE->poids_kg ?? 0) > 0 ? 
                        (float)($statsHE->valeur_ar ?? 0) / (float)($statsHE->poids_kg ?? 0) : 0,
                    'prix_moyen_formate' => ($statsHE->poids_kg ?? 0) > 0 ? 
                        number_format(($statsHE->valeur_ar ?? 0) / ($statsHE->poids_kg ?? 0), 0, ',', ' ') . ' Ar/kg' : '0 Ar/kg',
                    'pourcentage' => $pourcentageHE . '%',
                    'pourcentage_numerique' => $pourcentageHE
                ];
            }
            
            // Formater la tendance mensuelle
            $tendanceFormatee = [];
            foreach ($tendanceMensuelle as $tendance) {
                $date = Carbon::createFromFormat('Y-m', $tendance->mois);
                $tendanceFormatee[] = [
                    'periode' => $date->format('M Y'),
                    'mois' => $date->month,
                    'annee' => $date->year,
                    'poids_kg' => (float)$tendance->poids_kg,
                    'nombre_pv' => $tendance->nombre_pv,
                    'valeur_ar' => (float)$tendance->valeur_ar,
                    'mois_complet' => $tendance->mois
                ];
            }
            
            // Remplir les mois manquants dans la tendance
            $tendanceComplete = $this->completerTendanceMois($tendanceFormatee);
            
            return response()->json([
                'success' => true,
                'localisation' => [
                    'id' => $localisation->id,
                    'nom' => $localisation->Nom,
                    'nombre_collecteurs' => $collecteurs->count(),
                    'statut' => $collecteurs->count() > 0 || ($statsHE->nombre_fiches ?? 0) > 0 ? 'Actif' : 'Inactif',
                    'collecteurs' => $collecteurs->map(function($collecteur) {
                        return [
                            'id' => $collecteur->id,
                            'nom_complet' => $collecteur->nom . ' ' . $collecteur->prenom,
                            'prenom' => $collecteur->prenom,
                            'nom' => $collecteur->nom,
                            'numero' => $collecteur->numero,
                            'role' => $collecteur->role,
                            'code_collecteur' => $collecteur->code_collecteur,
                            'pv_payes' => $collecteur->pv_payes,
                            'poids_total' => (float)$collecteur->poids_total,
                            'poids_total_formate' => number_format($collecteur->poids_total, 1) . ' kg',
                            'valeur_total' => (float)$collecteur->valeur_total,
                            'valeur_total_formate' => number_format($collecteur->valeur_total, 0, ',', ' ') . ' Ar'
                        ];
                    })
                ],
                'statistiques' => [
                    'total_poids' => (float)$totalPoidsLocalisation,
                    'total_poids_formate' => number_format($totalPoidsLocalisation, 1) . ' kg',
                    'total_valeur' => (float)(($statsGenerales->total_valeur ?? 0) + ($statsHE->valeur_ar ?? 0)),
                    'total_valeur_formate' => number_format(($statsGenerales->total_valeur ?? 0) + ($statsHE->valeur_ar ?? 0), 0, ',', ' ') . ' Ar',
                    'total_fiches' => ($statsGenerales->total_pv ?? 0) + ($statsHE->nombre_fiches ?? 0),
                    'prix_moyen_global' => $totalPoidsLocalisation > 0 ? 
                        (($statsGenerales->total_valeur ?? 0) + ($statsHE->valeur_ar ?? 0)) / $totalPoidsLocalisation : 0,
                    'prix_moyen_global_formate' => $totalPoidsLocalisation > 0 ? 
                        number_format((($statsGenerales->total_valeur ?? 0) + ($statsHE->valeur_ar ?? 0)) / $totalPoidsLocalisation, 0, ',', ' ') . ' Ar/kg' : '0 Ar/kg',
                    'date_premier_pv' => $statsGenerales->date_premier_pv ? 
                        Carbon::parse($statsGenerales->date_premier_pv)->format('d/m/Y') : null,
                    'date_dernier_pv' => $statsGenerales->date_dernier_pv ? 
                        Carbon::parse($statsGenerales->date_dernier_pv)->format('d/m/Y') : null,
                    'par_type' => $typesAvecPourcentages,
                    'par_categorie' => [
                        'matiere_premiere' => [
                            'poids_kg' => (float)($statsGenerales->total_poids ?? 0),
                            'valeur_ar' => (float)($statsGenerales->total_valeur ?? 0),
                            'nombre_fiches' => $statsGenerales->total_pv ?? 0
                        ],
                        'huile_essentielle' => [
                            'poids_kg' => (float)($statsHE->poids_kg ?? 0),
                            'valeur_ar' => (float)($statsHE->valeur_ar ?? 0),
                            'nombre_fiches' => $statsHE->nombre_fiches ?? 0
                        ]
                    ]
                ],
                'meilleurs_collecteurs' => $meilleursCollecteurs->map(function($collecteur, $index) {
                    return [
                        'classement' => $index + 1,
                        'medaille' => $this->getMedaille($index + 1),
                        'id' => $collecteur->id,
                        'nom_complet' => $collecteur->nom . ' ' . $collecteur->prenom,
                        'poids_total' => (float)$collecteur->poids_total,
                        'poids_total_formate' => number_format($collecteur->poids_total, 1) . ' kg',
                        'valeur_total' => (float)$collecteur->valeur_total,
                        'valeur_total_formate' => number_format($collecteur->valeur_total, 0, ',', ' ') . ' Ar',
                        'nombre_pv' => $collecteur->nombre_pv
                    ];
                }),
                'tendance_mensuelle' => $tendanceComplete,
                'periode_analyse' => [
                    'debut' => Carbon::now()->subMonths(5)->startOfMonth()->format('Y-m-d'),
                    'fin' => Carbon::now()->endOfMonth()->format('Y-m-d'),
                    'nombre_mois' => 6
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des détails de la localisation',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }
    
    private function getStatsLocalisationHuileEssentielleDetails($localisationId)
    {
        $tableName = 'fiche_receptions'; // Modifier selon votre base de données
        
        if (!DB::getSchemaBuilder()->hasTable($tableName)) {
            return (object)[
                'poids_kg' => 0,
                'valeur_ar' => 0,
                'nombre_fiches' => 0
            ];
        }
        
        return DB::table($tableName . ' as f')
            ->join('utilisateurs as u', 'f.utilisateur_id', '=', 'u.id')
            ->select([
                DB::raw('SUM(f.poids_net) as poids_kg'),
                DB::raw('SUM(f.prix_total) as valeur_ar'),
                DB::raw('COUNT(f.id) as nombre_fiches')
            ])
            ->where('u.localisation_id', $localisationId)
            ->where('f.statut', 'payé')
            ->first() ?? (object)[
                'poids_kg' => 0,
                'valeur_ar' => 0,
                'nombre_fiches' => 0
            ];
    }
    
    /**
     * Compléter les mois manquants dans la tendance
     */
    private function completerTendanceMois($tendanceExistante)
    {
        $resultat = [];
        $date = Carbon::now()->subMonths(5)->startOfMonth();
        
        for ($i = 0; $i < 6; $i++) {
            $moisRecherche = $date->format('Y-m');
            $moisTendance = $date->format('M Y');
            
            $trouvee = false;
            foreach ($tendanceExistante as $tendance) {
                if ($tendance['mois_complet'] === $moisRecherche) {
                    $resultat[] = $tendance;
                    $trouvee = true;
                    break;
                }
            }
            
            if (!$trouvee) {
                $resultat[] = [
                    'periode' => $moisTendance,
                    'mois' => $date->month,
                    'annee' => $date->year,
                    'poids_kg' => 0,
                    'nombre_pv' => 0,
                    'valeur_ar' => 0,
                    'mois_complet' => $moisRecherche
                ];
            }
            
            $date->addMonth();
        }
        
        return $resultat;
    }
}