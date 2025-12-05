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
    /**
     * Vérification du mot de passe administrateur
     */
    public function verifyAdmin(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'password' => 'required|string'
            ]);

            // Chercher un admin existant dans la base de données
            $existingAdmin = Utilisateur::where('role', 'admin')->first();
            
            if (!$existingAdmin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun administrateur existant pour vérifier les permissions'
                ], 403);
            }
            
            // Vérifier le mot de passe avec l'admin existant
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

    /**
     * Inscription d'un nouvel utilisateur
     */
    public function register(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'nom' => 'required|string|max:100',
                'prenom' => 'required|string|max:100',
                'numero' => 'required|string|max:15|unique:utilisateurs',
                'CIN' => 'required|string|max:20|unique:utilisateurs',
                'localisation_id' => 'required|exists:localisations,id',
                'password' => 'required|string|min:8|confirmed',
                'role' => 'sometimes|in:admin,collecteur,vendeur,distilleur',
                'admin_confirmation_password' => 'required_if:role,admin|string'
            ]);

            // Vérification du mot de passe admin si le rôle est admin
            if ($request->role === 'admin') {
                $adminConfirmationPassword = $request->admin_confirmation_password;
                
                // Chercher un admin existant dans la base de données
                $existingAdmin = Utilisateur::where('role', 'admin')->first();
                
                if (!$existingAdmin) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Aucun administrateur existant pour vérifier les permissions'
                    ], 403);
                }
                
                // Vérifier le mot de passe avec l'admin existant
                if (!Hash::check($adminConfirmationPassword, $existingAdmin->password)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Mot de passe administrateur incorrect'
                    ], 403);
                }
            }

            $utilisateur = Utilisateur::create([
                'nom' => $request->nom,
                'prenom' => $request->prenom,
                'numero' => $request->numero,
                'CIN' => $request->CIN,
                'localisation_id' => $request->localisation_id,
                'password' => Hash::make($request->password),
                'role' => $request->role ?? 'collecteur'
            ]);

            $token = $utilisateur->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Utilisateur créé avec succès',
                'data' => $utilisateur,
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
                'data' => $utilisateur,
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
                'data' => $request->user()->load('localisation')
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