<?php

namespace App\Http\Controllers;

use App\Models\Utilisateur;
use App\Models\SiteCollecte;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UtilisateurController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        try {
       
            $rules = [
                'nom' => 'required|string|max:100',
                'prenom' => 'required|string|max:100',
                'numero' => 'required|string|max:15|unique:utilisateurs',
                'CIN' => 'required|string|max:20|unique:utilisateurs',
                'localisation_id' => 'required|exists:localisations,id',
                'password' => 'required|string|min:8',
                'role' => 'required|in:admin,collecteur,vendeur,distilleur',
                'admin_confirmation_password' => 'required|string'
            ];


            if ($request->role === 'collecteur') {
                $rules['code_collecteur'] = 'nullable|string|max:50|unique:utilisateurs,code_collecteur';
            } else {
                $rules['code_collecteur'] = 'nullable|string|max:50';
            }

            if ($request->role === 'distilleur') {
                $rules['site_collecte_id'] = 'required|exists:site_collectes,id';
            } else {
                $rules['site_collecte_id'] = 'nullable|exists:site_collectes,id';
            }

            $validatedData = $request->validate($rules);

            $existingAdmin = Utilisateur::where('role', 'admin')->first();
            
            if (!$existingAdmin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun administrateur existant pour vérifier les permissions'
                ], 403);
            }
            
            if (!Hash::check($validatedData['admin_confirmation_password'], $existingAdmin->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Mot de passe administrateur incorrect'
                ], 403);
            }

            $userData = [
                'nom' => $validatedData['nom'],
                'prenom' => $validatedData['prenom'],
                'numero' => $validatedData['numero'],
                'CIN' => $validatedData['CIN'],
                'localisation_id' => $validatedData['localisation_id'],
                'password' => Hash::make($validatedData['password']),
                'role' => $validatedData['role']
            ];

            if ($request->role === 'collecteur' && isset($validatedData['code_collecteur'])) {
                $userData['code_collecteur'] = $validatedData['code_collecteur'];
            } else {
                $userData['code_collecteur'] = null;
            }

            if ($request->role === 'distilleur' && isset($validatedData['site_collecte_id'])) {
                $userData['site_collecte_id'] = $validatedData['site_collecte_id'];
            } else {
                $userData['site_collecte_id'] = null;
            }

            $utilisateur = Utilisateur::create($userData);

            return response()->json([
                'success' => true,
                'message' => 'Utilisateur créé avec succès',
                'data' => $utilisateur->load(['localisation', 'siteCollecte'])
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la création de l\'utilisateur: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de l\'utilisateur',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function update(Request $request, Utilisateur $utilisateur): JsonResponse
    {
        try {

            $rules = [
                'nom' => 'sometimes|string|max:100',
                'prenom' => 'sometimes|string|max:100',
                'numero' => 'sometimes|string|max:15|unique:utilisateurs,numero,' . $utilisateur->id,
                'CIN' => 'sometimes|string|max:20|unique:utilisateurs,CIN,' . $utilisateur->id,
                'localisation_id' => 'sometimes|exists:localisations,id',
                'password' => 'sometimes|string|min:8',
                'role' => 'sometimes|in:admin,collecteur,vendeur,distilleur',
                'admin_confirmation_password' => 'required|string'
            ];

            // Ajout de la règle pour code_collecteur
            $rules['code_collecteur'] = 'sometimes|nullable|string|max:50|unique:utilisateurs,code_collecteur,' . $utilisateur->id;

            if ($request->has('role') && $request->role === 'distilleur') {
                $rules['site_collecte_id'] = 'required|exists:site_collectes,id';
            } else if ($request->has('site_collecte_id')) {
                $rules['site_collecte_id'] = 'nullable|exists:site_collectes,id';
            }

            $validatedData = $request->validate($rules);

            $existingAdmin = Utilisateur::where('role', 'admin')->first();
            
            if (!$existingAdmin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun administrateur existant pour vérifier les permissions'
                ], 403);
            }
            
            if (!Hash::check($validatedData['admin_confirmation_password'], $existingAdmin->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Mot de passe administrateur incorrect'
                ], 403);
            }

            $data = [];
            if (isset($validatedData['nom'])) $data['nom'] = $validatedData['nom'];
            if (isset($validatedData['prenom'])) $data['prenom'] = $validatedData['prenom'];
            if (isset($validatedData['numero'])) $data['numero'] = $validatedData['numero'];
            if (isset($validatedData['CIN'])) $data['CIN'] = $validatedData['CIN'];
            if (isset($validatedData['localisation_id'])) $data['localisation_id'] = $validatedData['localisation_id'];
            if (isset($validatedData['role'])) $data['role'] = $validatedData['role'];
            if (isset($validatedData['code_collecteur'])) $data['code_collecteur'] = $validatedData['code_collecteur'];

            if ($request->has('password')) {
                $data['password'] = Hash::make($validatedData['password']);
            }

       
            if ($request->has('role')) {
                if ($request->role !== 'collecteur') {
                    $data['code_collecteur'] = null;
                }
            }

            if ($request->has('role')) {
                if ($request->role === 'distilleur' && isset($validatedData['site_collecte_id'])) {
                    $data['site_collecte_id'] = $validatedData['site_collecte_id'];
                } else {
                    $data['site_collecte_id'] = null;
                }
            } elseif ($request->has('site_collecte_id')) {
                $data['site_collecte_id'] = $validatedData['site_collecte_id'];
            }
            $utilisateur->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Utilisateur mis à jour avec succès',
                'data' => $utilisateur->load(['localisation', 'siteCollecte'])
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour de l\'utilisateur: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour de l\'utilisateur',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function index(): JsonResponse
    {
        try {
            $utilisateurs = Utilisateur::with(['localisation', 'siteCollecte'])->get();
            return response()->json([
                'success' => true,
                'data' => $utilisateurs
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des utilisateurs: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des utilisateurs',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function show(Utilisateur $utilisateur): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $utilisateur->load(['localisation', 'siteCollecte'])
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération de l\'utilisateur: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l\'utilisateur',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function destroy(Utilisateur $utilisateur): JsonResponse
    {
        try {
            $utilisateur->delete();

            return response()->json([
                'success' => true,
                'message' => 'Utilisateur supprimé avec succès'
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression de l\'utilisateur: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de l\'utilisateur',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function getSitesCollecte(): JsonResponse
    {
        try {
            $sites = SiteCollecte::all();
            return response()->json([
                'success' => true,
                'data' => $sites
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
}