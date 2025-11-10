<?php

namespace App\Http\Controllers;

use App\Models\Localisation;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class LocalisationController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $localisations = Localisation::all();
            return response()->json([
                'success' => true,
                'data' => $localisations
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des localisations: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des localisations',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'Nom' => 'required|string|max:50|unique:localisations'
            ]);

            $localisation = Localisation::create($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Localisation créée avec succès',
                'data' => $localisation
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la création de la localisation: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de la localisation',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function show(Localisation $localisation): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $localisation
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération de la localisation: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la localisation',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function update(Request $request, Localisation $localisation): JsonResponse
    {
        try {
            $request->validate([
                'Nom' => 'required|string|max:50|unique:localisations,Nom,' . $localisation->id
            ]);

            $localisation->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Localisation mise à jour avec succès',
                'data' => $localisation
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour de la localisation: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour de la localisation',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function destroy(Localisation $localisation): JsonResponse
    {
        try {
            $localisation->delete();

            return response()->json([
                'success' => true,
                'message' => 'Localisation supprimée avec succès'
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression de la localisation: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la localisation',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }
}