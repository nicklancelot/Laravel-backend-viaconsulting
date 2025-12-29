<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CaissierController;
use App\Http\Controllers\Dashboard\CollecteurControlleur;
use App\Http\Controllers\Dashboard\DistillationDashController;
use App\Http\Controllers\Dashboard\InfoCollecteurController;
use App\Http\Controllers\DemandeSoldeController;
use App\Http\Controllers\DestinateurControlleur;
use App\Http\Controllers\Distillation\CarburantController;
use App\Http\Controllers\Distillation\DistillationController;
use App\Http\Controllers\Distillation\ExpeditionController;
use App\Http\Controllers\Distillation\GestionSoldeController;
use App\Http\Controllers\Distillation\StatistiqueController;
use App\Http\Controllers\Distillation\StockDistillationController;
use App\Http\Controllers\Distillation\TransportController;
use App\Http\Controllers\LivreurControlleur;
use App\Http\Controllers\LocalisationController;
use App\Http\Controllers\MatierePremiere\FacturationController;
use App\Http\Controllers\MatierePremiere\FicheLivraisonController;
use App\Http\Controllers\MatierePremiere\FournisseurController;
use App\Http\Controllers\MatierePremiere\ImpayeController;
use App\Http\Controllers\MatierePremiere\LivraisonController;
use App\Http\Controllers\MatierePremiere\PVReceptionController;
use App\Http\Controllers\MatierePremiere\StockController;
use App\Http\Controllers\MatierePremiere\StockPvReceptionController;
use App\Http\Controllers\PayementEnAvanceController;
use App\Http\Controllers\ProvenancesController;
use App\Http\Controllers\SiteCollecteController;
use App\Http\Controllers\SoldeUserController;
use App\Http\Controllers\TestHuille\statController;
use App\Http\Controllers\UtilisateurController;
use App\Http\Controllers\TestHuille\FicheReceptionController;
use App\Http\Controllers\TestHuille\HELivraisonController;
use App\Http\Controllers\TestHuille\HEFacturationController;
use App\Http\Controllers\TestHuille\HEFicheLivraisonController;
use App\Http\Controllers\TestHuille\HEImpayeController;
use App\Http\Controllers\TestHuille\HETesterController;
use App\Http\Controllers\TestHuille\HEValidationController;
use App\Http\Controllers\TestHuille\StockheController;
use App\Http\Controllers\TransfertController;
use App\Http\Controllers\Vente\ReceptionController;
use App\Http\Controllers\Vente\ClientController;
use App\Http\Controllers\Vente\ExportationController;
use App\Http\Controllers\Vente\LocalController;
use App\Http\Controllers\Vente\HistoriqueVenteLocalExportationController;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Route;


    

// ==================================================
// ROUTES PUBLIQUES (Accessibles sans authentification)
// ==================================================

    // Authentification
            Route::post('/register', [AuthController::class, 'register']);
            Route::post('/login', [AuthController::class, 'login']);
    // localisations
    Route::prefix('localisations')->group(function () {
            Route::get('/', [LocalisationController::class, 'index']);
            Route::get('/{localisation}', [LocalisationController::class, 'show']);
            Route::delete('/{localisation}', [LocalisationController::class, 'destroy']);
    });
     // provenances
    Route::prefix('provenances')->group(function () {
            Route::get('/', [ProvenancesController::class, 'index']);
            Route::post('/', [ProvenancesController::class, 'store']);
            Route::get('/{provenance}', [ProvenancesController::class, 'show']);
            Route::put('/{provenance}', [ProvenancesController::class, 'update']);
            Route::delete('/{provenance}', [ProvenancesController::class, 'destroy']);
    });
    //site-collectes
    Route::prefix('site-collectes')->group(function () {
            Route::get('/', [SiteCollecteController::class, 'index']);
            Route::post('/', [SiteCollecteController::class, 'store']);
            Route::get('/{siteCollecte}', [SiteCollecteController::class, 'show']);
            Route::put('/{siteCollecte}', [SiteCollecteController::class, 'update']);
            Route::delete('/{siteCollecte}', [SiteCollecteController::class, 'destroy']);
    });

    //verification mot de passe admin   
            Route::post('/verify-admin', [AuthController::class, 'verifyAdmin']);

