<?php

namespace App\Http\Controllers\TestHuille;

use App\Http\Controllers\Controller;
use App\Models\TestHuille\HEValidation;
use App\Models\TestHuille\FicheReception;
use App\Models\TestHuille\HETester;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HEValidationController extends Controller
{
    public function index()
    {
        try {
            $validations = HEValidation::with(['ficheReception', 'test'])
                ->orderBy('created_at', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $validations,
                'count' => $validations->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des validations',
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
                'test_id' => 'required|exists:h_e_testers,id',
                'decision' => 'required|in:Accepter,Refuser,A retraiter',
                'poids_agreer' => 'nullable|numeric|min:0',
                'observation_ecart_poids' => 'nullable|string',
                'observation_generale' => 'nullable|string'
            ]);

            // Vérifier si la fiche de réception existe
            $fiche = FicheReception::find($validated['fiche_reception_id']);
            if (!$fiche) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fiche de réception non trouvée'
                ], 404);
            }

            // Vérifier si le test existe
            $test = HETester::find($validated['test_id']);
            if (!$test) {
                return response()->json([
                    'success' => false,
                    'message' => 'Test non trouvé'
                ], 404);
            }

            // Créer la validation
            $validation = HEValidation::create($validated);

            // Changer le statut de la fiche de réception selon la décision
            $statutFiche = $this->getStatutFromDecision($validated['decision']);
            $fiche->update(['statut' => $statutFiche]);

            DB::commit();

            $validation->load(['ficheReception', 'test']);

            return response()->json([
                'success' => true,
                'message' => 'Validation créée avec succès',
                'data' => $validation
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
                'message' => 'Erreur lors de la création de la validation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $validation = HEValidation::with(['ficheReception', 'test'])->find($id);

            if (!$validation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation non trouvée'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $validation
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la validation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            DB::beginTransaction();

            $validation = HEValidation::find($id);

            if (!$validation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation non trouvée'
                ], 404);
            }

            $validated = $request->validate([
                'decision' => 'sometimes|in:Accepter,Refuser,A retraiter',
                'poids_agreer' => 'nullable|numeric|min:0',
                'observation_ecart_poids' => 'nullable|string',
                'observation_generale' => 'nullable|string'
            ]);

            $validation->update($validated);

            // Si la décision est modifiée, mettre à jour le statut de la fiche
            if (isset($validated['decision'])) {
                $fiche = $validation->ficheReception;
                $statutFiche = $this->getStatutFromDecision($validated['decision']);
                $fiche->update(['statut' => $statutFiche]);
            }

            DB::commit();

            $validation->load(['ficheReception', 'test']);

            return response()->json([
                'success' => true,
                'message' => 'Validation mise à jour avec succès',
                'data' => $validation
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
                'message' => 'Erreur lors de la mise à jour de la validation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            DB::beginTransaction();

            $validation = HEValidation::find($id);

            if (!$validation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation non trouvée'
                ], 404);
            }

            // Remettre le statut de la fiche de réception à "en cours de teste"
            $fiche = $validation->ficheReception;
            if ($fiche) {
                $fiche->update(['statut' => 'en cours de teste']);
            }

            $validation->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Validation supprimée avec succès'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la validation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function getStatutFromDecision($decision)
    {
        return match($decision) {
            'Accepter' => 'Accepté',
            'Refuser' => 'Refusé',
            'A retraiter' => 'A retraiter',
            default => 'en cours de teste'
        };
    }
}