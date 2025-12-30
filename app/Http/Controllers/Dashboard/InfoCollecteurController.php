<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Utilisateur;
use App\Models\MatierePremiere\PVReception;
use App\Models\MatierePremiere\Facturation;
use App\Models\MatierePremiere\Impaye;
use App\Models\SoldeUser;
use App\Models\Transfert;
use App\Models\Localisation;
use App\Models\SiteCollecte;
use App\Models\TestHuille\FicheReception;
use Carbon\Carbon;

class InfoCollecteurController extends Controller
{
    /**
     * Récupérer la liste de tous les collecteurs avec leurs statistiques
     */
    public function listeCollecteurs(Request $request)
    {
        try {
            // Paramètres de pagination et filtres
            $perPage = $request->input('per_page', 10);
            $search = $request->input('search', '');
            $localisationId = $request->input('localisation_id');
            $statut = $request->input('statut', 'tous'); // tous, actif, inactif
            
            // Base query pour les collecteurs
            $query = Utilisateur::where('role', 'collecteur')
                ->with(['localisation', 'siteCollecte']);
            
            // Filtre par recherche
            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('nom', 'LIKE', "%{$search}%")
                      ->orWhere('prenom', 'LIKE', "%{$search}%")
                      ->orWhere('numero', 'LIKE', "%{$search}%")
                      ->orWhere('CIN', 'LIKE', "%{$search}%")
                      ->orWhere('code_collecteur', 'LIKE', "%{$search}%");
                });
            }
            
            // Filtre par localisation
            if ($localisationId) {
                $query->where('localisation_id', $localisationId);
            }
            
            // Filtre par statut
            if ($statut === 'actif') {
                $query->where(function($q) {
                    $q->whereHas('pvReceptions', function($query) {
                        $query->where('statut', 'paye')
                            ->where('date_reception', '>=', Carbon::now()->subMonth());
                    })->orWhereHas('fichesReceptionsHE', function($query) {
                        $query->where('statut', 'payé')
                            ->where('date_reception', '>=', Carbon::now()->subMonth());
                    });
                });
            } elseif ($statut === 'inactif') {
                $query->where(function($q) {
                    $q->whereDoesntHave('pvReceptions', function($query) {
                        $query->where('statut', 'paye')
                            ->where('date_reception', '>=', Carbon::now()->subMonth());
                    })->whereDoesntHave('fichesReceptionsHE', function($query) {
                        $query->where('statut', 'payé')
                            ->where('date_reception', '>=', Carbon::now()->subMonth());
                    });
                });
            }
            
            // Pagination
            $collecteurs = $query->orderBy('nom')
                ->orderBy('prenom')
                ->paginate($perPage);
            
            // Récupérer les statistiques globales
            $statsGlobales = $this->getStatsGlobalesCollecteurs();
            
            // Pour chaque collecteur, ajouter ses statistiques
            $collecteurs->getCollection()->transform(function($collecteur) {
                return $this->enrichirCollecteur($collecteur);
            });
            
            return response()->json([
                'success' => true,
                'data' => [
                    'collecteurs' => $collecteurs,
                    'stats_globales' => $statsGlobales,
                    'filtres' => [
                        'search' => $search,
                        'localisation_id' => $localisationId,
                        'statut' => $statut,
                        'per_page' => $perPage
                    ]
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des collecteurs',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Récupérer les détails complets d'un collecteur
     */
    public function detailsCollecteur($id)
    {
        try {
            $collecteur = Utilisateur::with(['localisation', 'siteCollecte'])
                ->where('role', 'collecteur')
                ->findOrFail($id);
            
            // Enrichir le collecteur avec toutes ses données
            $collecteurEnrichi = $this->enrichirCollecteur($collecteur);
            
            // Récupérer l'historique des transferts
            $transferts = $this->getTransfertsCollecteur($collecteur->id);
            
            // Récupérer l'historique des PV de réception
            $pvReceptions = $this->getPvReceptionsCollecteur($collecteur->id);
            
            // Récupérer l'historique des fiches HE
            $fichesHE = $this->getFichesHECollecteur($collecteur->id);
            
            // Récupérer l'historique des facturations
            $facturations = $this->getFacturationsCollecteur($collecteur->id);
            
            // Récupérer l'historique des impayés
            $impayes = $this->getImpayesCollecteur($collecteur->id);
            
            // Récupérer les statistiques par type de matière (incluant HE)
            $statsParType = $this->getStatsParTypeCollecteur($collecteur->id);
            
            // Tendance mensuelle
            $tendanceMensuelle = $this->getTendanceMensuelleCollecteur($collecteur->id);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'collecteur' => $collecteurEnrichi,
                    'transferts' => $transferts,
                    'pv_receptions' => $pvReceptions,
                    'fiches_he' => $fichesHE,
                    'facturations' => $facturations,
                    'impayes' => $impayes,
                    'stats_par_type' => $statsParType,
                    'tendance_mensuelle' => $tendanceMensuelle
                ]
            ]);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Collecteur non trouvé'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des détails du collecteur',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Récupérer les statistiques globales des collecteurs (incluant HE)
     */
    private function getStatsGlobalesCollecteurs()
    {
        // Nombre total de collecteurs
        $totalCollecteurs = Utilisateur::where('role', 'collecteur')->count();
        
        // Collecteurs actifs (ont créé des PV payés ou fiches HE ce mois)
        $collecteursActifsPV = DB::table('utilisateurs as u')
            ->join('p_v_receptions as pv', 'u.id', '=', 'pv.utilisateur_id')
            ->where('u.role', 'collecteur')
            ->where('pv.statut', 'paye')
            ->where('pv.date_reception', '>=', Carbon::now()->subMonth())
            ->distinct()
            ->count('u.id');
        
        $collecteursActifsHE = DB::table('utilisateurs as u')
            ->join('fiche_receptions as fr', 'u.id', '=', 'fr.utilisateur_id')
            ->where('u.role', 'collecteur')
            ->where('fr.statut', 'payé')
            ->where('fr.date_reception', '>=', Carbon::now()->subMonth())
            ->distinct()
            ->count('u.id');
        
        // Combiner les collecteurs actifs (unique)
        $collecteursActifs = max($collecteursActifsPV, $collecteursActifsHE);
        
        // Solde total disponible
        $soldeTotalDisponible = SoldeUser::join('utilisateurs as u', 'solde_users.utilisateur_id', '=', 'u.id')
            ->where('u.role', 'collecteur')
            ->sum('solde_users.solde');
        
        // Total dépensé (matières premières + HE)
        $totalDepenseMP = DB::table('p_v_receptions')
            ->join('utilisateurs', 'p_v_receptions.utilisateur_id', '=', 'utilisateurs.id')
            ->where('utilisateurs.role', 'collecteur')
            ->where('p_v_receptions.statut', 'paye')
            ->sum('p_v_receptions.prix_total');
        
        $totalDepenseHE = DB::table('fiche_receptions')
            ->join('utilisateurs', 'fiche_receptions.utilisateur_id', '=', 'utilisateurs.id')
            ->where('utilisateurs.role', 'collecteur')
            ->where('fiche_receptions.statut', 'payé')
            ->sum('fiche_receptions.prix_total');
        
        $totalDepense = $totalDepenseMP + $totalDepenseHE;
        
        // Total matières premières (kg)
        $totalMpKgMP = DB::table('p_v_receptions')
            ->join('utilisateurs', 'p_v_receptions.utilisateur_id', '=', 'utilisateurs.id')
            ->where('utilisateurs.role', 'collecteur')
            ->where('p_v_receptions.statut', 'paye')
            ->sum('p_v_receptions.poids_net');
        
        $totalMpKgHE = DB::table('fiche_receptions')
            ->join('utilisateurs', 'fiche_receptions.utilisateur_id', '=', 'utilisateurs.id')
            ->where('utilisateurs.role', 'collecteur')
            ->where('fiche_receptions.statut', 'payé')
            ->sum('fiche_receptions.poids_net');
        
        $totalMpKg = $totalMpKgMP + $totalMpKgHE;
        
        return [
            'total_collecteurs' => $totalCollecteurs,
            'collecteurs_actifs' => $collecteursActifs,
            'collecteurs_inactifs' => $totalCollecteurs - $collecteursActifs,
            'solde_total_disponible' => (float) $soldeTotalDisponible,
            'solde_total_formate' => number_format($soldeTotalDisponible, 0, ',', ' ') . ' Ar',
            'total_depense' => (float) $totalDepense,
            'total_depense_formate' => number_format($totalDepense, 0, ',', ' ') . ' Ar',
            'total_mp_kg' => (float) $totalMpKg,
            'total_mp_formate' => number_format($totalMpKg, 1) . ' kg',
            'detail_depense' => [
                'matieres_premieres' => [
                    'montant' => (float) $totalDepenseMP,
                    'formate' => number_format($totalDepenseMP, 0, ',', ' ') . ' Ar',
                    'pourcentage' => $totalDepense > 0 ? round(($totalDepenseMP / $totalDepense) * 100, 1) : 0
                ],
                'huile_essentielle' => [
                    'montant' => (float) $totalDepenseHE,
                    'formate' => number_format($totalDepenseHE, 0, ',', ' ') . ' Ar',
                    'pourcentage' => $totalDepense > 0 ? round(($totalDepenseHE / $totalDepense) * 100, 1) : 0
                ]
            ],
            'detail_matiere' => [
                'matieres_premieres' => [
                    'poids_kg' => (float) $totalMpKgMP,
                    'formate' => number_format($totalMpKgMP, 1) . ' kg',
                    'pourcentage' => $totalMpKg > 0 ? round(($totalMpKgMP / $totalMpKg) * 100, 1) : 0
                ],
                'huile_essentielle' => [
                    'poids_kg' => (float) $totalMpKgHE,
                    'formate' => number_format($totalMpKgHE, 1) . ' kg',
                    'pourcentage' => $totalMpKg > 0 ? round(($totalMpKgHE / $totalMpKg) * 100, 1) : 0
                ]
            ],
            'moyenne_depense_par_collecteur' => $totalCollecteurs > 0 ? 
                round($totalDepense / $totalCollecteurs, 0) : 0,
            'moyenne_mp_par_collecteur' => $totalCollecteurs > 0 ? 
                round($totalMpKg / $totalCollecteurs, 1) : 0
        ];
    }
    
    /**
     * Enrichir un collecteur avec ses statistiques (incluant HE)
     */
    private function enrichirCollecteur($collecteur)
    {
        // Récupérer le solde de l'utilisateur
        $soldeUser = SoldeUser::where('utilisateur_id', $collecteur->id)->first();
        $soldeDisponible = $soldeUser ? (float) $soldeUser->solde : 0;
        
        // 1. Statistiques des PV de réception (matières premières classiques)
        $statsPv = DB::table('p_v_receptions')
            ->select([
                DB::raw('COUNT(*) as total_pv'),
                DB::raw('SUM(CASE WHEN statut = "paye" THEN poids_net ELSE 0 END) as mp_total_kg'),
                DB::raw('SUM(CASE WHEN statut = "paye" THEN prix_total ELSE 0 END) as total_depense'),
                DB::raw('SUM(CASE WHEN statut = "non_paye" THEN prix_total ELSE 0 END) as total_dette'),
                DB::raw('SUM(CASE WHEN statut = "non_paye" THEN dette_fournisseur ELSE 0 END) as dette_restante'),
                DB::raw('MAX(CASE WHEN statut = "paye" THEN date_reception END) as dernier_pv_date')
            ])
            ->where('utilisateur_id', $collecteur->id)
            ->first();
        
        // 2. Statistiques pour l'huile essentielle
        $statsHe = DB::table('fiche_receptions')
            ->select([
                DB::raw('COUNT(*) as total_fiches_he'),
                DB::raw('SUM(CASE WHEN statut = "payé" THEN poids_net ELSE 0 END) as he_total_kg'),
                DB::raw('SUM(CASE WHEN statut = "payé" THEN prix_total ELSE 0 END) as he_total_depense'),
                DB::raw('MAX(CASE WHEN statut = "payé" THEN date_reception END) as dernier_he_date')
            ])
            ->where('utilisateur_id', $collecteur->id)
            ->first();
        
        // Nombre de transferts reçus
        $nombreTransferts = Transfert::where('destinataire_id', $collecteur->id)->count();
        
        // Dernier transfert
        $dernierTransfert = Transfert::where('destinataire_id', $collecteur->id)
            ->orderBy('created_at', 'desc')
            ->first();
        
        // Calculer l'historique d'activité (PV des 30 derniers jours)
        $activite30jours = DB::table('p_v_receptions')
            ->where('utilisateur_id', $collecteur->id)
            ->where('statut', 'paye')
            ->where('date_reception', '>=', Carbon::now()->subDays(30))
            ->count();
        
        // Ajouter l'activité HE
        $activiteHe30jours = DB::table('fiche_receptions')
            ->where('utilisateur_id', $collecteur->id)
            ->where('statut', 'payé')
            ->where('date_reception', '>=', Carbon::now()->subDays(30))
            ->count();
        
        $totalActivite30jours = $activite30jours + $activiteHe30jours;
        
        // Déterminer le statut d'activité
        $statutActivite = $totalActivite30jours > 0 ? 'Actif' : 'Inactif';
        
        // Calculer les totaux combinés
        $totalMpKg = ($statsPv->mp_total_kg ?? 0) + ($statsHe->he_total_kg ?? 0);
        $totalDepense = ($statsPv->total_depense ?? 0) + ($statsHe->he_total_depense ?? 0);
        $totalPV = ($statsPv->total_pv ?? 0) + ($statsHe->total_fiches_he ?? 0);
        
        // Calculer le solde initial (total dépensé + solde disponible)
        $soldeInitial = $totalDepense + $soldeDisponible;
        
        // Pourcentage d'utilisation du solde
        $pourcentageUtilisation = $soldeInitial > 0 ? 
            round(($totalDepense / $soldeInitial) * 100, 1) : 0;
        
        // Dernière activité (matières premières ou HE)
        $dernierPvDate = $statsPv->dernier_pv_date ? Carbon::parse($statsPv->dernier_pv_date) : null;
        $dernierHeDate = $statsHe->dernier_he_date ? Carbon::parse($statsHe->dernier_he_date) : null;
        
        if ($dernierPvDate && $dernierHeDate) {
            $derniereActiviteDate = $dernierPvDate->gt($dernierHeDate) ? $dernierPvDate : $dernierHeDate;
        } elseif ($dernierPvDate) {
            $derniereActiviteDate = $dernierPvDate;
        } elseif ($dernierHeDate) {
            $derniereActiviteDate = $dernierHeDate;
        } else {
            $derniereActiviteDate = null;
        }
        
        // Calculer les détails par type
        $feuillesPoids = $this->getPoidsParType($collecteur->id, 'FG', 'p_v_receptions');
        $feuillesValeur = $this->getValeurParType($collecteur->id, 'FG', 'p_v_receptions');
        $griffesPoids = $this->getPoidsParType($collecteur->id, 'CG', 'p_v_receptions');
        $griffesValeur = $this->getValeurParType($collecteur->id, 'CG', 'p_v_receptions');
        $clousPoids = $this->getPoidsParType($collecteur->id, 'GG', 'p_v_receptions');
        $clousValeur = $this->getValeurParType($collecteur->id, 'GG', 'p_v_receptions');
        $hePoids = $this->getPoidsHE($collecteur->id);
        $heValeur = $this->getValeurHE($collecteur->id);
        
        return [
            'id' => $collecteur->id,
            'nom_complet' => $collecteur->nom . ' ' . $collecteur->prenom,
            'nom' => $collecteur->nom,
            'prenom' => $collecteur->prenom,
            'numero' => $collecteur->numero,
            'CIN' => $collecteur->CIN,
            'email' => $collecteur->email ?? null,
            'code_collecteur' => $collecteur->code_collecteur,
            'date_inscription' => $collecteur->created_at->format('d/m/Y'),
            'date_inscription_complete' => $collecteur->created_at->format('d/m/Y H:i'),
            'localisation' => $collecteur->localisation ? $collecteur->localisation->Nom : 'Non défini',
            'localisation_id' => $collecteur->localisation_id,
            'site_collecte' => $collecteur->siteCollecte ? $collecteur->siteCollecte->Nom : 'Non attribué',
            'site_collecte_id' => $collecteur->site_collecte_id,
            'statut_activite' => $statutActivite,
            'statut_activite_color' => $statutActivite === 'Actif' ? 'success' : 'warning',
            
            // Finances
            'solde_disponible' => $soldeDisponible,
            'solde_disponible_formate' => number_format($soldeDisponible, 0, ',', ' ') . ' Ar',
            'total_depense' => $totalDepense,
            'total_depense_formate' => number_format($totalDepense, 0, ',', ' ') . ' Ar',
            'solde_initial' => $soldeInitial,
            'solde_initial_formate' => number_format($soldeInitial, 0, ',', ' ') . ' Ar',
            'pourcentage_utilisation' => $pourcentageUtilisation,
            
            // Statistiques
            'nombre_transferts' => $nombreTransferts,
            'total_pv' => $totalPV,
            'mp_total_kg' => $totalMpKg,
            'mp_total_formate' => number_format($totalMpKg, 1) . ' kg',
            'total_dette' => (float) ($statsPv->total_dette ?? 0),
            'dette_restante' => (float) ($statsPv->dette_restante ?? 0),
            'dette_restante_formate' => number_format($statsPv->dette_restante ?? 0, 0, ',', ' ') . ' Ar',
            
            // Dates importantes
            'dernier_pv_date' => $dernierPvDate ? $dernierPvDate->format('d/m/Y') : 'Jamais',
            'dernier_he_date' => $dernierHeDate ? $dernierHeDate->format('d/m/Y') : 'Jamais',
            'dernier_transfert_date' => $dernierTransfert ? 
                $dernierTransfert->created_at->format('d/m/Y') : 'Jamais',
            'derniere_activite' => $derniereActiviteDate ? 
                $derniereActiviteDate->format('d/m/Y') : 'Jamais',
            
            // Activité récente
            'activite_30jours' => $totalActivite30jours,
            'activite_30jours_detail' => [
                'matieres_premieres' => $activite30jours,
                'huile_essentielle' => $activiteHe30jours
            ],
            'activite_7jours' => DB::table('p_v_receptions')
                ->where('utilisateur_id', $collecteur->id)
                ->where('statut', 'paye')
                ->where('date_reception', '>=', Carbon::now()->subDays(7))
                ->count() + 
                DB::table('fiche_receptions')
                    ->where('utilisateur_id', $collecteur->id)
                    ->where('statut', 'payé')
                    ->where('date_reception', '>=', Carbon::now()->subDays(7))
                    ->count(),
            
            // Détails par type de matière
            'detail_matiere' => [
                'feuilles_poids' => $feuillesPoids,
                'feuilles_valeur' => $feuillesValeur,
                'griffes_poids' => $griffesPoids,
                'griffes_valeur' => $griffesValeur,
                'clous_poids' => $clousPoids,
                'clous_valeur' => $clousValeur,
                'he_poids' => $hePoids,
                'he_valeur' => $heValeur
            ],
            
            // Totaux par type formatés pour affichage
            'detail_matiere_formate' => [
                'feuilles' => [
                    'poids' => number_format($feuillesPoids, 1) . ' kg',
                    'valeur' => number_format($feuillesValeur, 0, ',', ' ') . ' Ar'
                ],
                'griffes' => [
                    'poids' => number_format($griffesPoids, 1) . ' kg',
                    'valeur' => number_format($griffesValeur, 0, ',', ' ') . ' Ar'
                ],
                'clous' => [
                    'poids' => number_format($clousPoids, 1) . ' kg',
                    'valeur' => number_format($clousValeur, 0, ',', ' ') . ' Ar'
                ],
                'he_feuilles' => [
                    'poids' => number_format($hePoids, 1) . ' kg',
                    'valeur' => number_format($heValeur, 0, ',', ' ') . ' Ar'
                ]
            ]
        ];
    }
    
    /**
     * Récupérer les transferts d'un collecteur
     */
    private function getTransfertsCollecteur($collecteurId)
    {
        $transferts = Transfert::with(['admin:id,nom,prenom,CIN'])
            ->where('destinataire_id', $collecteurId)
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(function($transfert) {
                return [
                    'id' => $transfert->id,
                    'reference' => $transfert->reference ?? 'TRF-' . $transfert->created_at->format('dmY') . '-' . str_pad($transfert->id, 3, '0', STR_PAD_LEFT),
                    'montant' => (float) $transfert->montant,
                    'montant_formate' => number_format($transfert->montant, 0, ',', ' ') . ' Ar',
                    'type_transfert' => $transfert->type_transfert,
                    'type_icone' => $this->getIconeTypeTransfert($transfert->type_transfert),
                    'raison' => $transfert->raison,
                    'admin' => $transfert->admin ? 
                        $transfert->admin->nom . ' ' . $transfert->admin->prenom : 'Admin inconnu',
                    'admin_cin' => $transfert->admin->CIN ?? 'N/A',
                    'date' => $transfert->created_at->format('d/m/Y'),
                    'heure' => $transfert->created_at->format('H:i'),
                    'datetime' => $transfert->created_at->format('d/m/Y H:i'),
                    'statut' => 'complet' // Par défaut, les transferts sont toujours complets
                ];
            });
        
        return $transferts;
    }
    
    /**
     * Récupérer les PV de réception d'un collecteur
     */
    private function getPvReceptionsCollecteur($collecteurId)
    {
        $pvReceptions = PVReception::with(['fournisseur', 'provenance'])
            ->where('utilisateur_id', $collecteurId)
            ->orderBy('date_reception', 'desc')
            ->limit(15)
            ->get()
            ->map(function($pv) {
                return [
                    'id' => $pv->id,
                    'numero_doc' => $pv->numero_doc,
                    'type' => $pv->type,
                    'type_nom' => $this->getNomType($pv->type),
                    'date_reception' => Carbon::parse($pv->date_reception)->format('d/m/Y'),
                    'poids_net' => (float) $pv->poids_net,
                    'poids_formate' => number_format($pv->poids_net, 1) . ' kg',
                    'prix_total' => (float) $pv->prix_total,
                    'prix_formate' => number_format($pv->prix_total, 0, ',', ' ') . ' Ar',
                    'prix_unitaire' => (float) $pv->prix_unitaire,
                    'statut' => $pv->statut,
                    'statut_label' => $this->getLabelStatut($pv->statut),
                    'statut_color' => $this->getColorStatut($pv->statut),
                    'fournisseur' => $pv->fournisseur ? 
                        $pv->fournisseur->nom . ' ' . $pv->fournisseur->prenom : 'N/A',
                    'provenance' => $pv->provenance->nom ?? 'N/A',
                    'dette_fournisseur' => (float) $pv->dette_fournisseur,
                    'created_at' => $pv->created_at->format('d/m/Y H:i')
                ];
            });
        
        return $pvReceptions;
    }
    
    /**
     * Récupérer les fiches HE d'un collecteur
     */
    private function getFichesHECollecteur($collecteurId)
    {
        $fichesHE = FicheReception::with(['fournisseur', 'siteCollecte'])
            ->where('utilisateur_id', $collecteurId)
            ->orderBy('date_reception', 'desc')
            ->limit(15)
            ->get()
            ->map(function($fiche) {
                return [
                    'id' => $fiche->id,
                    'numero_document' => $fiche->numero_document,
                    'date_reception' => Carbon::parse($fiche->date_reception)->format('d/m/Y'),
                    'heure_reception' => $fiche->heure_reception,
                    'poids_net' => (float) $fiche->poids_net,
                    'poids_formate' => number_format($fiche->poids_net, 1) . ' kg',
                    'prix_total' => (float) $fiche->prix_total,
                    'prix_formate' => number_format($fiche->prix_total, 0, ',', ' ') . ' Ar',
                    'prix_unitaire' => (float) $fiche->prix_unitaire,
                    'statut' => $fiche->statut,
                    'statut_label' => $this->getLabelStatutHE($fiche->statut),
                    'statut_color' => $this->getColorStatutHE($fiche->statut),
                    'fournisseur' => $fiche->fournisseur ? 
                        $fiche->fournisseur->nom . ' ' . $fiche->fournisseur->prenom : 'N/A',
                    'site_collecte' => $fiche->siteCollecte->Nom ?? 'N/A',
                    'created_at' => $fiche->created_at->format('d/m/Y H:i')
                ];
            });
        
        return $fichesHE;
    }
    
    /**
     * Récupérer les facturations d'un collecteur
     */
    private function getFacturationsCollecteur($collecteurId)
    {
        $facturations = Facturation::with(['pvReception.fournisseur'])
            ->whereHas('pvReception', function($query) use ($collecteurId) {
                $query->where('utilisateur_id', $collecteurId);
            })
            ->orderBy('date_facturation', 'desc')
            ->limit(15)
            ->get()
            ->map(function($facturation) {
                return [
                    'id' => $facturation->id,
                    'numero_facture' => $facturation->numero_facture,
                    'date_facturation' => Carbon::parse($facturation->date_facturation)->format('d/m/Y'),
                    'montant_total' => (float) $facturation->montant_total,
                    'montant_formate' => number_format($facturation->montant_total, 0, ',', ' ') . ' Ar',
                    'montant_paye' => (float) $facturation->montant_paye,
                    'reste_a_payer' => (float) $facturation->reste_a_payer,
                    'mode_paiement' => $facturation->mode_paiement,
                    'mode_paiement_label' => $this->getLabelModePaiement($facturation->mode_paiement),
                    'reference_paiement' => $facturation->reference_paiement,
                    'statut' => $facturation->reste_a_payer == 0 ? 'payee' : 'partielle',
                    'statut_label' => $facturation->reste_a_payer == 0 ? 'Payée' : 'Partielle',
                    'pv_reception_id' => $facturation->pv_reception_id,
                    'pv_numero' => $facturation->pvReception->numero_doc ?? 'N/A'
                ];
            });
        
        return $facturations;
    }
    
    /**
     * Récupérer les impayés d'un collecteur
     */
    private function getImpayesCollecteur($collecteurId)
    {
        $impayes = Impaye::with(['pvReception.fournisseur'])
            ->whereHas('pvReception', function($query) use ($collecteurId) {
                $query->where('utilisateur_id', $collecteurId);
            })
            ->orderBy('date_facturation', 'desc')
            ->limit(15)
            ->get()
            ->map(function($impaye) {
                return [
                    'id' => $impaye->id,
                    'numero_facture' => $impaye->numero_facture,
                    'date_facturation' => Carbon::parse($impaye->date_facturation)->format('d/m/Y'),
                    'montant_total' => (float) $impaye->montant_total,
                    'montant_formate' => number_format($impaye->montant_total, 0, ',', ' ') . ' Ar',
                    'montant_paye' => (float) $impaye->montant_paye,
                    'reste_a_payer' => (float) $impaye->reste_a_payer,
                    'mode_paiement' => $impaye->mode_paiement,
                    'reference_paiement' => $impaye->reference_paiement,
                    'statut' => $impaye->reste_a_payer == 0 ? 'regle' : 'en_cours',
                    'statut_label' => $impaye->reste_a_payer == 0 ? 'Réglé' : 'En cours',
                    'pv_reception_id' => $impaye->pv_reception_id,
                    'pv_numero' => $impaye->pvReception->numero_doc ?? 'N/A'
                ];
            });
        
        return $impayes;
    }
    
    /**
     * Récupérer les statistiques par type de matière d'un collecteur (incluant HE)
     */
    private function getStatsParTypeCollecteur($collecteurId)
    {
        // 1. Statistiques pour les matières premières classiques (FG, CG, GG)
        $statsPV = DB::table('p_v_receptions')
            ->select([
                'type',
                DB::raw('SUM(poids_net) as poids_kg'),
                DB::raw('SUM(prix_total) as valeur_ar'),
                DB::raw('COUNT(*) as nombre_pv'),
                DB::raw('AVG(prix_unitaire) as prix_moyen')
            ])
            ->where('utilisateur_id', $collecteurId)
            ->where('statut', 'paye')
            ->groupBy('type')
            ->get();
        
        // 2. Statistiques pour l'huile essentielle (FicheReception)
        $statsHE = DB::table('fiche_receptions')
            ->select([
                DB::raw('"HE" as type'),
                DB::raw('SUM(poids_net) as poids_kg'),
                DB::raw('SUM(prix_total) as valeur_ar'),
                DB::raw('COUNT(*) as nombre_pv'),
                DB::raw('AVG(prix_unitaire) as prix_moyen')
            ])
            ->where('utilisateur_id', $collecteurId)
            ->where('statut', 'payé')
            ->first();
        
        // Combiner les résultats
        $toutesStats = collect();
        
        // Ajouter les stats PV
        foreach ($statsPV as $stat) {
            $toutesStats->push($stat);
        }
        
        // Ajouter les stats HE si elles existent
        if ($statsHE && ($statsHE->poids_kg > 0 || $statsHE->valeur_ar > 0)) {
            $toutesStats->push($statsHE);
        }
        
        $totalPoids = $toutesStats->sum('poids_kg');
        $totalValeur = $toutesStats->sum('valeur_ar');
        
        $result = [];
        $types = ['FG', 'CG', 'GG', 'HE']; // Types maintenant incluent HE
        
        foreach ($types as $typeCode) {
            $statType = $toutesStats->where('type', $typeCode)->first();
            $poids = $statType ? (float) $statType->poids_kg : 0;
            $valeur = $statType ? (float) $statType->valeur_ar : 0;
            
            $result[$typeCode] = [
                'type' => $typeCode,
                'nom' => $this->getNomType($typeCode),
                'poids_kg' => $poids,
                'poids_formate' => number_format($poids, 1) . ' kg',
                'valeur_ar' => $valeur,
                'valeur_formate' => number_format($valeur, 0, ',', ' ') . ' Ar',
                'nombre_pv' => $statType ? $statType->nombre_pv : 0,
                'prix_moyen' => $statType ? (float) $statType->prix_moyen : 0,
                'prix_moyen_formate' => $statType ? number_format($statType->prix_moyen, 0, ',', ' ') . ' Ar/kg' : '0 Ar/kg',
                'pourcentage_poids' => $totalPoids > 0 ? round(($poids / $totalPoids) * 100, 1) : 0,
                'pourcentage_valeur' => $totalValeur > 0 ? round(($valeur / $totalValeur) * 100, 1) : 0
            ];
        }
        
        // Calculer la qualité moyenne (basée sur le prix moyen des matières premières classiques)
        $statsSansHE = $statsPV->where('type', '!=', 'HE');
        $totalPoidsSansHE = $statsSansHE->sum('poids_kg');
        $totalValeurSansHE = $statsSansHE->sum('valeur_ar');
        $prixMoyenGlobal = $totalPoidsSansHE > 0 ? $totalValeurSansHE / $totalPoidsSansHE : 0;
        $qualite = $this->determinerQualite($prixMoyenGlobal);
        
        return [
            'par_type' => $result,
            'total' => [
                'poids_kg' => $totalPoids,
                'poids_formate' => number_format($totalPoids, 1) . ' kg',
                'valeur_ar' => $totalValeur,
                'valeur_formate' => number_format($totalValeur, 0, ',', ' ') . ' Ar',
                'nombre_pv' => $toutesStats->sum('nombre_pv'),
                'prix_moyen_global' => $totalPoids > 0 ? round($totalValeur / $totalPoids, 0) : 0,
                'prix_moyen_global_formate' => $totalPoids > 0 ? number_format(round($totalValeur / $totalPoids, 0), 0, ',', ' ') . ' Ar/kg' : '0 Ar/kg',
                'qualite' => $qualite['niveau'],
                'qualite_label' => $qualite['label'],
                'qualite_color' => $qualite['color'],
                'detail_types' => [
                    'matieres_premieres' => [
                        'poids_kg' => $statsPV->sum('poids_kg'),
                        'valeur_ar' => $statsPV->sum('valeur_ar'),
                        'nombre_pv' => $statsPV->sum('nombre_pv')
                    ],
                    'huile_essentielle' => [
                        'poids_kg' => $statsHE ? (float)$statsHE->poids_kg : 0,
                        'valeur_ar' => $statsHE ? (float)$statsHE->valeur_ar : 0,
                        'nombre_pv' => $statsHE ? $statsHE->nombre_pv : 0
                    ]
                ]
            ]
        ];
    }
    
    /**
     * Récupérer la tendance mensuelle d'un collecteur
     */
    private function getTendanceMensuelleCollecteur($collecteurId)
    {
        $tendances = [];
        $date = Carbon::now()->subMonths(5)->startOfMonth();
        
        for ($i = 0; $i < 6; $i++) {
            $debutMois = $date->copy();
            $finMois = $date->copy()->endOfMonth();
            
            // Statistiques PV
            $statsPV = DB::table('p_v_receptions')
                ->select([
                    DB::raw('SUM(poids_net) as poids_kg'),
                    DB::raw('COUNT(*) as nombre_pv'),
                    DB::raw('SUM(prix_total) as valeur_ar')
                ])
                ->where('utilisateur_id', $collecteurId)
                ->where('statut', 'paye')
                ->whereBetween('date_reception', [$debutMois, $finMois])
                ->first();
            
            // Statistiques HE
            $statsHE = DB::table('fiche_receptions')
                ->select([
                    DB::raw('SUM(poids_net) as poids_kg'),
                    DB::raw('COUNT(*) as nombre_fiches'),
                    DB::raw('SUM(prix_total) as valeur_ar')
                ])
                ->where('utilisateur_id', $collecteurId)
                ->where('statut', 'payé')
                ->whereBetween('date_reception', [$debutMois, $finMois])
                ->first();
            
            $poidsTotal = ($statsPV->poids_kg ?? 0) + ($statsHE->poids_kg ?? 0);
            $valeurTotal = ($statsPV->valeur_ar ?? 0) + ($statsHE->valeur_ar ?? 0);
            $nombreTotal = ($statsPV->nombre_pv ?? 0) + ($statsHE->nombre_fiches ?? 0);
            
            $tendances[] = [
                'periode' => $date->format('M Y'),
                'mois' => $date->month,
                'annee' => $date->year,
                'poids_kg' => (float) $poidsTotal,
                'nombre_pv' => $nombreTotal,
                'valeur_ar' => (float) $valeurTotal,
                'mois_complet' => $date->format('Y-m'),
                'detail' => [
                    'matieres_premieres' => [
                        'poids_kg' => (float) ($statsPV->poids_kg ?? 0),
                        'valeur_ar' => (float) ($statsPV->valeur_ar ?? 0),
                        'nombre_pv' => $statsPV->nombre_pv ?? 0
                    ],
                    'huile_essentielle' => [
                        'poids_kg' => (float) ($statsHE->poids_kg ?? 0),
                        'valeur_ar' => (float) ($statsHE->valeur_ar ?? 0),
                        'nombre_fiches' => $statsHE->nombre_fiches ?? 0
                    ]
                ]
            ];
            
            $date->addMonth();
        }
        
        return $tendances;
    }
    
    /**
     * Méthodes utilitaires
     */
    private function getNomType($code)
    {
        $noms = [
            'FG' => 'Feuilles',
            'CG' => 'Griffes',
            'GG' => 'Clous',
            'HE' => 'HE Feuilles'
        ];
        
        return $noms[$code] ?? $code;
    }
    
    private function getIconeTypeTransfert($type)
    {
        $icones = [
            'especes' => '💰',
            'mobile' => '📱',
            'virement' => '🏦'
        ];
        
        return $icones[$type] ?? '💸';
    }
    
    private function getLabelStatut($statut)
    {
        $labels = [
            'paye' => 'Payé',
            'non_paye' => 'Non payé',
            'incomplet' => 'Incomplet',
            'en_attente_livraison' => 'En attente livraison',
            'partiellement_livre' => 'Partiellement livré',
            'livree' => 'Livré'
        ];
        
        return $labels[$statut] ?? $statut;
    }
    
    private function getColorStatut($statut)
    {
        $colors = [
            'paye' => 'success',
            'non_paye' => 'danger',
            'incomplet' => 'warning',
            'en_attente_livraison' => 'info',
            'partiellement_livre' => 'warning',
            'livree' => 'success'
        ];
        
        return $colors[$statut] ?? 'secondary';
    }
    
    private function getLabelStatutHE($statut)
    {
        $labels = [
            'en attente de teste' => 'En attente de test',
            'en cours de teste' => 'En cours de test',
            'Accepté' => 'Accepté',
            'Refusé' => 'Refusé',
            'A retraiter' => 'À retraiter',
            'payé' => 'Payé',
            'incomplet' => 'Incomplet',
            'payement incomplète' => 'Paiement incomplet',
            'En attente de livraison' => 'En attente de livraison',
            'en cours de livraison' => 'En cours de livraison',
            'livré' => 'Livré',
            'partiellement_livre' => 'Partiellement livré'
        ];
        
        return $labels[$statut] ?? $statut;
    }
    
    private function getColorStatutHE($statut)
    {
        $colors = [
            'en attente de teste' => 'info',
            'en cours de teste' => 'warning',
            'Accepté' => 'success',
            'Refusé' => 'danger',
            'A retraiter' => 'warning',
            'payé' => 'success',
            'incomplet' => 'warning',
            'payement incomplète' => 'warning',
            'En attente de livraison' => 'info',
            'en cours de livraison' => 'primary',
            'livré' => 'success',
            'partiellement_livre' => 'warning'
        ];
        
        return $colors[$statut] ?? 'secondary';
    }
    
    private function getLabelModePaiement($mode)
    {
        $labels = [
            'especes' => 'Espèces',
            'virement' => 'Virement',
            'cheque' => 'Chèque',
            'carte' => 'Carte',
            'mobile_money' => 'Mobile Money'
        ];
        
        return $labels[$mode] ?? $mode;
    }
    
    private function determinerQualite($prixMoyen)
    {
        // Logique de détermination de la qualité basée sur le prix moyen
        if ($prixMoyen >= 200) {
            return ['niveau' => 'A', 'label' => 'Excellente', 'color' => 'success'];
        } elseif ($prixMoyen >= 150) {
            return ['niveau' => 'B', 'label' => 'Bonne', 'color' => 'primary'];
        } elseif ($prixMoyen >= 100) {
            return ['niveau' => 'C', 'label' => 'Moyenne', 'color' => 'warning'];
        } else {
            return ['niveau' => 'D', 'label' => 'Faible', 'color' => 'danger'];
        }
    }
    
    /**
     * Méthodes utilitaires pour récupérer les données spécifiques
     */
    private function getPoidsParType($collecteurId, $type, $table = 'p_v_receptions')
    {
        return DB::table($table)
            ->where('utilisateur_id', $collecteurId)
            ->where('type', $type)
            ->where('statut', $table === 'p_v_receptions' ? 'paye' : 'payé')
            ->sum('poids_net') ?? 0;
    }
    
    private function getValeurParType($collecteurId, $type, $table = 'p_v_receptions')
    {
        return DB::table($table)
            ->where('utilisateur_id', $collecteurId)
            ->where('type', $type)
            ->where('statut', $table === 'p_v_receptions' ? 'paye' : 'payé')
            ->sum('prix_total') ?? 0;
    }
    
    private function getPoidsHE($collecteurId)
    {
        return DB::table('fiche_receptions')
            ->where('utilisateur_id', $collecteurId)
            ->where('statut', 'payé')
            ->sum('poids_net') ?? 0;
    }
    
    private function getValeurHE($collecteurId)
    {
        return DB::table('fiche_receptions')
            ->where('utilisateur_id', $collecteurId)
            ->where('statut', 'payé')
            ->sum('prix_total') ?? 0;
    }
    
    /**
     * Récupérer les collecteurs par localisation
     */
    public function getCollecteursParLocalisation($localisationId)
    {
        try {
            $localisation = Localisation::findOrFail($localisationId);
            
            $collecteurs = Utilisateur::where('localisation_id', $localisationId)
                ->where('role', 'collecteur')
                ->with(['siteCollecte'])
                ->orderBy('nom')
                ->get()
                ->map(function($collecteur) {
                    return $this->enrichirCollecteur($collecteur);
                });
            
            // Statistiques de la localisation
            $statsLocalisation = $this->getStatsLocalisationCollecteurs($localisationId);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'localisation' => [
                        'id' => $localisation->id,
                        'nom' => $localisation->Nom,
                        'nombre_collecteurs' => $collecteurs->count()
                    ],
                    'collecteurs' => $collecteurs,
                    'statistiques' => $statsLocalisation
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des collecteurs par localisation',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    private function getStatsLocalisationCollecteurs($localisationId)
    {
        $collecteursIds = Utilisateur::where('localisation_id', $localisationId)
            ->where('role', 'collecteur')
            ->pluck('id');
        
        if ($collecteursIds->isEmpty()) {
            return [
                'total_collecteurs' => 0,
                'total_pv' => 0,
                'total_mp_kg' => 0,
                'total_depense' => 0,
                'solde_total' => 0
            ];
        }
        
        // Statistiques PV
        $statsPV = DB::table('p_v_receptions')
            ->select([
                DB::raw('COUNT(*) as total_pv'),
                DB::raw('SUM(CASE WHEN statut = "paye" THEN poids_net ELSE 0 END) as total_mp_kg'),
                DB::raw('SUM(CASE WHEN statut = "paye" THEN prix_total ELSE 0 END) as total_depense')
            ])
            ->whereIn('utilisateur_id', $collecteursIds)
            ->first();
        
        // Statistiques HE
        $statsHE = DB::table('fiche_receptions')
            ->select([
                DB::raw('COUNT(*) as total_fiches_he'),
                DB::raw('SUM(CASE WHEN statut = "payé" THEN poids_net ELSE 0 END) as he_total_kg'),
                DB::raw('SUM(CASE WHEN statut = "payé" THEN prix_total ELSE 0 END) as he_total_depense')
            ])
            ->whereIn('utilisateur_id', $collecteursIds)
            ->first();
        
        $soldeTotal = SoldeUser::whereIn('utilisateur_id', $collecteursIds)->sum('solde');
        
        $totalMpKg = ($statsPV->total_mp_kg ?? 0) + ($statsHE->he_total_kg ?? 0);
        $totalDepense = ($statsPV->total_depense ?? 0) + ($statsHE->he_total_depense ?? 0);
        $totalPV = ($statsPV->total_pv ?? 0) + ($statsHE->total_fiches_he ?? 0);
        
        return [
            'total_collecteurs' => $collecteursIds->count(),
            'total_pv' => $totalPV,
            'total_mp_kg' => (float) $totalMpKg,
            'total_mp_formate' => number_format($totalMpKg, 1) . ' kg',
            'total_depense' => (float) $totalDepense,
            'total_depense_formate' => number_format($totalDepense, 0, ',', ' ') . ' Ar',
            'solde_total' => (float) $soldeTotal,
            'solde_total_formate' => number_format($soldeTotal, 0, ',', ' ') . ' Ar',
            'detail' => [
                'matieres_premieres' => [
                    'poids_kg' => (float) ($statsPV->total_mp_kg ?? 0),
                    'depense_ar' => (float) ($statsPV->total_depense ?? 0),
                    'nombre_pv' => $statsPV->total_pv ?? 0
                ],
                'huile_essentielle' => [
                    'poids_kg' => (float) ($statsHE->he_total_kg ?? 0),
                    'depense_ar' => (float) ($statsHE->he_total_depense ?? 0),
                    'nombre_fiches' => $statsHE->total_fiches_he ?? 0
                ]
            ]
        ];
    }
    
    /**
     * Rechercher des collecteurs
     */
    public function rechercherCollecteurs(Request $request)
    {
        try {
            $term = $request->input('term', '');
            
            if (strlen($term) < 2) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Terme de recherche trop court'
                ]);
            }
            
            $collecteurs = Utilisateur::where('role', 'collecteur')
                ->where(function($query) use ($term) {
                    $query->where('nom', 'LIKE', "%{$term}%")
                          ->orWhere('prenom', 'LIKE', "%{$term}%")
                          ->orWhere('numero', 'LIKE', "%{$term}%")
                          ->orWhere('CIN', 'LIKE', "%{$term}%")
                          ->orWhere('code_collecteur', 'LIKE', "%{$term}%");
                })
                ->with(['localisation', 'siteCollecte'])
                ->orderBy('nom')
                ->limit(20)
                ->get()
                ->map(function($collecteur) {
                    return [
                        'id' => $collecteur->id,
                        'nom_complet' => $collecteur->nom . ' ' . $collecteur->prenom,
                        'code_collecteur' => $collecteur->code_collecteur,
                        'numero' => $collecteur->numero,
                        'localisation' => $collecteur->localisation->Nom ?? 'N/A',
                        'site_collecte' => $collecteur->siteCollecte->Nom ?? 'N/A'
                    ];
                });
            
            return response()->json([
                'success' => true,
                'data' => $collecteurs,
                'count' => $collecteurs->count()
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la recherche des collecteurs',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Dashboard résumé pour les collecteurs (incluant HE)
     */
    public function dashboardResume()
    {
        try {
            // Statistiques globales
            $statsGlobales = $this->getStatsGlobalesCollecteurs();
            
            // Meilleurs collecteurs (top 5 par MP collectée - incluant HE)
            $meilleursCollecteurs = DB::table('p_v_receptions as pv')
                ->join('utilisateurs as u', 'pv.utilisateur_id', '=', 'u.id')
                ->select([
                    'u.id',
                    'u.nom',
                    'u.prenom',
                    'u.code_collecteur',
                    'u.localisation_id',
                    DB::raw('SUM(pv.poids_net) as mp_total_kg'),
                    DB::raw('SUM(pv.prix_total) as total_depense'),
                    DB::raw('COUNT(pv.id) as nombre_pv')
                ])
                ->where('u.role', 'collecteur')
                ->where('pv.statut', 'paye')
                ->where('pv.date_reception', '>=', Carbon::now()->subMonth())
                ->groupBy('u.id', 'u.nom', 'u.prenom', 'u.code_collecteur', 'u.localisation_id')
                ->get()
                ->map(function($collecteur) {
                    // Ajouter les données HE pour chaque collecteur
                    $statsHE = DB::table('fiche_receptions as fr')
                        ->select([
                            DB::raw('SUM(fr.poids_net) as he_poids_kg'),
                            DB::raw('SUM(fr.prix_total) as he_total_depense'),
                            DB::raw('COUNT(fr.id) as nombre_fiches_he')
                        ])
                        ->where('fr.utilisateur_id', $collecteur->id)
                        ->where('fr.statut', 'payé')
                        ->where('fr.date_reception', '>=', Carbon::now()->subMonth())
                        ->first();
                    
                    $totalPoids = (float) $collecteur->mp_total_kg + (float) ($statsHE->he_poids_kg ?? 0);
                    $totalDepense = (float) $collecteur->total_depense + (float) ($statsHE->he_total_depense ?? 0);
                    
                    return [
                        'id' => $collecteur->id,
                        'nom_complet' => $collecteur->nom . ' ' . $collecteur->prenom,
                        'code_collecteur' => $collecteur->code_collecteur,
                        'mp_total_kg' => $totalPoids,
                        'mp_total_formate' => number_format($totalPoids, 1) . ' kg',
                        'total_depense' => $totalDepense,
                        'total_depense_formate' => number_format($totalDepense, 0, ',', ' ') . ' Ar',
                        'nombre_pv' => $collecteur->nombre_pv + ($statsHE->nombre_fiches_he ?? 0),
                        'detail' => [
                            'matieres_premieres' => [
                                'poids_kg' => (float) $collecteur->mp_total_kg,
                                'valeur_ar' => (float) $collecteur->total_depense,
                                'nombre_pv' => $collecteur->nombre_pv
                            ],
                            'huile_essentielle' => [
                                'poids_kg' => (float) ($statsHE->he_poids_kg ?? 0),
                                'valeur_ar' => (float) ($statsHE->he_total_depense ?? 0),
                                'nombre_fiches' => $statsHE->nombre_fiches_he ?? 0
                            ]
                        ],
                        'classement' => 1 // À ajuster selon le classement réel
                    ];
                })
                ->sortByDesc('mp_total_kg')
                ->take(5)
                ->values();
            
            // Dernières activités (PV et HE récents)
            $dernieresActivitesPV = DB::table('p_v_receptions as pv')
                ->join('utilisateurs as u', 'pv.utilisateur_id', '=', 'u.id')
                ->select([
                    'pv.id',
                    'pv.numero_doc',
                    'pv.type',
                    'pv.date_reception',
                    'pv.poids_net',
                    'pv.prix_total',
                    'pv.statut',
                    'u.id as collecteur_id',
                    'u.nom as collecteur_nom',
                    'u.prenom as collecteur_prenom'
                ])
                ->where('u.role', 'collecteur')
                ->where('pv.statut', 'paye')
                ->orderBy('pv.date_reception', 'DESC')
                ->limit(5)
                ->get()
                ->map(function($activite) {
                    return [
                        'type' => 'matiere_premiere',
                        'id' => $activite->id,
                        'numero_doc' => $activite->numero_doc,
                        'type_matiere' => $activite->type,
                        'type_nom' => $this->getNomType($activite->type),
                        'date_reception' => Carbon::parse($activite->date_reception)->format('d/m/Y'),
                        'poids_net' => (float) $activite->poids_net,
                        'poids_formate' => number_format($activite->poids_net, 1) . ' kg',
                        'prix_total' => (float) $activite->prix_total,
                        'prix_formate' => number_format($activite->prix_total, 0, ',', ' ') . ' Ar',
                        'collecteur' => $activite->collecteur_nom . ' ' . $activite->collecteur_prenom,
                        'collecteur_id' => $activite->collecteur_id
                    ];
                });
            
            $dernieresActivitesHE = DB::table('fiche_receptions as fr')
                ->join('utilisateurs as u', 'fr.utilisateur_id', '=', 'u.id')
                ->select([
                    'fr.id',
                    'fr.numero_document',
                    'fr.date_reception',
                    'fr.poids_net',
                    'fr.prix_total',
                    'fr.statut',
                    'u.id as collecteur_id',
                    'u.nom as collecteur_nom',
                    'u.prenom as collecteur_prenom'
                ])
                ->where('u.role', 'collecteur')
                ->where('fr.statut', 'payé')
                ->orderBy('fr.date_reception', 'DESC')
                ->limit(5)
                ->get()
                ->map(function($activite) {
                    return [
                        'type' => 'huile_essentielle',
                        'id' => $activite->id,
                        'numero_doc' => $activite->numero_document,
                        'type_matiere' => 'HE',
                        'type_nom' => 'HE Feuilles',
                        'date_reception' => Carbon::parse($activite->date_reception)->format('d/m/Y'),
                        'poids_net' => (float) $activite->poids_net,
                        'poids_formate' => number_format($activite->poids_net, 1) . ' kg',
                        'prix_total' => (float) $activite->prix_total,
                        'prix_formate' => number_format($activite->prix_total, 0, ',', ' ') . ' Ar',
                        'collecteur' => $activite->collecteur_nom . ' ' . $activite->collecteur_prenom,
                        'collecteur_id' => $activite->collecteur_id
                    ];
                });
            
            // Combiner et trier par date
            $dernieresActivites = collect()
                ->merge($dernieresActivitesPV)
                ->merge($dernieresActivitesHE)
                ->sortByDesc(function($activite) {
                    return Carbon::parse($activite['date_reception']);
                })
                ->take(10)
                ->values();
            
            // Statistiques par localisation (incluant HE)
            $statsParLocalisation = DB::table('utilisateurs as u')
                ->join('localisations as l', 'u.localisation_id', '=', 'l.id')
                ->leftJoin('p_v_receptions as pv', function($join) {
                    $join->on('u.id', '=', 'pv.utilisateur_id')
                         ->where('pv.statut', 'paye')
                         ->where('pv.date_reception', '>=', Carbon::now()->subMonth());
                })
                ->leftJoin('fiche_receptions as fr', function($join) {
                    $join->on('u.id', '=', 'fr.utilisateur_id')
                         ->where('fr.statut', 'payé')
                         ->where('fr.date_reception', '>=', Carbon::now()->subMonth());
                })
                ->select([
                    'l.id',
                    'l.Nom as localisation',
                    DB::raw('COUNT(DISTINCT u.id) as nombre_collecteurs'),
                    DB::raw('COALESCE(SUM(pv.poids_net), 0) as mp_total_kg'),
                    DB::raw('COALESCE(SUM(pv.prix_total), 0) as total_depense'),
                    DB::raw('COALESCE(SUM(fr.poids_net), 0) as he_total_kg'),
                    DB::raw('COALESCE(SUM(fr.prix_total), 0) as he_total_depense')
                ])
                ->where('u.role', 'collecteur')
                ->groupBy('l.id', 'l.Nom')
                ->get()
                ->map(function($localisation) {
                    $totalPoids = (float) $localisation->mp_total_kg + (float) $localisation->he_total_kg;
                    $totalDepense = (float) $localisation->total_depense + (float) $localisation->he_total_depense;
                    
                    return [
                        'id' => $localisation->id,
                        'nom' => $localisation->localisation,
                        'nombre_collecteurs' => $localisation->nombre_collecteurs,
                        'mp_total_kg' => $totalPoids,
                        'mp_total_formate' => number_format($totalPoids, 1) . ' kg',
                        'total_depense' => $totalDepense,
                        'total_depense_formate' => number_format($totalDepense, 0, ',', ' ') . ' Ar',
                        'detail' => [
                            'matieres_premieres' => [
                                'poids_kg' => (float) $localisation->mp_total_kg,
                                'depense_ar' => (float) $localisation->total_depense
                            ],
                            'huile_essentielle' => [
                                'poids_kg' => (float) $localisation->he_total_kg,
                                'depense_ar' => (float) $localisation->he_total_depense
                            ]
                        ]
                    ];
                })
                ->sortByDesc('mp_total_kg');
            
            return response()->json([
                'success' => true,
                'data' => [
                    'stats_globales' => $statsGlobales,
                    'meilleurs_collecteurs' => $meilleursCollecteurs,
                    'dernieres_activites' => $dernieresActivites,
                    'stats_par_localisation' => $statsParLocalisation
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la génération du dashboard',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}