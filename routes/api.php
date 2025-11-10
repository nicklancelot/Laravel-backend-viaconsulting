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
use App\Http\Controllers\UtilisateurController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Routes publiques
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/localisations', [LocalisationController::class, 'index']);
Route::get('/localisations/{localisation}', [LocalisationController::class, 'show']);

// Nouvelle route pour la vérification admin
Route::post('/verify-admin', [AuthController::class, 'verifyAdmin']);

// Routes protégées par authentification
Route::middleware('auth:sanctum')->group(function () {
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // Localisations - routes protégées (écriture)
    Route::post('/localisations', [LocalisationController::class, 'store']);
    Route::put('/localisations/{localisation}', [LocalisationController::class, 'update']);
    Route::delete('/localisations/{localisation}', [LocalisationController::class, 'destroy']);

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
});