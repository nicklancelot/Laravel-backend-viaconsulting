<?php

namespace App\Http\Controllers\MatierePremiere;

use App\Http\Controllers\Controller;
use App\Models\MatierePremiere\Fournisseur;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;

class FournisseurController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $fournisseurs = Fournisseur::with(['localisation', 'utilisateur'])
                ->forUser($user) 
                ->orderBy('created_at', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $fournisseurs
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des fournisseurs: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des fournisseurs',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $request->validate([
                'nom' => 'nullable|string|max:100',
                'prenom' => 'nullable|string|max:100',
                'adresse' => 'nullable|string|max:255',
                'cin' => 'nullable|string|max:20',
                'identification_fiscale' => 'nullable|string|max:50|unique:fournisseurs',
                'localisation_id' => 'nullable|exists:localisations,id',
                'contact' => 'nullable|string|max:20'
            ]);

            // Ajouter l'utilisateur_id automatiquement
            $data = $request->all();
            $data['utilisateur_id'] = $user->id;

            $fournisseur = Fournisseur::create($data);

            return response()->json([
                'success' => true,
                'message' => 'Fournisseur créé avec succès',
                'data' => $fournisseur->load(['localisation', 'utilisateur'])
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la création du fournisseur: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du fournisseur',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function show(Fournisseur $fournisseur): JsonResponse
    {
        try {
            $user = Auth::user();
            
            // Vérifier les permissions
            if ($user->role !== 'admin' && $fournisseur->utilisateur_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé à ce fournisseur'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => $fournisseur->load(['localisation', 'utilisateur'])
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération du fournisseur: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du fournisseur',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function update(Request $request, Fournisseur $fournisseur): JsonResponse
    {
        try {
            $user = Auth::user();
            
            // Vérifier les permissions
            if ($user->role !== 'admin' && $fournisseur->utilisateur_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé pour modifier ce fournisseur'
                ], 403);
            }

            $request->validate([
                'nom' => 'nullable|string|max:100',
                'prenom' => 'nullable|string|max:100',
                'adresse' => 'nullable|string|max:255',
                'cin' => 'nullable|string|max:20',
                'identification_fiscale' => 'nullable|string|max:50|unique:fournisseurs,identification_fiscale,' . $fournisseur->id,
                'localisation_id' => 'nullable|exists:localisations,id',
                'contact' => 'nullable|string|max:20'
            ]);

            $fournisseur->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Fournisseur mis à jour avec succès',
                'data' => $fournisseur->load(['localisation', 'utilisateur'])
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour du fournisseur: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du fournisseur',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function destroy(Fournisseur $fournisseur): JsonResponse
    {
        try {
            $user = Auth::user();
            
            // Vérifier les permissions
            if ($user->role !== 'admin' && $fournisseur->utilisateur_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé pour supprimer ce fournisseur'
                ], 403);
            }

            // Vérifier si le fournisseur est utilisé dans des PV de réception
            if ($fournisseur->pvReceptions()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de supprimer ce fournisseur car il est utilisé dans des PV de réception'
                ], 422);
            }

            $fournisseur->delete();

            return response()->json([
                'success' => true,
                'message' => 'Fournisseur supprimé avec succès'
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression du fournisseur: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression du fournisseur',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function search(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $request->validate([
                'search' => 'required|string|min:2'
            ]);

            $fournisseurs = Fournisseur::with(['localisation', 'utilisateur'])
                ->forUser($user) // Applique le filtre selon le rôle
                ->where(function($query) use ($request) {
                    $query->where('nom', 'like', '%' . $request->search . '%')
                          ->orWhere('prenom', 'like', '%' . $request->search . '%')
                          ->orWhere('identification_fiscale', 'like', '%' . $request->search . '%')
                          ->orWhere('contact', 'like', '%' . $request->search . '%');
                })
                ->get();

            return response()->json([
                'success' => true,
                'data' => $fournisseurs
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la recherche des fournisseurs: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la recherche des fournisseurs',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }
}