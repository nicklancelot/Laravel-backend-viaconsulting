<?php

namespace App\Http\Controllers\TestHuille;

use App\Http\Controllers\Controller;
use App\Models\TestHuille\FicheReception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class FicheReceptionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $user = Auth::user();
            
            $fiches = FicheReception::with(['fournisseur', 'siteCollecte', 'utilisateur'])
                ->forUser($user)
                ->orderBy('created_at', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'message' => 'Liste des fiches de réception',
                'data' => $fiches,
                'count' => $fiches->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des fiches',
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

            $user = Auth::user();
            
            $validated = $request->validate([
                'date_reception' => 'required|date',
                'heure_reception' => 'required|date_format:H:i',
                'fournisseur_id' => 'required|exists:fournisseurs,id',
                'site_collecte_id' => 'required|exists:site_collectes,id',
                'utilisateur_id' => 'required|exists:utilisateurs,id',
                'poids_brut' => 'required|numeric|min:0'
            ]);

            if ($user->role !== 'admin' && $validated['utilisateur_id'] != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous ne pouvez créer des fiches que pour votre propre compte'
                ], 403);
            }

            $numeroDocument = 'REC-' . date('Ymd') . '-' . Str::upper(Str::random(6));

            $fiche = FicheReception::create([
                'numero_document' => $numeroDocument,
                'date_reception' => $validated['date_reception'],
                'heure_reception' => $validated['heure_reception'],
                'fournisseur_id' => $validated['fournisseur_id'],
                'site_collecte_id' => $validated['site_collecte_id'],
                'utilisateur_id' => $validated['utilisateur_id'],
                'poids_brut' => $validated['poids_brut'],
                'statut' => 'en attente de teste'
            ]);

            DB::commit();

            $fiche->load(['fournisseur', 'siteCollecte', 'utilisateur']);

            return response()->json([
                'success' => true,
                'message' => 'Fiche de réception créée avec succès',
                'data' => $fiche
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
                'message' => 'Erreur lors de la création de la fiche',
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
            $user = Auth::user();
            $fiche = FicheReception::with(['fournisseur', 'siteCollecte', 'utilisateur'])->find($id);

            if (!$fiche) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fiche de réception non trouvée'
                ], 404);
            }

            if ($user->role !== 'admin' && $fiche->utilisateur_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé à cette fiche de réception'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'message' => 'Fiche de réception trouvée',
                'data' => $fiche
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la fiche',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
   /**
 * Update the specified resource in storage.
 */
public function update(Request $request, $id)
{
    try {
        DB::beginTransaction();

        $user = Auth::user();
        $fiche = FicheReception::find($id);

        if (!$fiche) {
            return response()->json([
                'success' => false,
                'message' => 'Fiche de réception non trouvée'
            ], 404);
        }

        if ($user->role !== 'admin' && $fiche->utilisateur_id != $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Accès non autorisé pour modifier cette fiche de réception'
            ], 403);
        }

        // CORRECTION : Liste complète des statuts autorisés
        $validated = $request->validate([
            'date_reception' => 'sometimes|date',
            'heure_reception' => 'sometimes|date_format:H:i',
            'fournisseur_id' => 'sometimes|exists:fournisseurs,id',
            'site_collecte_id' => 'sometimes|exists:site_collectes,id',
            'utilisateur_id' => 'sometimes|exists:utilisateurs,id',
            'poids_brut' => 'sometimes|numeric|min:0',
            'statut' => 'sometimes|in:en attente de teste,en cours de teste,Accepté,teste validé,teste invalide,En attente de livraison,payé,incomplet,partiellement payé,en attente de paiement,livré,Refusé,A retraiter'
        ]);

        if ($request->has('utilisateur_id') && $user->role !== 'admin' && $request->utilisateur_id != $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez assigner des fiches qu\'à votre propre compte'
            ], 403);
        }

        foreach ($validated as $key => $value) {
            $fiche->$key = $value;
        }

        $fiche->save();

        DB::commit();

        $fiche->load(['fournisseur', 'siteCollecte', 'utilisateur']);

        return response()->json([
            'success' => true,
            'message' => 'Fiche de réception mise à jour avec succès',
            'data' => $fiche,
            'updated_fields' => array_keys($validated)
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
            'message' => 'Erreur lors de la mise à jour de la fiche',
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

            $user = Auth::user();
            $fiche = FicheReception::find($id);

            if (!$fiche) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fiche de réception non trouvée'
                ], 404);
            }

            if ($user->role !== 'admin' && $fiche->utilisateur_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé pour supprimer cette fiche de réception'
                ], 403);
            }

            $fiche->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Fiche de réception supprimée avec succès'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la fiche',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}