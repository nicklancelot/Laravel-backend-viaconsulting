<?php

namespace App\Http\Controllers;

use App\Models\Provenances;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ProvenancesController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $provenances = Provenances::all();
            return response()->json([
                'success' => true,
                'data' => $provenances
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des provenances: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des provenances',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'Nom' => 'required|string|max:50|unique:provenances'
            ]);

            $provenance = Provenances::create($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Provenance créée avec succès',
                'data' => $provenance
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la création de la provenance: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de la provenance',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function show(Provenances $provenance): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $provenance
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération de la provenance: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la provenance',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function update(Request $request, Provenances $provenance): JsonResponse
    {
        try {
            $request->validate([
                'Nom' => 'required|string|max:50|unique:provenances,Nom,' . $provenance->id
            ]);

            $provenance->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Provenance mise à jour avec succès',
                'data' => $provenance
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour de la provenance: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour de la provenance',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function destroy(Provenances $provenance): JsonResponse
    {
        try {
            $provenance->delete();

            return response()->json([
                'success' => true,
                'message' => 'Provenance supprimée avec succès'
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression de la provenance: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la provenance',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }
}