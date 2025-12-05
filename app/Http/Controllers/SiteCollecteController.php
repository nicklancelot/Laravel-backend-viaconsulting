<?php

namespace App\Http\Controllers;

use App\Models\SiteCollecte;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SiteCollecteController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $siteCollectes = SiteCollecte::all();
            return response()->json([
                'success' => true,
                'data' => $siteCollectes
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des sites de collecte: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des sites de collecte',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'Nom' => 'required|string|max:50|unique:site_collectes'
            ]);

            $siteCollecte = SiteCollecte::create($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Site de collecte créé avec succès',
                'data' => $siteCollecte
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la création du site de collecte: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du site de collecte',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function show(SiteCollecte $siteCollecte): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $siteCollecte
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération du site de collecte: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du site de collecte',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function update(Request $request, SiteCollecte $siteCollecte): JsonResponse
    {
        try {
            $request->validate([
                'Nom' => 'required|string|max:50|unique:site_collectes,Nom,' . $siteCollecte->id
            ]);

            $siteCollecte->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Site de collecte mis à jour avec succès',
                'data' => $siteCollecte
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour du site de collecte: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du site de collecte',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function destroy(SiteCollecte $siteCollecte): JsonResponse
    {
        try {
            $siteCollecte->delete();

            return response()->json([
                'success' => true,
                'message' => 'Site de collecte supprimé avec succès'
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression du site de collecte: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression du site de collecte',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }
}