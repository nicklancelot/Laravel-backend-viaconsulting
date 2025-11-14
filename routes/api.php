<?php

use App\Http\Controllers\AuthController;
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

    // Route::get('/provenances', [ProvenancesController::class, 'index']);
    // Route::post('/provenances', [ProvenancesController::class, 'store']);
    // Route::get('/provenances/{provenance}', [ProvenancesController::class, 'show']);
    // Route::put('/provenances/{provenance}', [ProvenancesController::class, 'update']);
    // Route::delete('/provenances/{provenance}', [ProvenancesController::class, 'destroy']);

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

    // Routes pour la facturation
    Route::get('/facturations', [HEFacturationController::class, 'index']);
    Route::post('/facturations', [HEFacturationController::class, 'store']);
    Route::get('/facturations/{id}', [HEFacturationController::class, 'show']);
    Route::put('/facturations/{id}', [HEFacturationController::class, 'update']);
    Route::delete('/facturations/{id}', [HEFacturationController::class, 'destroy']);
    Route::post('/facturations/{id}/paiement', [HEFacturationController::class, 'ajouterPaiement']);
    Route::get('/facturations/statut/{statut}', [HEFacturationController::class, 'getByStatutPaiement']);
    Route::get('/facturations/impayes', [HEFacturationController::class, 'getImpayes']);

    // Routes pour les fiches de livraison (CRUD complet)
    Route::get('/fiche-livraisons', [HEFicheLivraisonController::class, 'index']);
    Route::post('/fiche-livraisons', [HEFicheLivraisonController::class, 'store']);
    Route::get('/fiche-livraisons/{id}', [HEFicheLivraisonController::class, 'show']);
    Route::put('/fiche-livraisons/{id}', [HEFicheLivraisonController::class, 'update']);
    Route::delete('/fiche-livraisons/{id}', [HEFicheLivraisonController::class, 'destroy']);
    Route::get('/fiche-livraisons/fiche/{fiche_reception_id}', [HEFicheLivraisonController::class, 'getByFicheReception']);

    // Routes pour la gestion des livraisons
    Route::post('/livraisons/{fiche_reception_id}/demarrer', [HELivraisonController::class, 'demarrerLivraison']);
    Route::post('/livraisons/{fiche_reception_id}/terminer', [HELivraisonController::class, 'terminerLivraison']);
    Route::get('/livraisons/en-attente', [HELivraisonController::class, 'getEnAttenteLivraison']);
    Route::get('/livraisons/en-cours', [HELivraisonController::class, 'getEnCoursLivraison']);
    Route::get('/livraisons/livrees', [HELivraisonController::class, 'getLivrees']);
});






//teste avec postman
//masquer apres:
    // Route::post('/localisations', [LocalisationController::class, 'store']);
    //   Route::put('/localisations/{localisation}', [LocalisationController::class, 'update']);


    // Utilisateurs - toutes les routes protégées
    // Route::get('/utilisateurs', [UtilisateurController::class, 'index']);
    // Route::post('/utilisateurs', [UtilisateurController::class, 'store']);
    // Route::get('/utilisateurs/{utilisateur}', [UtilisateurController::class, 'show']);
    // Route::put('/utilisateurs/{utilisateur}', [UtilisateurController::class, 'update']);
    // Route::delete('/utilisateurs/{utilisateur}', [UtilisateurController::class, 'destroy']);

    // Route::get('/fournisseurs', [FournisseurController::class, 'index']);
    // Route::post('/fournisseurs', [FournisseurController::class, 'store']);
    // Route::get('/fournisseurs/{fournisseur}', [FournisseurController::class, 'show']);
    // Route::put('/fournisseurs/{fournisseur}', [FournisseurController::class, 'update']);
    // Route::delete('/fournisseurs/{fournisseur}', [FournisseurController::class, 'destroy']);
    // Route::get('/fournisseurs/search/{search}', [FournisseurController::class, 'search']);

    // Route::get('/fiche-receptions', [FicheReceptionController::class, 'getAllFiches']);
    // Route::get('/fiche-receptions/{id}', [FicheReceptionController::class, 'getFicheById']);
    // Route::post('/fiche-receptions', [FicheReceptionController::class, 'createFiche']);
    // Route::put('/fiche-receptions/{id}', [FicheReceptionController::class, 'updateFiche']);
    // Route::delete('/fiche-receptions/{id}', [FicheReceptionController::class, 'deleteFiche']);
    // Route::get('/fiche-receptions/statut/{statut}', [FicheReceptionController::class, 'getFichesByStatut']);

    // Route::post('/tests/demarrer/{ficheId}', [HETesterController::class, 'demarrerTest']);
    // Route::post('/tests/resultats/{testId}', [HETesterController::class, 'enregistrerResultats']);
    // Route::get('/tests/fiche/{ficheId}', [HETesterController::class, 'getTestsByFiche']);
    // Route::get('/tests/{id}', [HETesterController::class, 'getTestById']);
    // Route::get('/tests/en-cours', [HETesterController::class, 'getTestsEnCours']);
    // Route::post('/tests/annuler/{testId}', [HETesterController::class, 'annulerTest']);


    // Route::post('/validations/decider/{ficheId}', [HEValidationController::class, 'enregistrerDecision']);
    // Route::get('/validations/fiche/{ficheId}', [HEValidationController::class, 'getValidationsByFiche']);
    // Route::get('/validations/{id}', [HEValidationController::class, 'getValidationById']);
    // Route::get('/validations/historique/all', [HEValidationController::class, 'getHistoriqueValidations']);


    // Route::post('/facturations/creer/{ficheId}', [HEFacturationController::class, 'creerFacturation']);
    // Route::get('/facturations/fiche/{ficheId}', [HEFacturationController::class, 'getFacturationByFiche']);
    // Route::put('/facturations/{id}', [HEFacturationController::class, 'updateFacturation']);
    // Route::get('/facturations/historique/all', [HEFacturationController::class, 'getHistoriqueFacturations']);


// route commenter pour tester sans auth