<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CaissierController;
use App\Http\Controllers\DemandeSoldeController;
use App\Http\Controllers\DestinateurControlleur;
use App\Http\Controllers\LivreurControlleur;
use App\Http\Controllers\LocalisationController;
use App\Http\Controllers\MatierePremiere\FacturationController;
use App\Http\Controllers\MatierePremiere\FicheLivraisonController;
use App\Http\Controllers\MatierePremiere\FournisseurController;
use App\Http\Controllers\MatierePremiere\ImpayeController;
use App\Http\Controllers\MatierePremiere\LivraisonController;
use App\Http\Controllers\MatierePremiere\PVReceptionController;
use App\Http\Controllers\MatierePremiere\StockController;
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
use App\Http\Controllers\TransfertController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

            // Routes publiques
            Route::post('/register', [AuthController::class, 'register']);
            Route::post('/login', [AuthController::class, 'login']);

    Route::prefix('localisations')->group(function () {
            Route::get('/', [LocalisationController::class, 'index']);
            Route::get('/{localisation}', [LocalisationController::class, 'show']);
            Route::delete('/{localisation}', [LocalisationController::class, 'destroy']);
    });

    Route::prefix('provenances')->group(function () {
            Route::get('/', [ProvenancesController::class, 'index']);
            Route::post('/', [ProvenancesController::class, 'store']);
            Route::get('/{provenance}', [ProvenancesController::class, 'show']);
            Route::put('/{provenance}', [ProvenancesController::class, 'update']);
            Route::delete('/{provenance}', [ProvenancesController::class, 'destroy']);
    });

    Route::prefix('site-collectes')->group(function () {
            Route::get('/', [SiteCollecteController::class, 'index']);
            Route::post('/', [SiteCollecteController::class, 'store']);
            Route::get('/{siteCollecte}', [SiteCollecteController::class, 'show']);
            Route::put('/{siteCollecte}', [SiteCollecteController::class, 'update']);
            Route::delete('/{siteCollecte}', [SiteCollecteController::class, 'destroy']);
    });

            // Nouvelle route pour la vérification admin
            Route::post('/verify-admin', [AuthController::class, 'verifyAdmin']);

Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/user', [AuthController::class, 'user']);
    
    Route::prefix('localisations')->group(function () {
            Route::post('/', [LocalisationController::class, 'store']);
            Route::put('/{localisation}', [LocalisationController::class, 'update']);
    });

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
    });

    // Utilisateurs - toutes les routes protégées
    Route::prefix('utilisateurs')->group(function () {
            Route::get('/', [UtilisateurController::class, 'index']);
            Route::post('/', [UtilisateurController::class, 'store']);
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

    Route::prefix('fournisseurs')->group(function () {
            Route::get('/', [FournisseurController::class, 'index']);
            Route::post('/', [FournisseurController::class, 'store']);
            Route::get('/{fournisseur}', [FournisseurController::class, 'show']);
            Route::put('/{fournisseur}', [FournisseurController::class, 'update']);
            Route::delete('/{fournisseur}', [FournisseurController::class, 'destroy']);
            Route::get('/search/{search}', [FournisseurController::class, 'search']);
    });

    Route::prefix('pv-receptions')->group(function () {
            Route::get('/', [PVReceptionController::class, 'index']);
            Route::post('/', [PVReceptionController::class, 'store']);
            Route::get('/fournisseurs-disponibles', [PVReceptionController::class, 'getFournisseursDisponibles']);
            Route::get('/{pvReception}', [PVReceptionController::class, 'show']);
            Route::put('/{pvReception}', [PVReceptionController::class, 'update']);
            Route::delete('/{pvReception}', [PVReceptionController::class, 'destroy']);
            Route::get('/fournisseur/{fournisseur_id}', [PVReceptionController::class, 'getInfosFournisseur']);
       
    });

    Route::prefix('facturations')->group(function () {
            Route::get('/', [FacturationController::class, 'index']);
            Route::post('/', [FacturationController::class, 'store']);
            Route::get('/{id}', [FacturationController::class, 'show']);
            Route::put('/{id}', [FacturationController::class, 'update']);
            Route::post('/{id}/paiement', [FacturationController::class, 'enregistrerPaiement']);
    });

    // Routes pour les impayés
    Route::prefix('impayes')->group(function () {
            Route::get('/', [ImpayeController::class, 'index']);
            Route::post('/', [ImpayeController::class, 'store']);
            Route::get('/{id}', [ImpayeController::class, 'show']);
            Route::put('/{id}', [ImpayeController::class, 'update']);
            Route::post('/{id}/paiement', [ImpayeController::class, 'enregistrerPaiement']);
    });

    // Routes pour fiches de livraison
    Route::prefix('fiche-livraisons')->group(function () {
            Route::get('/', [FicheLivraisonController::class, 'index']);
            Route::post('/', [FicheLivraisonController::class, 'store']);
            Route::get('/{id}', [FicheLivraisonController::class, 'show']);
            Route::post('/{id}/livrer', [FicheLivraisonController::class, 'livrer']); 
            Route::post('/{id}/livrer-partielle', [FicheLivraisonController::class, 'livrerPartielle']);
    });
    Route::prefix('fiche-statistique')->group(function () {
            Route::get('/', [statController::class, 'index']);

    });

    // Routes pour livraisons (confirmation)
    Route::prefix('livraisons')->group(function () {
            Route::get('/', [LivraisonController::class, 'index']);
            Route::get('/{id}', [LivraisonController::class, 'show']);
    });

    Route::prefix('stock')->group(function () {
            Route::get('/stats', [StockController::class, 'getStockStats']);
            Route::get('/historique', [StockController::class, 'getHistoriqueMouvements']);
            Route::get('/tendances', [StockController::class, 'getTendancesStock']);
    });

    Route::prefix('fiche-receptions')->group(function () {
            Route::get('/', [FicheReceptionController::class, 'index']);
            Route::post('/', [FicheReceptionController::class, 'store']);
            Route::get('/{id}', [FicheReceptionController::class, 'show']);
            Route::put('/{id}', [FicheReceptionController::class, 'update']);
            Route::delete('/{id}', [FicheReceptionController::class, 'destroy']);
            Route::get('/fournisseur/{fournisseur_id}', [FicheReceptionController::class, 'getInfosFournisseur']);
    });

    // Routes pour les tests
     Route::prefix('tests')->group(function () {
            Route::get('/', [HETesterController::class, 'index']);
            Route::post('/', [HETesterController::class, 'store']);
            Route::get('/{id}', [HETesterController::class, 'show']);
            Route::put('/{id}', [HETesterController::class, 'update']);
            Route::delete('/{id}', [HETesterController::class, 'destroy']);
    
    });

    // Routes pour les validations
    Route::prefix('validations')->group(function () {
            Route::get('/', [HEValidationController::class, 'index']);
            Route::post('/', [HEValidationController::class, 'store']);
            Route::get('/{id}', [HEValidationController::class, 'show']);
            Route::put('/{id}', [HEValidationController::class, 'update']);
            Route::delete('/{id}', [HEValidationController::class, 'destroy']);
        
    });

    // Routes pour la facturation
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

    // Routes pour les fiches de livraison (CRUD complet)
    Route::prefix('he-fiche-livraisons')->group(function () {
            Route::get('/', [HEFicheLivraisonController::class, 'index']);
            Route::post('/', [HEFicheLivraisonController::class, 'store']);
            Route::get('/livreurs', [HEFicheLivraisonController::class, 'getLivreurs']);
            Route::get('/destinateurs', [HEFicheLivraisonController::class, 'getDestinateurs']);
            Route::get('/{id}', [HEFicheLivraisonController::class, 'show']);
            Route::put('/{id}', [HEFicheLivraisonController::class, 'update']);
            Route::delete('/{id}', [HEFicheLivraisonController::class, 'destroy']);
            Route::get('/fiche/{fiche_reception_id}', [HEFicheLivraisonController::class, 'getByFicheReception']);

    });

    // Routes pour la gestion des livraisons
    Route::prefix('he-livraisons')->group(function () {
            Route::post('/{fiche_reception_id}/demarrer', [HELivraisonController::class, 'demarrerLivraison']);
            Route::post('/{fiche_reception_id}/terminer', [HELivraisonController::class, 'terminerLivraison']);
            Route::get('/en-attente', [HELivraisonController::class, 'getEnAttenteLivraison']);
            Route::get('/en-cours', [HELivraisonController::class, 'getEnCoursLivraison']);
            Route::get('/livrees', [HELivraisonController::class, 'getLivrees']);
    });

    Route::prefix('he-impayes')->group(function () {
            Route::get('/', [HEImpayeController::class, 'index']);
            Route::post('/', [HEImpayeController::class, 'store']);
            Route::get('/actifs', [HEImpayeController::class, 'getImpayesActifs']);
            Route::get('/{id}', [HEImpayeController::class, 'show']);
            Route::put('/{id}', [HEImpayeController::class, 'update']);
            Route::delete('/{id}', [HEImpayeController::class, 'destroy']);
    });

    Route::prefix('livreurs')->group(function () {
            Route::get('/', [LivreurControlleur::class, 'index']);
            Route::post('/', [LivreurControlleur::class, 'store']);
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
});