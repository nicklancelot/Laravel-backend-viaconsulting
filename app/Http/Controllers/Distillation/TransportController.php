<?php

namespace App\Http\Controllers\Distillation;

use App\Http\Controllers\Controller;
use App\Models\Distillation\Distillation;
use App\Models\Distillation\Transport;
use App\Models\Livreur;
use App\Models\Utilisateur;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class TransportController extends Controller
{
    /**
     * Récupérer les distillations terminées sans transport (pour le distilleur connecté)
     */
    public function getDistillationsSansTransport(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if ($user->role !== 'distilleur') {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès réservé aux distillateurs'
                ], 403);
            }

            $distillations = Distillation::where('statut', 'termine')
                ->whereDoesntHave('transport')
                ->whereHas('expedition.ficheLivraison', function($query) use ($user) {
                    $query->where('distilleur_id', $user->id);
                })
                ->with(['expedition.ficheLivraison.distilleur.siteCollecte'])
                ->orderBy('date_fin', 'desc')
                ->get();

            // Formater les données
            $distillations->each(function ($distillation) {
                $distillation->type_matiere = $distillation->type_he ?? $distillation->type_matiere_premiere;
                $distillation->quantite_disponible = $distillation->quantite_resultat;
                $distillation->site_collecte = $distillation->expedition->ficheLivraison->distilleur->siteCollecte->Nom ?? 'Non défini';
            });

            return response()->json([
                'success' => true,
                'data' => $distillations,
                'count' => $distillations->count(),
                'distilleur_info' => [
                    'id' => $user->id,
                    'nom_complet' => $user->nom . ' ' . $user->prenom,
                    'site_collecte' => $user->siteCollecte->Nom ?? 'Non défini'
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des distillations sans transport',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Récupérer les vendeurs disponibles
     */
    public function getVendeursDisponibles(): JsonResponse
    {
        try {
            $vendeurs = Utilisateur::where('role', 'vendeur')
                ->with('localisation')
                ->get()
                ->map(function ($vendeur) {
                    return [
                        'id' => $vendeur->id,
                        'nom_complet' => $vendeur->nom . ' ' . $vendeur->prenom,
                        'nom' => $vendeur->nom,
                        'prenom' => $vendeur->prenom,
                        'numero' => $vendeur->numero,
                        'localisation' => $vendeur->localisation->Nom ?? 'Non défini',
                        'localisation_id' => $vendeur->localisation_id
                    ];
                });
            
            return response()->json([
                'success' => true,
                'data' => $vendeurs
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des vendeurs',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Récupérer les livreurs disponibles
     */
    public function getLivreursDisponibles(): JsonResponse
    {
        try {
            $livreurs = Livreur::all()
                ->map(function ($livreur) {
                    return [
                        'id' => $livreur->id,
                        'nom_complet' => $livreur->nom . ' ' . $livreur->prenom,
                        'nom' => $livreur->nom,
                        'prenom' => $livreur->prenom,
                        'numero_vehicule' => $livreur->numero_vehicule,
                        'zone_livraison' => $livreur->zone_livraison,
                        'telephone' => $livreur->telephone
                    ];
                });
            
            return response()->json([
                'success' => true,
                'data' => $livreurs
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des livreurs',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Créer un transport (statut: en_cours par défaut)
     */
    public function creerTransport(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if ($user->role !== 'distilleur') {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès réservé aux distillateurs'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'distillation_id' => 'required|exists:distillations,id',
                'vendeur_id' => 'required|exists:utilisateurs,id',
                'livreur_id' => 'required|exists:livreurs,id',
                'date_transport' => 'required|date',
                'site_destination' => 'required|string|max:100',
                'type_matiere' => 'required|string|max:50',
                'quantite_a_livrer' => 'required|numeric|min:0',
                'ristourne_regionale' => 'nullable|numeric|min:0',
                'ristourne_communale' => 'nullable|numeric|min:0',
                'observations' => 'nullable|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Vérifier que la distillation appartient au distilleur connecté
            $distillation = Distillation::where('id', $request->distillation_id)
                ->whereHas('expedition.ficheLivraison', function($query) use ($user) {
                    $query->where('distilleur_id', $user->id);
                })
                ->with(['expedition.ficheLivraison.distilleur.siteCollecte'])
                ->first();
            
            if (!$distillation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Distillation non trouvée ou non autorisée'
                ], 404);
            }

            // Vérifier que la distillation est terminée
            if ($distillation->statut !== 'termine') {
                return response()->json([
                    'success' => false,
                    'message' => 'La distillation doit être terminée avant transport. Statut actuel: ' . $distillation->statut
                ], 400);
            }

            // Vérifier qu'il n'y a pas déjà un transport pour cette distillation
            $transportExistant = Transport::where('distillation_id', $distillation->id)->first();
            if ($transportExistant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Un transport existe déjà pour cette distillation'
                ], 400);
            }

            // Vérifier la quantité disponible
            if ($distillation->quantite_resultat < $request->quantite_a_livrer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Quantité insuffisante. Disponible: ' . $distillation->quantite_resultat . ' Demandé: ' . $request->quantite_a_livrer
                ], 400);
            }

            // Vérifier que le vendeur a bien le rôle vendeur
            $vendeur = Utilisateur::where('id', $request->vendeur_id)
                ->where('role', 'vendeur')
                ->first();
            
            if (!$vendeur) {
                return response()->json([
                    'success' => false,
                    'message' => 'L\'utilisateur sélectionné doit être un vendeur'
                ], 400);
            }

            // Récupérer le site de collecte du distilleur (lieu de départ)
            $lieuDepart = $distillation->expedition->ficheLivraison->distilleur->siteCollecte->Nom ?? 'PK 12';

            // Créer le transport avec statut "en_cours" par défaut
            $transport = Transport::create([
                'distillation_id' => $distillation->id,
                'vendeur_id' => $vendeur->id,
                'livreur_id' => $request->livreur_id,
                'date_transport' => $request->date_transport,
                'lieu_depart' => $lieuDepart,
                'site_destination' => $request->site_destination,
                'type_matiere' => $request->type_matiere,
                'quantite_a_livrer' => $request->quantite_a_livrer,
                'ristourne_regionale' => $request->ristourne_regionale ?? 0,
                'ristourne_communale' => $request->ristourne_communale ?? 0,
                'observations' => $request->observations,
                'statut' => 'en_cours' // Statut par défaut
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Transport créé avec succès (en cours)',
                'data' => $transport->load([
                    'distillation.expedition.ficheLivraison.distilleur.siteCollecte',
                    'livreur',
                    'vendeur'
                ]),
                'distilleur_info' => [
                    'id' => $user->id,
                    'nom_complet' => $user->nom . ' ' . $user->prenom,
                    'site_collecte' => $user->siteCollecte->Nom ?? 'Non défini'
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du transport',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Récupérer les transports en cours (pour le distilleur connecté)
     */
    public function getTransportsEnCours(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if ($user->role !== 'distilleur') {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès réservé aux distillateurs'
                ], 403);
            }

            $transports = Transport::where('statut', 'en_cours')
                ->whereHas('distillation.expedition.ficheLivraison', function($query) use ($user) {
                    $query->where('distilleur_id', $user->id);
                })
                ->with([
                    'distillation',
                    'livreur',
                    'vendeur'
                ])
                ->orderBy('date_transport', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $transports,
                'count' => $transports->count(),
                'distilleur_info' => [
                    'id' => $user->id,
                    'nom_complet' => $user->nom . ' ' . $user->prenom,
                    'site_collecte' => $user->siteCollecte->Nom ?? 'Non défini'
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des transports en cours',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Récupérer les transports livrés (pour le distilleur connecté)
     */
    public function getTransportsLivre(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if ($user->role !== 'distilleur') {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès réservé aux distillateurs'
                ], 403);
            }

            $transports = Transport::where('statut', 'livre')
                ->whereHas('distillation.expedition.ficheLivraison', function($query) use ($user) {
                    $query->where('distilleur_id', $user->id);
                })
                ->with([
                    'distillation',
                    'livreur',
                    'vendeur'
                ])
                ->orderBy('date_livraison', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $transports,
                'count' => $transports->count(),
                'distilleur_info' => [
                    'id' => $user->id,
                    'nom_complet' => $user->nom . ' ' . $user->prenom,
                    'site_collecte' => $user->siteCollecte->Nom ?? 'Non défini'
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des transports livrés',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Marquer un transport comme livré
     */
    public function marquerLivre(Request $request, $transportId): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if ($user->role !== 'distilleur') {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès réservé aux distillateurs'
                ], 403);
            }

            $request->validate([
                'observations' => 'nullable|string'
            ]);

            // Vérifier que le transport appartient au distilleur connecté
            $transport = Transport::where('id', $transportId)
                ->whereHas('distillation.expedition.ficheLivraison', function($query) use ($user) {
                    $query->where('distilleur_id', $user->id);
                })
                ->first();
            
            if (!$transport) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transport non trouvé ou non autorisé'
                ], 404);
            }

            // Vérifier que le transport est en cours
            if (!$transport->estEnCours()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le transport n\'est pas en cours. Statut actuel: ' . $transport->statut
                ], 400);
            }

            $transport->marquerLivre($request->observations);

            return response()->json([
                'success' => true,
                'message' => 'Transport marqué comme livré',
                'data' => $transport->load(['livreur', 'vendeur', 'distillation']),
                'distilleur_info' => [
                    'id' => $user->id,
                    'nom_complet' => $user->nom . ' ' . $user->prenom,
                    'site_collecte' => $user->siteCollecte->Nom ?? 'Non défini'
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du marquage du transport',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Récupérer tous les transports du distilleur connecté
     */
    public function getMesTransports(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if ($user->role !== 'distilleur') {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès réservé aux distillateurs'
                ], 403);
            }

            $transports = Transport::whereHas('distillation.expedition.ficheLivraison', function($query) use ($user) {
                    $query->where('distilleur_id', $user->id);
                })
                ->with([
                    'distillation',
                    'livreur',
                    'vendeur'
                ])
                ->orderBy('created_at', 'desc')
                ->get();

            // Calculer les statistiques
            $stats = [
                'total' => $transports->count(),
                'en_cours' => $transports->where('statut', 'en_cours')->count(),
                'livre' => $transports->where('statut', 'livre')->count(),
                'quantite_livree_totale' => $transports->sum('quantite_a_livrer'),
                'quantite_livree_livre' => $transports->where('statut', 'livre')->sum('quantite_a_livrer')
            ];

            return response()->json([
                'success' => true,
                'data' => $transports,
                'stats' => $stats,
                'distilleur_info' => [
                    'id' => $user->id,
                    'nom_complet' => $user->nom . ' ' . $user->prenom,
                    'site_collecte' => $user->siteCollecte->Nom ?? 'Non défini'
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des transports',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }
}