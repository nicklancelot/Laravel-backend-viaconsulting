<?php

namespace App\Http\Controllers\Distillation;

use App\Http\Controllers\Controller;
use App\Models\Distillation\Distillation;
use App\Models\Distillation\Stock;
use App\Models\Distillation\Transport;
use App\Models\Livreur;
use App\Models\Utilisateur;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class TransportController extends Controller
{
    /**
     * Récupérer les distillations terminées sans transport (pour le distilleur connecté)
     */
    public function getDistillationsSansTransport(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!in_array($user->role, ['distilleur', 'admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès réservé aux distillateurs et administrateurs'
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

  public function creerTransport(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!in_array($user->role, ['distilleur', 'admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès réservé aux distillateurs et administrateurs'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'stock_id' => 'required|exists:stocks,id',
                'vendeur_id' => 'required|exists:utilisateurs,id',
                'livreur_id' => 'required|exists:livreurs,id',
                'date_transport' => 'required|date',
                'site_destination' => 'required|string|max:100',
                'type_matiere' => 'required|string|max:50',
                'quantite_a_livrer' => 'required|numeric|min:0.1',
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

            DB::beginTransaction();

            // Vérifier que le stock appartient au distilleur
            $stock = Stock::where('id', $request->stock_id)
                ->where('distilleur_id', $user->id)
                ->lockForUpdate()
                ->first();
            
            if (!$stock) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Stock non trouvé ou non autorisé'
                ], 404);
            }

            // Vérifier que la quantité est disponible
            if ($stock->quantite_disponible < $request->quantite_a_livrer) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Quantité insuffisante dans le stock',
                    'stock_info' => [
                        'id' => $stock->id,
                        'reference' => $stock->reference,
                        'quantite_disponible' => $stock->quantite_disponible,
                        'type_produit' => $stock->type_produit
                    ]
                ], 400);
            }

            // Vérifier que le vendeur est bien un vendeur
            $vendeur = Utilisateur::where('id', $request->vendeur_id)
                ->where('role', 'vendeur')
                ->first();
            
            if (!$vendeur) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'L\'utilisateur avec ID ' . $request->vendeur_id . ' doit être un vendeur'
                ], 400);
            }

            // Réserver la quantité dans le stock
            if (!$stock->reserverQuantite($request->quantite_a_livrer)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur lors de la réservation de la quantité'
                ], 500);
            }

            // Créer le transport
            $transport = Transport::create([
                'distillation_id' => $stock->distillation_id,
                'stock_id' => $stock->id,
                'vendeur_id' => $vendeur->id,
                'livreur_id' => $request->livreur_id,
                'date_transport' => $request->date_transport,
                'lieu_depart' => $stock->site_production,
                'site_destination' => $request->site_destination,
                'type_matiere' => $request->type_matiere,
                'quantite_a_livrer' => $request->quantite_a_livrer,
                'ristourne_regionale' => $request->ristourne_regionale ?? 0,
                'ristourne_communale' => $request->ristourne_communale ?? 0,
                'observations' => $request->observations ?? null,
                'statut' => 'en_cours',
                
            ]);

            // Sortir la quantité du stock
            $stock->sortirQuantite($request->quantite_a_livrer);

            DB::commit();

            // Vérifier si une réception a été créée automatiquement
            $reception = null;
            try {
                $reception = \App\Models\Vente\Reception::where('transport_id', $transport->id)->first();
            } catch (\Exception $e) {
                Log::warning('Erreur vérification réception: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Transport créé avec succès',
                'data' => [
                    'transport' => $transport->load(['livreur', 'vendeur', 'stock.distillation']),
                    'stock_mis_a_jour' => [
                        'id' => $stock->id,
                        'quantite_disponible' => $stock->quantite_disponible,
                        'quantite_reservee' => $stock->quantite_reservee,
                        'quantite_sortie' => $stock->quantite_sortie,
                        'statut' => $stock->statut
                    ],
                    'reception_auto_creée' => $reception ? true : false
                ],
                'distilleur_info' => [
                    'id' => $user->id,
                    'nom_complet' => $user->nom . ' ' . $user->prenom,
                    'site_collecte' => $user->siteCollecte->Nom ?? 'Non défini'
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur création transport: ' . $e->getMessage());
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
            
            if (!in_array($user->role, ['distilleur', 'admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès réservé aux distillateurs et administrateurs'
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
            
            if (!in_array($user->role, ['distilleur', 'admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès réservé aux distillateurs et administrateurs'
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
            
            if (!in_array($user->role, ['distilleur', 'admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès réservé aux distillateurs et administrateurs'
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
            
            if (!in_array($user->role, ['distilleur', 'admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès réservé aux distillateurs et administrateurs'
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
public function getDistillationsDisponibles(): JsonResponse
{
    try {
        $user = Auth::user();
        
        if (!in_array($user->role, ['distilleur', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Accès réservé aux distillateurs et administrateurs'
            ], 403);
        }

        $distillations = Distillation::where('statut', 'termine')
            ->whereHas('expedition.ficheLivraison', function($query) use ($user) {
                $query->where('distilleur_id', $user->id);
            })
            ->with(['expedition.ficheLivraison.distilleur.siteCollecte'])
            ->orderBy('date_fin', 'desc')
            ->get();

        // Calculer les quantités disponibles SANS utiliser la relation transports()
        $distillations->each(function ($distillation) {
            // Récupérer les transports via une requête séparée
            $transports = Transport::where('distillation_id', $distillation->id)
                ->where('statut', 'en_cours')
                ->get();
            
            $quantiteDejaTransportee = $transports->sum('quantite_a_livrer');
            
            $distillation->quantite_disponible = $distillation->quantite_resultat - $quantiteDejaTransportee;
            $distillation->peut_creer_transport = $distillation->quantite_disponible > 0;
            $distillation->nombre_transports = $transports->count();
            $distillation->transports = $transports; // Ajouter les transports à l'objet
        });

        // Filtrer seulement celles avec de la quantité disponible
        $distillationsDisponibles = $distillations->where('quantite_disponible', '>', 0)->values();

        return response()->json([
            'success' => true,
            'data' => [
                'data' => $distillationsDisponibles,
                'stats' => [
                    'total_disponibles' => $distillationsDisponibles->count(),
                    'quantite_totale_disponible' => $distillationsDisponibles->sum('quantite_disponible'),
                    'distillations_completement_transportees' => $distillations->count() - $distillationsDisponibles->count()
                ]
            ],
            'distilleur_info' => [
                'id' => $user->id,
                'nom_complet' => $user->nom . ' ' . $user->prenom,
                'site_collecte' => $user->siteCollecte->Nom ?? 'Non défini'
            ]
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la récupération des distillations disponibles',
            'error' => env('APP_DEBUG') ? $e->getMessage() : null
        ], 500);
    }
}
}