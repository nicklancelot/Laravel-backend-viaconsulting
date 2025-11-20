<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CaissierController;
use App\Http\Controllers\LocalisationController;
use App\Http\Controllers\MatierePremiere\FacturationController;
use App\Http\Controllers\MatierePremiere\FicheLivraisonController;
use App\Http\Controllers\MatierePremiere\FournisseurController;
use App\Http\Controllers\MatierePremiere\ImpayeController;
use App\Http\Controllers\MatierePremiere\LivraisonController;
use App\Http\Controllers\MatierePremiere\PVReceptionController;
use App\Http\Controllers\MatierePremiere\StockController;
use App\Http\Controllers\ProvenancesController;
use App\Http\Controllers\SiteCollecteController;
use App\Http\Controllers\UtilisateurController;
use App\Http\Controllers\TestHuille\FicheReceptionController;
use App\Http\Controllers\TestHuille\HELivraisonController;
use App\Http\Controllers\TestHuille\HEFacturationController;
use App\Http\Controllers\TestHuille\HEFicheLivraisonController;
use App\Http\Controllers\TestHuille\HETesterController;
use App\Http\Controllers\TestHuille\HEValidationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

    // Routes publiques
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::get('/localisations', [LocalisationController::class, 'index']);
    Route::get('/localisations/{localisation}', [LocalisationController::class, 'show']);
    Route::delete('/localisations/{localisation}', [LocalisationController::class, 'destroy']);

    Route::get('/provenances', [ProvenancesController::class, 'index']);
    Route::post('/provenances', [ProvenancesController::class, 'store']);
    Route::get('/provenances/{provenance}', [ProvenancesController::class, 'show']);
    Route::put('/provenances/{provenance}', [ProvenancesController::class, 'update']);
    Route::delete('/provenances/{provenance}', [ProvenancesController::class, 'destroy']);

    Route::get('/site-collectes', [SiteCollecteController::class, 'index']);
    Route::post('/site-collectes', [SiteCollecteController::class, 'store']);
    Route::get('/site-collectes/{siteCollecte}', [SiteCollecteController::class, 'show']);
    Route::put('/site-collectes/{siteCollecte}', [SiteCollecteController::class, 'update']);
    Route::delete('/site-collectes/{siteCollecte}', [SiteCollecteController::class, 'destroy']);


    // Nouvelle route pour la vérification admin
    Route::post('/verify-admin', [AuthController::class, 'verifyAdmin']);

    // Routes protégées par authentification
