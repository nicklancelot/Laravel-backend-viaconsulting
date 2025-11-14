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
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $validations = HEValidation::with([
                'ficheReception.fournisseur', 
                'ficheReception.siteCollecte',
                'test'
            ])->orderBy('created_at', 'desc')->get();
            
            return response()->json([
                'success' => true,
                'message' => 'Liste des validations',
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

    /**
     * Store a newly created resource in storage.
     */
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

            // Vérifier si la fiche existe et a le bon statut
            $fiche = FicheReception::find($validated['fiche_reception_id']);
            if (!$fiche) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fiche de réception non trouvée'
                ], 404);
            }

            if ($fiche->statut !== 'Teste terminée') {
                return response()->json([
                    'success' => false,
                    'message' => 'La fiche doit être en statut "Teste terminée" pour être validée'
                ], 400);
            }

            // Vérifier si le test existe et correspond à la fiche
            $test = HETester::where('id', $validated['test_id'])
                ->where('fiche_reception_id', $validated['fiche_reception_id'])
                ->first();

            if (!$test) {
                return response()->json([
                    'success' => false,
                    'message' => 'Test non trouvé ou ne correspond pas à la fiche de réception'
                ], 404);
            }

            // Vérifier si une validation existe déjà pour cette fiche
            $existingValidation = HEValidation::where('fiche_reception_id', $validated['fiche_reception_id'])->first();
            if ($existingValidation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Une validation existe déjà pour cette fiche de réception'
                ], 409);
            }

            // Créer la validation
            $validation = HEValidation::create($validated);

            // Mettre à jour le statut de la fiche selon la décision
            $nouveauStatut = $this->getStatutFromDecision($validated['decision']);
            $fiche->update(['statut' => $nouveauStatut]);

            DB::commit();

            $validation->load(['ficheReception.fournisseur', 'ficheReception.siteCollecte', 'test']);

            return response()->json([
                'success' => true,
                'message' => 'Décision de validation enregistrée avec succès',
                'data' => $validation,
                'nouveau_statut' => $nouveauStatut
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
                'message' => 'Erreur lors de l\'enregistrement de la validation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        try {
            $validation = HEValidation::with([
                'ficheReception.fournisseur', 
                'ficheReception.siteCollecte',
                'test'
            ])->find($id);

            if (!$validation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation non trouvée'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Validation trouvée',
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

    /**
     * Update the specified resource in storage.
     */
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

            // Sauvegarder l'ancienne décision pour la mise à jour du statut
            $ancienneDecision = $validation->decision;

            $validation->update($validated);

            // Mettre à jour le statut de la fiche si la décision a changé
            if ($request->has('decision') && $validated['decision'] !== $ancienneDecision) {
                $nouveauStatut = $this->getStatutFromDecision($validated['decision']);
                $fiche = $validation->ficheReception;
                $fiche->update(['statut' => $nouveauStatut]);
            }

            DB::commit();

            $validation->load(['ficheReception.fournisseur', 'ficheReception.siteCollecte', 'test']);

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

    /**
     * Remove the specified resource from storage.
     */
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

            // Remettre le statut de la fiche à "Teste terminée"
            $fiche = $validation->ficheReception;
            $fiche->update(['statut' => 'Teste terminée']);

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

    /**
     * Récupérer la validation par fiche de réception
     */
    public function getByFicheReception($fiche_reception_id)
    {
        try {
            $validation = HEValidation::with([
                'ficheReception.fournisseur', 
                'ficheReception.siteCollecte',
                'test'
            ])->where('fiche_reception_id', $fiche_reception_id)->first();

            if (!$validation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune validation trouvée pour cette fiche de réception'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Validation trouvée',
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

    /**
     * Convertir la décision en statut de fiche
     */
    private function getStatutFromDecision($decision)
    {
        switch ($decision) {
            case 'Accepter':
                return 'Accepté';
            case 'Refuser':
                return 'Refusé';
            case 'A retraiter':
                return 'A retraiter';
            default:
                return 'Teste terminée';
        }
    }
}