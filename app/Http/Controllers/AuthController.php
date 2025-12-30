<?php

namespace App\Http\Controllers;

use App\Models\Utilisateur;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function verifyAdmin(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'password' => 'required|string'
            ]);

            $existingAdmin = Utilisateur::where('role', 'admin')->first();
            
            if (!$existingAdmin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun administrateur existant pour vérifier les permissions'
                ], 403);
            }
            
            if (!Hash::check($request->password, $existingAdmin->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Mot de passe administrateur incorrect'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'message' => 'Vérification administrateur réussie'
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la vérification admin: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la vérification',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function register(Request $request): JsonResponse
    {
        try {

            $rules = [
                'nom' => 'required|string|max:100',
                'prenom' => 'required|string|max:100',
                'numero' => 'required|string|max:15|unique:utilisateurs',
                'CIN' => 'required|string|max:20|unique:utilisateurs',
                'localisation_id' => 'required|exists:localisations,id',
                'password' => 'required|string|min:8|confirmed',
                'role' => 'required|in:admin,collecteur,vendeur,distilleur',
                'admin_confirmation_password' => 'required|string'
            ];

            // Ajout de la règle pour code_collecteur
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

            // Gestion du code_collecteur
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

            $token = $utilisateur->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Utilisateur créé avec succès',
                'data' => $utilisateur->load(['localisation', 'siteCollecte']),
                'access_token' => $token,
                'token_type' => 'Bearer'
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'inscription: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'inscription',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function login(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'numero' => 'required|string',
                'password' => 'required|string'
            ]);

            $utilisateur = Utilisateur::where('numero', $request->numero)->first();

            if (!$utilisateur || !Hash::check($request->password, $utilisateur->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Les identifiants sont incorrects.'
                ], 401);
            }

            $token = $utilisateur->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Connexion réussie',
                'data' => $utilisateur->load(['localisation', 'siteCollecte']),
                'access_token' => $token,
                'token_type' => 'Bearer'
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la connexion: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la connexion',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Déconnexion réussie'
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la déconnexion: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la déconnexion',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function user(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $request->user()->load(['localisation', 'siteCollecte'])
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération du profil: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du profil',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }
}