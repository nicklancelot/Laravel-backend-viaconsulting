<?php

namespace App\Http\Controllers\TestHuille;

use App\Http\Controllers\Controller;
use App\Models\TestHuille\HETester;
use App\Models\TestHuille\FicheReception;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class HETesterController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $tests = HETester::with(['ficheReception.fournisseur', 'ficheReception.siteCollecte'])
                ->orderBy('created_at', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'message' => 'Liste des tests',
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

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validate([
                'fiche_reception_id' => 'required|exists:fiche_receptions,id',
                'date_test' => 'required|date',
                'heure_debut' => 'required|date_format:H:i',
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

            // Vérifier si un test existe déjà pour cette fiche
            $existingTest = HETester::where('fiche_reception_id', $validated['fiche_reception_id'])->first();
            if ($existingTest) {
                return response()->json([
                    'success' => false,
                    'message' => 'Un test existe déjà pour cette fiche de réception'
                ], 409);
            }

            // Calcul des heures (3-4 minutes en développement)
            $heureDebut = Carbon::createFromFormat('H:i', $validated['heure_debut']);
            $dureeMinutes = rand(3, 4); // Durée aléatoire entre 3 et 4 minutes
            $heureFinPrevue = $heureDebut->copy()->addMinutes($dureeMinutes);
            
            // Date d'expiration du test (24 heures)
            $testExpiresAt = Carbon::now()->addHours(24);

            $test = HETester::create([
                'fiche_reception_id' => $validated['fiche_reception_id'],
                'date_test' => $validated['date_test'],
                'heure_debut' => $validated['heure_debut'],
                'heure_fin_prevue' => $heureFinPrevue->format('H:i'),
                'densite' => $validated['densite'] ?? null,
                'presence_huile_vegetale' => $validated['presence_huile_vegetale'],
                'presence_lookhead' => $validated['presence_lookhead'],
                'teneur_eau' => $validated['teneur_eau'] ?? null,
                'observations' => $validated['observations'] ?? null,
                'test_expires_at' => $testExpiresAt
            ]);

            // Mettre à jour le statut de la fiche de réception
            $fiche->update(['statut' => 'en cours de teste']);

            DB::commit();

            $test->load(['ficheReception.fournisseur', 'ficheReception.siteCollecte']);

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

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        try {
            $test = HETester::with(['ficheReception.fournisseur', 'ficheReception.siteCollecte'])->find($id);

            if (!$test) {
                return response()->json([
                    'success' => false,
                    'message' => 'Test non trouvé'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Test trouvé',
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

    /**
     * Update the specified resource in storage.
     */
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
                'heure_fin_reelle' => 'sometimes|date_format:H:i',
                'densite' => 'nullable|numeric|min:0',
                'presence_huile_vegetale' => 'sometimes|in:Oui,Non',
                'presence_lookhead' => 'sometimes|in:Oui,Non',
                'teneur_eau' => 'nullable|numeric|min:0|max:100',
                'observations' => 'nullable|string'
            ]);

            $test->update($validated);

            DB::commit();

            $test->load(['ficheReception.fournisseur', 'ficheReception.siteCollecte']);

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

    /**
     * Remove the specified resource from storage.
     */
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
            $fiche->update(['statut' => 'en attente de teste']);

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

    /**
     * Terminer un test (marquer comme terminé)
     */
    public function terminerTest($id)
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

            // Marquer l'heure de fin réelle comme maintenant
            $test->update([
                'heure_fin_reelle' => Carbon::now()->format('H:i')
            ]);

            // CORRECTION : Mettre à jour le statut de la fiche de réception en "Teste terminée"
            $fiche = $test->ficheReception;
            $fiche->update(['statut' => 'Teste terminée']);

            DB::commit();

            $test->load(['ficheReception.fournisseur', 'ficheReception.siteCollecte']);

            return response()->json([
                'success' => true,
                'message' => 'Test terminé avec succès',
                'data' => $test,
                'nouveau_statut' => 'Teste terminée'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la finalisation du test',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer les tests en cours
     */
    public function testsEnCours()
    {
        try {
            $tests = HETester::with(['ficheReception.fournisseur', 'ficheReception.siteCollecte'])
                ->whereNull('heure_fin_reelle')
                ->where('test_expires_at', '>', Carbon::now())
                ->orderBy('created_at', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'message' => 'Tests en cours',
                'data' => $tests,
                'count' => $tests->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des tests en cours',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer les tests terminés
     */
    public function testsTermines()
    {
        try {
            $tests = HETester::with(['ficheReception.fournisseur', 'ficheReception.siteCollecte'])
                ->whereNotNull('heure_fin_reelle')
                ->orderBy('heure_fin_reelle', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'message' => 'Tests terminés',
                'data' => $tests,
                'count' => $tests->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des tests terminés',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}