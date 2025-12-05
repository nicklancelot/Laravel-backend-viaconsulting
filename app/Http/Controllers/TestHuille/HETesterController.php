<?php

namespace App\Http\Controllers\TestHuille;

use App\Http\Controllers\Controller;
use App\Models\TestHuille\HETester;
use App\Models\TestHuille\FicheReception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HETesterController extends Controller
{
    public function index()
    {
        try {
            $tests = HETester::with(['ficheReception'])
                ->orderBy('created_at', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $tests,
                'count' => $tests->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des tests',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validate([
                'fiche_reception_id' => 'required|exists:fiche_receptions,id',
                'date_test' => 'required|date',
                'heure_debut' => 'required|date_format:H:i',
                'heure_fin_prevue' => 'required|date_format:H:i',
                'heure_fin_reelle' => 'nullable|date_format:H:i',
                'densite' => 'nullable|numeric|min:0',
                'presence_huile_vegetale' => 'required|in:Oui,Non',
                'presence_lookhead' => 'required|in:Oui,Non',
                'teneur_eau' => 'nullable|numeric|min:0|max:100',
                'observations' => 'nullable|string'
            ]);

            // Vérifier si la fiche de réception existe
            $fiche = FicheReception::find($validated['fiche_reception_id']);
            if (!$fiche) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fiche de réception non trouvée'
                ], 404);
            }

            // Créer le test
            $test = HETester::create($validated);

            // Changer le statut de la fiche de réception
            $fiche->update(['statut' => 'en cours de teste']);

            DB::commit();

            $test->load(['ficheReception']);

            return response()->json([
                'success' => true,
                'message' => 'Test créé avec succès',
                'data' => $test
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du test',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $test = HETester::with(['ficheReception'])->find($id);

            if (!$test) {
                return response()->json([
                    'success' => false,
                    'message' => 'Test non trouvé'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $test
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du test',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            DB::beginTransaction();

            $test = HETester::find($id);

            if (!$test) {
                return response()->json([
                    'success' => false,
                    'message' => 'Test non trouvé'
                ], 404);
            }

            $validated = $request->validate([
                'date_test' => 'sometimes|date',
                'heure_debut' => 'sometimes|date_format:H:i',
                'heure_fin_prevue' => 'sometimes|date_format:H:i',
                'heure_fin_reelle' => 'nullable|date_format:H:i',
                'densite' => 'nullable|numeric|min:0',
                'presence_huile_vegetale' => 'sometimes|in:Oui,Non',
                'presence_lookhead' => 'sometimes|in:Oui,Non',
                'teneur_eau' => 'nullable|numeric|min:0|max:100',
                'observations' => 'nullable|string'
            ]);

            $test->update($validated);

            DB::commit();

            $test->load(['ficheReception']);

            return response()->json([
                'success' => true,
                'message' => 'Test mis à jour avec succès',
                'data' => $test
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du test',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            DB::beginTransaction();

            $test = HETester::find($id);

            if (!$test) {
                return response()->json([
                    'success' => false,
                    'message' => 'Test non trouvé'
                ], 404);
            }

            // Remettre le statut de la fiche de réception à "en attente de teste"
            $fiche = $test->ficheReception;
            if ($fiche) {
                $fiche->update(['statut' => 'en attente de teste']);
            }

            $test->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Test supprimé avec succès'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression du test',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}