Route::middleware('auth:sanctum')->group(function () {
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // //Localisations - routes protégées (écriture)
    Route::post('/localisations', [LocalisationController::class, 'store']);
    Route::put('/localisations/{localisation}', [LocalisationController::class, 'update']);
    // Route::delete('/localisations/{localisation}', [LocalisationController::class, 'destroy']);



    // Utilisateurs - toutes les routes protégées
    Route::get('/utilisateurs', [UtilisateurController::class, 'index']);
    Route::post('/utilisateurs', [UtilisateurController::class, 'store']);
    Route::get('/utilisateurs/{utilisateur}', [UtilisateurController::class, 'show']);
    Route::put('/utilisateurs/{utilisateur}', [UtilisateurController::class, 'update']);
    Route::delete('/utilisateurs/{utilisateur}', [UtilisateurController::class, 'destroy']);

    Route::get('/fournisseurs', [FournisseurController::class, 'index']);
    Route::post('/fournisseurs', [FournisseurController::class, 'store']);
    Route::get('/fournisseurs/{fournisseur}', [FournisseurController::class, 'show']);
    Route::put('/fournisseurs/{fournisseur}', [FournisseurController::class, 'update']);
    Route::delete('/fournisseurs/{fournisseur}', [FournisseurController::class, 'destroy']);
    Route::get('/fournisseurs/search/{search}', [FournisseurController::class, 'search']);

        // Routes solde
    Route::get('/caissiers', [CaissierController::class, 'index']);
    Route::get('/caissiers/{id}', [CaissierController::class, 'show']);
    //eto mi retrait/ajouster solde raha ilaina
    Route::post('/caissiers', [CaissierController::class, 'store']);
    Route::put('/caissiers/{id}', [CaissierController::class, 'update']);
    Route::delete('/caissiers/{id}', [CaissierController::class, 'destroy']);
    Route::post('/caissiers/{utilisateur_id}/ajuster', [CaissierController::class, 'ajusterSolde']);
    Route::post('/caissiers/{utilisateur_id}/retirer', [CaissierController::class, 'retirerSolde']);


   
    Route::get('/pv-receptions', [PVReceptionController::class, 'index']);
    Route::post('/pv-receptions', [PVReceptionController::class, 'store']);
    Route::get('/pv-receptions/{pvReception}', [PVReceptionController::class, 'show']);
    Route::put('/pv-receptions/{pvReception}', [PVReceptionController::class, 'update']);
    Route::delete('/pv-receptions/{pvReception}', [PVReceptionController::class, 'destroy']);

    Route::get('/facturations', [FacturationController::class, 'index']);
    Route::post('/facturations', [FacturationController::class, 'store']);
    Route::get('/facturations/{id}', [FacturationController::class, 'show']);
    Route::put('/facturations/{id}', [FacturationController::class, 'update']);
    Route::post('/facturations/{id}/paiement', [FacturationController::class, 'enregistrerPaiement']);

    // Routes pour les impayés
    Route::get('/impayes', [ImpayeController::class, 'index']);
    Route::post('/impayes', [ImpayeController::class, 'store']);
    Route::get('/impayes/{id}', [ImpayeController::class, 'show']);
    Route::put('/impayes/{id}', [ImpayeController::class, 'update']);
    Route::post('/impayes/{id}/paiement', [ImpayeController::class, 'enregistrerPaiement']);

    // Routes pour fiches de livraison
    Route::get('/fiche-livraisons', [FicheLivraisonController::class, 'index']);
    Route::post('/fiche-livraisons', [FicheLivraisonController::class, 'store']);
    Route::get('/fiche-livraisons/{id}', [FicheLivraisonController::class, 'show']);
    Route::post('/fiche-livraisons/{id}/livrer', [FicheLivraisonController::class, 'livrer']); 
    Route::post('/fiche-livraisons/{id}/livrer-partielle', [FicheLivraisonController::class, 'livrerPartielle']);

    // Routes pour livraisons (confirmation)
    Route::get('/livraisons', [LivraisonController::class, 'index']);
    Route::get('/livraisons/{id}', [LivraisonController::class, 'show']);

    Route::get('/stock/stats', [StockController::class, 'getStockStats']);
    Route::get('/stock/historique', [StockController::class, 'getHistoriqueMouvements']);
    Route::get('/stock/tendances', [StockController::class, 'getTendancesStock']);




    Route::get('/fiche-receptions', [FicheReceptionController::class, 'index']);
    Route::post('/fiche-receptions', [FicheReceptionController::class, 'store']);
    Route::get('/fiche-receptions/{id}', [FicheReceptionController::class, 'show']);
    Route::put('/fiche-receptions/{id}', [FicheReceptionController::class, 'update']);
    Route::delete('/fiche-receptions/{id}', [FicheReceptionController::class, 'destroy']);


    // Routes pour les tests
    Route::get('/tests', [HETesterController::class, 'index']);
    Route::post('/tests', [HETesterController::class, 'store']);
    Route::get('/tests/{id}', [HETesterController::class, 'show']);
    Route::put('/tests/{id}', [HETesterController::class, 'update']);
    Route::delete('/tests/{id}', [HETesterController::class, 'destroy']);
    Route::post('/tests/{id}/terminer', [HETesterController::class, 'terminerTest']);
    Route::get('/tests/en-cours', [HETesterController::class, 'testsEnCours']);
    Route::get('/tests/termines', [HETesterController::class, 'testsTermines']);


// Routes pour les validations
    Route::get('/validations', [HEValidationController::class, 'index']);
    Route::post('/validations', [HEValidationController::class, 'store']);
    Route::get('/validations/{id}', [HEValidationController::class, 'show']);
    Route::put('/validations/{id}', [HEValidationController::class, 'update']);
    Route::delete('/validations/{id}', [HEValidationController::class, 'destroy']);
    Route::get('/validations/fiche/{fiche_reception_id}', [HEValidationController::class, 'getByFicheReception']);

    // Routes pour la facturation Huile essentiel
    Route::get('/he-facturations', [HEFacturationController::class, 'index']);
    Route::post('/he-facturations', [HEFacturationController::class, 'store']);
    Route::get('/he-facturations/{id}', [HEFacturationController::class, 'show']);
    Route::put('/he-facturations/{id}', [HEFacturationController::class, 'update']);
    Route::delete('/he-facturations/{id}', [HEFacturationController::class, 'destroy']);
    Route::post('/he-facturations/{id}/paiement', [HEFacturationController::class, 'ajouterPaiement']);
    Route::get('/he-facturations/statut/{statut}', [HEFacturationController::class, 'getByStatutPaiement']);
    Route::get('/he-facturations/impayes', [HEFacturationController::class, 'getImpayes']);

    // Routes pour les fiches de livraison (CRUD complet)
    Route::get('/he-fiche-livraisons', [HEFicheLivraisonController::class, 'index']);
    Route::post('/he-fiche-livraisons', [HEFicheLivraisonController::class, 'store']);
    Route::get('/he-fiche-livraisons/{id}', [HEFicheLivraisonController::class, 'show']);
    Route::put('/he-fiche-livraisons/{id}', [HEFicheLivraisonController::class, 'update']);
    Route::delete('/he-fiche-livraisons/{id}', [HEFicheLivraisonController::class, 'destroy']);
    Route::get('/he-fiche-livraisons/fiche/{fiche_reception_id}', [HEFicheLivraisonController::class, 'getByFicheReception']);

    // Routes pour la gestion des livraisons
    Route::post('/he-livraisons/{fiche_reception_id}/demarrer', [HELivraisonController::class, 'demarrerLivraison']);
    Route::post('/he-livraisons/{fiche_reception_id}/terminer', [HELivraisonController::class, 'terminerLivraison']);
    Route::get('/he-livraisons/en-attente', [HELivraisonController::class, 'getEnAttenteLivraison']);
    Route::get('/he-livraisons/en-cours', [HELivraisonController::class, 'getEnCoursLivraison']);
    Route::get('/he-livraisons/livrees', [HELivraisonController::class, 'getLivrees']);
});