Route::middleware('auth:sanctum')->group(function () {
    //Authentification
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/user', [AuthController::class, 'user']);
    //Authentification
    Route::prefix('localisations')->group(function () {
            Route::post('/', [LocalisationController::class, 'store']);
            Route::put('/{localisation}', [LocalisationController::class, 'update']);
    });
    //Caissier
    Route::prefix('caissiers')->group(function () {
            Route::get('/', [CaissierController::class, 'index']);
            Route::post('/', [CaissierController::class, 'store']);
            Route::get('/{id}', [CaissierController::class, 'show']);
            Route::put('/{id}', [CaissierController::class, 'update']);
            Route::delete('/{id}', [CaissierController::class, 'destroy']);
            Route::post('/{utilisateur_id}/ajuster', [CaissierController::class, 'ajusterSolde']);
            Route::post('/{utilisateur_id}/retirer', [CaissierController::class, 'retirerSolde']);
    });

    //transfert solde
    Route::prefix('transferts')->group(function () {
            Route::post('/', [TransfertController::class, 'store']);
            Route::get('/', [TransfertController::class, 'index']);
            Route::get('/{id}', [TransfertController::class, 'show']);
            Route::get('/utilisateur/{utilisateur_id}', [TransfertController::class, 'getTransfertsByUtilisateur']);
    });
    
            Route::get('/utilisateurs/{id}', [TransfertController::class, 'getUtilisateur']);
            Route::get('/solde-actuel', [TransfertController::class, 'getSoldeActuel']);

        // Routes pour les demandes de solde
        Route::prefix('demande-soldes')->group(function () {
        Route::get('/', [DemandeSoldeController::class, 'index']);
        Route::post('/', [DemandeSoldeController::class, 'store']);
        Route::get('/{id}', [DemandeSoldeController::class, 'show']);
        Route::put('/{id}/statut', [DemandeSoldeController::class, 'updateStatut']);
        Route::delete('/{id}', [DemandeSoldeController::class, 'destroy']);
        Route::get('/utilisateur/{utilisateur_id}', [DemandeSoldeController::class, 'mesDemandes']);
        Route::get('/statut/en-attente', [DemandeSoldeController::class, 'demandesEnAttente']);
        Route::get('/statistiques', [DemandeSoldeController::class, 'statistiques']);
        
        //  routes pour la gestion des états lu/non lu
        Route::put('/{id}/lu-utilisateur', [DemandeSoldeController::class, 'marquerCommeLuParUtilisateur']);
        Route::put('/{id}/lu-admin', [DemandeSoldeController::class, 'marquerCommeLuParAdmin']);
        Route::put('/{id}/reinitialiser-lu', [DemandeSoldeController::class, 'reinitialiserLu']);
        Route::get('/non-lues/{userId}/{role}', [DemandeSoldeController::class, 'getNonLues']);
        Route::put('/toutes-lues-utilisateur/{utilisateur_id}', [DemandeSoldeController::class, 'marquerToutesLuesParUtilisateur']);
        Route::put('/toutes-lues-admin', [DemandeSoldeController::class, 'marquerToutesLuesParAdmin']);
        });

    // Utilisateurs - toutes les routes protégées
    Route::prefix('utilisateurs')->group(function () {
            Route::get('/', [UtilisateurController::class, 'index']);
            Route::post('/', [UtilisateurController::class, 'store']);
            Route::get('/sites/collecte', [UtilisateurController::class, 'getSitesCollecte']);
            Route::get('/{utilisateur}', [UtilisateurController::class, 'show']);
            Route::put('/{utilisateur}', [UtilisateurController::class, 'update']);
            Route::delete('/{utilisateur}', [UtilisateurController::class, 'destroy']);
    });
    
    //get solde:
    Route::prefix('soldes-utilisateurs')->group(function () {
            Route::get('/', [SoldeUserController::class, 'index']);
            Route::get('/{utilisateur_id}', [SoldeUserController::class, 'show']);
    });

    // Routes pour les paiements en avance
    Route::prefix('paiements-avance')->group(function () {
            Route::get('/', [PayementEnAvanceController::class, 'index']);
            Route::post('/', [PayementEnAvanceController::class, 'store']);
            Route::get('/statistiques', [PayementEnAvanceController::class, 'statistiques']);
            Route::get('/{id}', [PayementEnAvanceController::class, 'show']);
            Route::put('/{id}/confirmer', [PayementEnAvanceController::class, 'confirmerPaiement']);
            Route::put('/{id}/annuler', [PayementEnAvanceController::class, 'annulerPaiement']);
            Route::get('/retard/en-retard', [PayementEnAvanceController::class, 'getPaiementsEnRetard']);
     
    });
    //fournisseurs
    Route::prefix('fournisseurs')->group(function () {
            Route::get('/', [FournisseurController::class, 'index']);
            Route::post('/', [FournisseurController::class, 'store']);
            Route::get('/{fournisseur}', [FournisseurController::class, 'show']);
            Route::put('/{fournisseur}', [FournisseurController::class, 'update']);
            Route::delete('/{fournisseur}', [FournisseurController::class, 'destroy']);
            Route::get('/search/{search}', [FournisseurController::class, 'search']);
    });
    //pv-receptions
    Route::prefix('pv-receptions')->group(function () {
            Route::get('/', [PVReceptionController::class, 'index']);
            Route::post('/', [PVReceptionController::class, 'store']);
            Route::get('/fournisseurs-disponibles', [PVReceptionController::class, 'getFournisseursDisponibles']);
            Route::get('/{pvReception}', [PVReceptionController::class, 'show']);
            Route::put('/{pvReception}', [PVReceptionController::class, 'update']);
            Route::delete('/{pvReception}', [PVReceptionController::class, 'destroy']);
            Route::get('/fournisseur/{fournisseur_id}', [PVReceptionController::class, 'getInfosFournisseur']);
       
    });
    //facturations PV  receptions
    Route::prefix('facturations')->group(function () {
            Route::get('/', [FacturationController::class, 'index']);
            Route::post('/', [FacturationController::class, 'store']);
            Route::get('/{id}', [FacturationController::class, 'show']);
            Route::put('/{id}', [FacturationController::class, 'update']);
            Route::post('/{id}/paiement', [FacturationController::class, 'enregistrerPaiement']);
    });

    // Routes pour les impayés pv reception
    Route::prefix('impayes')->group(function () {
            Route::get('/', [ImpayeController::class, 'index']);
            Route::post('/', [ImpayeController::class, 'store']);
            Route::get('/{id}', [ImpayeController::class, 'show']);
            Route::put('/{id}', [ImpayeController::class, 'update']);
            Route::post('/{id}/paiement', [ImpayeController::class, 'enregistrerPaiement']);
    });

        // Routes pour fiches de livraison pv reception
        Route::prefix('fiche-livraisons')->group(function () {
        Route::get('/', [FicheLivraisonController::class, 'index']);
        Route::post('/', [FicheLivraisonController::class, 'store']);
        Route::get('/{id}', [FicheLivraisonController::class, 'show']);
        Route::get('/distillateurs/disponibles', [FicheLivraisonController::class, 'getDistillateurs']);
        Route::get('/site-collecte/{nom}', [FicheLivraisonController::class, 'getBySiteCollecte']);
        // Ajoutez cette nouvelle route
        Route::get('/stocks/disponibles', [FicheLivraisonController::class, 'getStocksDisponiblesUtilisateur']);
        });
  // Routes pour stat fiche de livraison pv reception
    Route::prefix('fiche-statistique')->group(function () {
        Route::get('/', [statController::class, 'index']);

    });

    // Routes pour livraisons (confirmation) : ty fa tsy miasa angamba @zao
    Route::prefix('livraisons')->group(function () {
            Route::get('/', [LivraisonController::class, 'index']);
            Route::get('/{id}', [LivraisonController::class, 'show']);
    });

    Route::prefix('stock')->group(function () {
            Route::get('/stats', [StockController::class, 'getStockStats']);
            Route::get('/historique', [StockController::class, 'getHistoriqueMouvements']);
            Route::get('/tendances', [StockController::class, 'getTendancesStock']);
    });
    //route fiche de reception huille Ess
    Route::prefix('fiche-receptions')->group(function () {
            Route::get('/', [FicheReceptionController::class, 'index']);
            Route::post('/', [FicheReceptionController::class, 'store']);
            Route::get('/{id}', [FicheReceptionController::class, 'show']);
            Route::put('/{id}', [FicheReceptionController::class, 'update']);
            Route::delete('/{id}', [FicheReceptionController::class, 'destroy']);
            Route::get('/fournisseur/{fournisseur_id}', [FicheReceptionController::class, 'getInfosFournisseur']);
    });

    // Routes pour les tests huile
     Route::prefix('tests')->group(function () {
            Route::get('/', [HETesterController::class, 'index']);
            Route::post('/', [HETesterController::class, 'store']);
            Route::get('/{id}', [HETesterController::class, 'show']);
            Route::put('/{id}', [HETesterController::class, 'update']);
            Route::delete('/{id}', [HETesterController::class, 'destroy']);
    
    });

    // Routes pour les validations teste huile
    Route::prefix('validations')->group(function () {
            Route::get('/', [HEValidationController::class, 'index']);
            Route::post('/', [HEValidationController::class, 'store']);
            Route::get('/{id}', [HEValidationController::class, 'show']);
            Route::put('/{id}', [HEValidationController::class, 'update']);
            Route::delete('/{id}', [HEValidationController::class, 'destroy']);
        
    });

    // Routes pour la facturation Huille Ess
    Route::prefix('/he-facturations')->group(function () {
            Route::get('/', [HEFacturationController::class, 'index']);
            Route::post('/', [HEFacturationController::class, 'store']);
            Route::get('/impayes', [HEFacturationController::class, 'getImpayes']);
            Route::get('/{id}', [HEFacturationController::class, 'show']);
            Route::put('/{id}', [HEFacturationController::class, 'update']);
            Route::delete('/{id}', [HEFacturationController::class, 'destroy']);
            Route::post('/{id}/paiement', [HEFacturationController::class, 'ajouterPaiement']);
            Route::get('/statut/{statut}', [HEFacturationController::class, 'getByStatutPaiement']);
            
    });

        // Dans routes/api.php
        Route::prefix('he-fiche-livraisons')->group(function () {
        Route::get('/', [HEFicheLivraisonController::class, 'index']);
        Route::post('/', [HEFicheLivraisonController::class, 'store']);
        Route::get('/{id}', [HEFicheLivraisonController::class, 'show']);
        Route::put('/{id}', [HEFicheLivraisonController::class, 'update']);
        Route::delete('/{id}', [HEFicheLivraisonController::class, 'destroy']);
        Route::get('/livreurs/disponibles', [HEFicheLivraisonController::class, 'getLivreurs']);
        Route::get('/vendeurs/disponibles', [HEFicheLivraisonController::class, 'getVendeurs']);
        Route::get('/etat-stock', [HEFicheLivraisonController::class, 'getEtatStock']);
        Route::post('/annuler/{id}', [HEFicheLivraisonController::class, 'annulerLivraison']);
        Route::post('/verifier-stock', [HEFicheLivraisonController::class, 'verifierStockDisponible']);
        Route::get('/stocks/disponibles', [HEFicheLivraisonController::class, 'getStocksDisponiblesUtilisateur']);
        // Nouvelle route pour vérifier si on peut créer une fiche
        Route::get('/verifier-creation', [HEFicheLivraisonController::class, 'peutCreerFicheLivraison']);
        });

    // Routes pour la gestion des livraisons Huille Essentiel
    Route::prefix('he-livraisons')->group(function () {
            Route::post('/{fiche_reception_id}/demarrer', [HELivraisonController::class, 'demarrerLivraison']);
            Route::post('/{fiche_reception_id}/terminer', [HELivraisonController::class, 'terminerLivraison']);
            Route::get('/en-attente', [HELivraisonController::class, 'getEnAttenteLivraison']);
            Route::get('/en-cours', [HELivraisonController::class, 'getEnCoursLivraison']);
            Route::get('/livrees', [HELivraisonController::class, 'getLivrees']);
    });
    //Route pour impayee Huille Essentiel
    Route::prefix('he-impayes')->group(function () {
            Route::get('/', [HEImpayeController::class, 'index']);
            Route::post('/', [HEImpayeController::class, 'store']);
            Route::get('/actifs', [HEImpayeController::class, 'getImpayesActifs']);
            Route::get('/{id}', [HEImpayeController::class, 'show']);
            Route::put('/{id}', [HEImpayeController::class, 'update']);
            Route::delete('/{id}', [HEImpayeController::class, 'destroy']);
    });
    //Route pour livreurs
    Route::prefix('livreurs')->group(function () {
            Route::get('/', [LivreurControlleur::class, 'index']);
            Route::post('/', [LivreurControlleur::class, 'store']);
            Route::get('/stats', [LivreurControlleur::class, 'stats']);
            Route::get('/{id}', [LivreurControlleur::class, 'show']);
            Route::put('/{id}', [LivreurControlleur::class, 'update']);
            Route::delete('/{id}', [LivreurControlleur::class, 'destroy']);
            Route::get('/utilisateur/{userId}', [LivreurControlleur::class, 'getByUser']);
    });

    // Routes pour Destinateurs
    Route::prefix('destinateurs')->group(function () {
            Route::get('/', [DestinateurControlleur::class, 'index']);
            Route::post('/', [DestinateurControlleur::class, 'store']);
            Route::get('/{id}', [DestinateurControlleur::class, 'show']);
            Route::put('/{id}', [DestinateurControlleur::class, 'update']);
            Route::delete('/{id}', [DestinateurControlleur::class, 'destroy']);
            Route::get('/utilisateur/{userId}', [DestinateurControlleur::class, 'getByUser']);
    });
    //Route expedition (get manka aza rehetra de receptionner efa kop elaa)
        Route::prefix('expeditions')->group(function () {
        Route::get('/', [ExpeditionController::class, 'index']);
        Route::post('/{expeditionId}/receptionner', [ExpeditionController::class, 'marquerReceptionne']);
        });
    //Route distillations 
        Route::prefix('distillations')->group(function () {
        Route::get('/', [DistillationController::class, 'index']);
        Route::post('/{distillationId}/demarrer', [DistillationController::class, 'demarrerDistillation']);
        Route::post('/{distillationId}/terminer', [DistillationController::class, 'terminerDistillation']);
        });
//Transport 
Route::prefix('transports')->group(function () {
    
    Route::get('/distillations-sans-transport', [TransportController::class, 'getDistillationsSansTransport']);
    Route::get('/distillations-disponibles', [TransportController::class, 'getDistillationsDisponibles']); // NOUVELLE
    
    Route::get('/vendeurs-disponibles', [TransportController::class, 'getVendeursDisponibles']);
    Route::get('/livreurs-disponibles', [TransportController::class, 'getLivreursDisponibles']);

    // Route de création (accepte maintenant un tableau de transports)
    Route::post('/creer', [TransportController::class, 'creerTransport']);
    
    // Route pour voir ses propres transports avec filtres
    Route::get('/mes-transports', [TransportController::class, 'getMesTransports']);
    
    // Routes existantes
    Route::get('/en-cours', [TransportController::class, 'getTransportsEnCours']);
    Route::get('/livre', [TransportController::class, 'getTransportsLivre']);
    Route::post('/{transportId}/livre', [TransportController::class, 'marquerLivre']);
});



        Route::prefix('matiere-premiere/stock')->group(function () {
                Route::get('/', [StockPvReceptionController::class, 'getEtatStock']);
                Route::get('/utilisateur/{userId?}', [StockPvReceptionController::class, 'getStockUtilisateur']);
                // Routes pour compatibilité (version simple sans utilisateur)
                Route::get('/stock-he/simple', [StockheController::class, 'getEtatStockSimple']);
                Route::get('/stock-he/verifier-simple', [StockheController::class, 'verifierDisponibiliteSimple']);

        });

        Route::prefix('stock-he')->group(function () {
        Route::get('/', [StockheController::class, 'getEtatStock']);
        Route::get('/verifier', [StockheController::class, 'verifierDisponibilite']);
        // Routes Admin seulement
        Route::get('/tous-utilisateurs', [StockheController::class, 'getStockTousUtilisateurs']);
        Route::get('/utilisateur/{userId}', [StockheController::class, 'getStockUtilisateur']);
        Route::get('/liste-utilisateurs', [StockheController::class, 'getListeUtilisateurs']);
        
        // Route simple pour compatibilité
        Route::get('/simple', [StockheController::class, 'getEtatStockSimple']);
        });

// Dans routes/api.php
Route::prefix('receptions')->group(function () {
    Route::get('/', [ReceptionController::class, 'index']);
    Route::get('/recues', [ReceptionController::class, 'getRecues']);
    Route::get('/mes-receptions', [ReceptionController::class, 'getMesReceptions']);
    Route::get('/stats-cartes', [ReceptionController::class, 'getStatsCartes']); // AJOUTER CETTE LIGNE
    Route::post('/{id}/marquer-receptionne', [ReceptionController::class, 'marquerReceptionne']);
});
         // Clients 
         Route::prefix('clients')->group(function () {
          Route::get('/', [ClientController::class, 'index']);
          Route::post('/', [ClientController::class, 'store']);
          Route::get('/{client}', [ClientController::class, 'show']);
          Route::put('/{client}', [ClientController::class, 'update']);
          Route::delete('/{client}', [ClientController::class, 'destroy']);
         });

        // Exportations 
        Route::prefix('exportations')->group(function () {
          Route::get('/', [ExportationController::class, 'index']);
          Route::post('/', [ExportationController::class, 'store']);
          Route::get('/{exportation}', [ExportationController::class, 'show']);
          Route::put('/{exportation}', [ExportationController::class, 'update']);
          Route::delete('/{exportation}', [ExportationController::class, 'destroy']);
        });

        // Vente locale
        Route::prefix('locals')->group(function () {
          Route::get('/', [LocalController::class, 'index']);
          Route::post('/', [LocalController::class, 'store']);
          Route::get('/{local}', [LocalController::class, 'show']);
          Route::put('/{local}', [LocalController::class, 'update']);
          Route::delete('/{local}', [LocalController::class,'destroy']);
        });

        // Historique: affiche locals et exportations
        Route::get('/historique-vente', [HistoriqueVenteLocalExportationController::class, 'index']);

      
        Route::prefix('distillation-stat')->group(function () {
        // Statistiques
        Route::get('/', [StatistiqueController::class, 'index']);
        Route::post('/statistiques/par-periode', [StatistiqueController::class, 'parPeriode']);
        });

 
        // Routes pour la gestion des soldes distillation
        Route::prefix('gestion-solde-distilleur')->group(function () {
        Route::post('/retrait', [GestionSoldeController::class, 'retrait']);
        Route::get('/mon-solde', [GestionSoldeController::class, 'monSolde']);
        Route::get('/historique-retraits', [GestionSoldeController::class, 'historiqueRetraits']);
        Route::get('/detail-retrait/{id}', [GestionSoldeController::class, 'detailRetrait']);
        });





















Route::prefix('stocks-distillation')->group(function () {
    Route::get('/', [StockDistillationController::class, 'index']);
    Route::get('/disponibles', [StockDistillationController::class, 'getStocksDisponibles']);
    Route::get('/lots/{typeProduit}', [StockDistillationController::class, 'getLotsParType']); // Nouvelle
    Route::get('/historique', [StockDistillationController::class, 'getHistoriqueMouvements']); // Nouvelle
});




// routes/api.php Collecte
Route::prefix('dashboard')->group(function () {
    Route::get('collecteur/tableau-de-bord', [CollecteurControlleur::class, 'tableauDeBord']);
    Route::get('collecteur/stats-rapides', [CollecteurControlleur::class, 'statsRapides']);
    Route::get('collecteur/localisation/{id}', [CollecteurControlleur::class, 'detailsLocalisation']);
});

//Rapport collecteur
Route::prefix('dashboard-2')->group(function () {
    Route::get('collecteurs', [InfoCollecteurController::class, 'listeCollecteurs']);
    Route::get('collecteurs/{id}', [InfoCollecteurController::class, 'detailsCollecteur']);
    Route::get('collecteurs/localisation/{id}', [InfoCollecteurController::class, 'getCollecteursParLocalisation']);
    Route::get('collecteurs/rechercher', [InfoCollecteurController::class, 'rechercherCollecteurs']);
    Route::get('collecteurs/dashboard/resume', [InfoCollecteurController::class, 'dashboardResume']);
});

//Distillation
Route::prefix('dashboard-3')->group(function () {
    Route::get('/stock-huile-essentielle', [DistillationDashController::class, 'getStockHuileEssentielle']);
    Route::get('/resume-production', [DistillationDashController::class, 'getResumeProduction']);
    Route::get('/usines-disponibles', [DistillationDashController::class, 'getUsinesDisponibles']);
    Route::get('/complet', [DistillationDashController::class, 'getDashboardComplet']);
    Route::get('/statistiques-temps-reel', [DistillationDashController::class, 'getStatistiquesTempsReel']);
    Route::get('/rendement-moyen', [DistillationDashController::class, 'getRendementMoyen']);
    Route::get('/tendances-production', [DistillationDashController::class, 'getTendancesProduction']);
    Route::get('/statistiques-distilleur', [DistillationDashController::class, 'getStatistiquesParDistilleur']);
    Route::get('/alertes-stock-bas', [DistillationDashController::class, 'getAlertesStockBas']);
});

});