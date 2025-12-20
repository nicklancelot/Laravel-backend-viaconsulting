<?php

namespace App\Http\Controllers\Vente;

use App\Http\Controllers\Controller;
use App\Models\Vente\Reception;
use App\Models\TestHuille\HEFicheLivraison;
use App\Models\Distillation\Transport;
use App\Models\Utilisateur;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReceptionController extends Controller
{
    /**
     * Afficher la liste de toutes les réceptions
     */
    public function index()
    {
        try {
            $user = Auth::user();
            
            $receptions = Reception::with([
                    'ficheLivraison.stockhe',
                    'ficheLivraison.livreur',
                    'transport.distillation',
                    'transport.livreur',
                    'vendeur'
                ])
                ->when($user->role === 'vendeur', function ($query) use ($user) {
                    return $query->where('vendeur_id', $user->id);
                })
                ->orderBy('created_at', 'desc')
                ->get();
            
            // Ajouter des informations formatées
            $receptions->each(function ($reception) {
                $reception->informations_source = $this->formaterInformationsSource($reception);
                $reception->peut_marquer_receptionne = $reception->estEnAttente();
            });
            
            // Statistiques
            $stats = [
                'total' => $receptions->count(),
                'en_attente' => $receptions->where('statut', 'en attente')->count(),
                'receptionne' => $receptions->where('statut', 'receptionne')->count(),
                'annule' => $receptions->where('statut', 'annule')->count(),
                'par_type' => [
                    'fiche_livraison' => $receptions->where('type_livraison', 'fiche_livraison')->count(),
                    'transport' => $receptions->where('type_livraison', 'transport')->count()
                ]
            ];
            
            return response()->json([
                'success' => true,
                'message' => 'Liste des réceptions',
                'data' => $receptions,
                'stats' => $stats,
                'count' => $receptions->count()
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur récupération réceptions: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des réceptions',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Créer manuellement une réception (pour tests ou corrections)
     */
    public function store(Request $request)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validate([
                'fiche_livraison_id' => 'nullable|exists:h_e_fiche_livraisons,id',
                'transport_id' => 'nullable|exists:transports,id',
                'date_reception' => 'required|date',
                'heure_reception' => 'nullable|date_format:H:i',
                'observations' => 'nullable|string',
                'quantite_recue' => 'required|numeric|min:0',
                'lieu_reception' => 'required|string|max:100'
            ]);

            // Vérifier qu'on a soit fiche_livraison_id, soit transport_id, pas les deux
            if ($validated['fiche_livraison_id'] && $validated['transport_id']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Spécifiez soit fiche_livraison_id, soit transport_id, pas les deux'
                ], 400);
            }

            if (!$validated['fiche_livraison_id'] && !$validated['transport_id']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Spécifiez soit fiche_livraison_id, soit transport_id'
                ], 400);
            }

            $user = Auth::user();
            $source = null;
            $vendeurId = null;

            // Vérification selon le type
            if ($validated['fiche_livraison_id']) {
                $ficheLivraison = HEFicheLivraison::with('vendeur')->find($validated['fiche_livraison_id']);
                if (!$ficheLivraison) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Fiche de livraison non trouvée'
                    ], 404);
                }

                // Vérifier que la fiche est bien livrée
                if (!$ficheLivraison->estLivree()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Seules les fiches de livraison avec statut "livrée" peuvent être réceptionnées'
                    ], 400);
                }

                $vendeurId = $ficheLivraison->vendeur_id;
                $source = $ficheLivraison;
                
                // Vérifier les permissions
                if ($user->role === 'vendeur' && $ficheLivraison->vendeur_id != $user->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Vous ne pouvez réceptionner que vos propres livraisons'
                    ], 403);
                }

            } elseif ($validated['transport_id']) {
                $transport = Transport::with('vendeur')->find($validated['transport_id']);
                if (!$transport) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Transport non trouvé'
                    ], 404);
                }

                // Vérifier que le transport est bien livré
                if (!$transport->estLivre()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Seuls les transports avec statut "livré" peuvent être réceptionnés'
                    ], 400);
                }

                $vendeurId = $transport->vendeur_id;
                $source = $transport;
                
                // Vérifier les permissions
                if ($user->role === 'vendeur' && $transport->vendeur_id != $user->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Vous ne pouvez réceptionner que vos propres transports'
                    ], 403);
                }
            }

            // Vérifier la quantité
            if ($validated['quantite_recue'] > $source->quantite_a_livrer) {
                return response()->json([
                    'success' => false,
                    'message' => 'La quantité reçue ne peut pas dépasser la quantité livrée (' . $source->quantite_a_livrer . ')'
                ], 400);
            }

            // Vérifier qu'il n'y a pas déjà une réception
            $existingReception = Reception::where(function($query) use ($validated) {
                if ($validated['fiche_livraison_id']) {
                    $query->where('fiche_livraison_id', $validated['fiche_livraison_id']);
                } else {
                    $query->where('transport_id', $validated['transport_id']);
                }
            })->first();

            if ($existingReception) {
                return response()->json([
                    'success' => false,
                    'message' => 'Une réception existe déjà pour cette livraison/transport'
                ], 409);
            }

            $reception = Reception::create([
                'fiche_livraison_id' => $validated['fiche_livraison_id'] ?? null,
                'transport_id' => $validated['transport_id'] ?? null,
                'vendeur_id' => $vendeurId,
                'date_reception' => $validated['date_reception'],
                'heure_reception' => $validated['heure_reception'] ?? null,
                'statut' => 'en attente',
                'observations' => $validated['observations'] ?? null,
                'quantite_recue' => $validated['quantite_recue'],
                'lieu_reception' => $validated['lieu_reception']
            ]);

            DB::commit();

            $reception->load(['ficheLivraison', 'transport', 'vendeur']);
            $reception->informations_source = $this->formaterInformationsSource($reception);

            return response()->json([
                'success' => true,
                'message' => 'Réception créée avec succès (statut: en attente)',
                'data' => $reception
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
            Log::error('Erreur création réception: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de la réception',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Afficher une réception spécifique
     */
    public function show($id)
    {
        try {
            $user = Auth::user();
            $reception = Reception::with([
                    'ficheLivraison.stockhe',
                    'ficheLivraison.livreur',
                    'transport.distillation',
                    'transport.livreur',
                    'vendeur'
                ])->find($id);

            if (!$reception) {
                return response()->json([
                    'success' => false,
                    'message' => 'Réception non trouvée'
                ], 404);
            }

            // Vérifier les permissions
            if ($user->role === 'vendeur' && $reception->vendeur_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé à cette réception'
                ], 403);
            }

            $reception->informations_source = $this->formaterInformationsSource($reception);
            $reception->peut_marquer_receptionne = $reception->estEnAttente();

            return response()->json([
                'success' => true,
                'message' => 'Réception trouvée',
                'data' => $reception
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur récupération réception: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la réception',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Mettre à jour une réception
     */
    public function update(Request $request, $id)
    {
        try {
            DB::beginTransaction();

            $user = Auth::user();
            $reception = Reception::with(['ficheLivraison', 'transport'])->find($id);

            if (!$reception) {
                return response()->json([
                    'success' => false,
                    'message' => 'Réception non trouvée'
                ], 404);
            }

            // Vérifier les permissions
            if ($user->role === 'vendeur' && $reception->vendeur_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous ne pouvez modifier que vos propres réceptions'
                ], 403);
            }

            // Ne pas permettre la modification si déjà réceptionnée
            if ($reception->estReceptionne()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de modifier une réception déjà réceptionnée'
                ], 400);
            }

            $validated = $request->validate([
                'date_reception' => 'sometimes|date',
                'heure_reception' => 'nullable|date_format:H:i',
                'observations' => 'nullable|string',
                'quantite_recue' => 'sometimes|numeric|min:0',
                'lieu_reception' => 'sometimes|string|max:100'
            ]);

            // Vérifier la quantité si modifiée
            if (isset($validated['quantite_recue'])) {
                $source = $reception->source();
                if ($validated['quantite_recue'] > $source->quantite_a_livrer) {
                    return response()->json([
                        'success' => false,
                        'message' => 'La quantité reçue ne peut pas dépasser la quantité livrée (' . $source->quantite_a_livrer . ')'
                    ], 400);
                }
            }

            $reception->update($validated);

            DB::commit();

            $reception->load(['ficheLivraison', 'transport', 'vendeur']);
            $reception->informations_source = $this->formaterInformationsSource($reception);

            return response()->json([
                'success' => true,
                'message' => 'Réception mise à jour avec succès',
                'data' => $reception
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
            Log::error('Erreur mise à jour réception: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour de la réception',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Supprimer une réception
     */
    public function destroy($id)
    {
        try {
            DB::beginTransaction();

            $user = Auth::user();
            $reception = Reception::find($id);

            if (!$reception) {
                return response()->json([
                    'success' => false,
                    'message' => 'Réception non trouvée'
                ], 404);
            }

            // Vérifier les permissions
            if ($user->role === 'vendeur' && $reception->vendeur_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous ne pouvez supprimer que vos propres réceptions'
                ], 403);
            }

            // Ne pas permettre la suppression si déjà réceptionnée
            if ($reception->estReceptionne()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de supprimer une réception déjà réceptionnée'
                ], 400);
            }

            $reception->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Réception supprimée avec succès'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur suppression réception: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la réception',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Marquer une réception comme réceptionnée
     */
    public function marquerReceptionne(Request $request, $id)
    {
        try {
            DB::beginTransaction();

            $user = Auth::user();
            $reception = Reception::with(['ficheLivraison', 'transport'])->find($id);

            if (!$reception) {
                return response()->json([
                    'success' => false,
                    'message' => 'Réception non trouvée'
                ], 404);
            }

            // Vérifier que seul le vendeur concerné peut marquer comme réceptionné
            if ($reception->vendeur_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Seul le vendeur concerné peut marquer la réception comme réceptionnée'
                ], 403);
            }

            // Vérifier que la réception est en attente
            if (!$reception->estEnAttente()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Seules les réceptions en attente peuvent être marquées comme réceptionnées'
                ], 400);
            }

            $validated = $request->validate([
                'observations' => 'nullable|string'
            ]);

            // Marquer comme réceptionnée 
            $reception->marquerReceptionne([
                'observations' => $validated['observations'] ?? null
            ]);

            DB::commit();

            $reception->load(['ficheLivraison', 'transport', 'vendeur']);
            $reception->informations_source = $this->formaterInformationsSource($reception);

            return response()->json([
                'success' => true,
                'message' => 'Réception marquée comme réceptionnée avec succès',
                'data' => $reception
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
            Log::error('Erreur marquage réception: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du marquage de la réception',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Marquer une réception comme annulée
     */
    public function marquerAnnule(Request $request, $id)
    {
        try {
            DB::beginTransaction();

            $user = Auth::user();
            $reception = Reception::find($id);

            if (!$reception) {
                return response()->json([
                    'success' => false,
                    'message' => 'Réception non trouvée'
                ], 404);
            }

            // Vérifier les permissions (vendeur ou admin)
            if ($user->role === 'vendeur' && $reception->vendeur_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous ne pouvez annuler que vos propres réceptions'
                ], 403);
            }

            $validated = $request->validate([
                'raison' => 'required|string|max:255'
            ]);

            // Marquer comme annulée
            $reception->marquerAnnule($validated['raison']);

            DB::commit();

            $reception->load(['ficheLivraison', 'transport', 'vendeur']);
            $reception->informations_source = $this->formaterInformationsSource($reception);

            return response()->json([
                'success' => true,
                'message' => 'Réception annulée avec succès',
                'data' => $reception
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
            Log::error('Erreur annulation réception: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'annulation de la réception',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Récupérer les réceptions par statut
     */
    public function getByStatut($statut)
    {
        try {
            $user = Auth::user();
            $statutsValides = ['en attente', 'receptionne', 'annule'];
            
            if (!in_array($statut, $statutsValides)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Statut invalide',
                    'statuts_valides' => $statutsValides
                ], 400);
            }

            $receptions = Reception::with([
                    'ficheLivraison.stockhe',
                    'ficheLivraison.livreur',
                    'transport.distillation',
                    'transport.livreur',
                    'vendeur'
                ])
                ->where('statut', $statut)
                ->when($user->role === 'vendeur', function ($query) use ($user) {
                    return $query->where('vendeur_id', $user->id);
                })
                ->orderBy('created_at', 'desc')
                ->get();

            // Ajouter des informations
            $receptions->each(function ($reception) {
                $reception->informations_source = $this->formaterInformationsSource($reception);
            });

            return response()->json([
                'success' => true,
                'message' => 'Réceptions avec statut: ' . $statut,
                'data' => $receptions,
                'count' => $receptions->count()
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur récupération réceptions par statut: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des réceptions',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Récupérer les réceptions d'une fiche de livraison
     */
    public function getByFicheLivraison($ficheLivraisonId)
    {
        try {
            $user = Auth::user();
            
            $receptions = Reception::with([
                    'ficheLivraison.stockhe',
                    'ficheLivraison.livreur',
                    'vendeur'
                ])
                ->where('fiche_livraison_id', $ficheLivraisonId)
                ->when($user->role === 'vendeur', function ($query) use ($user) {
                    return $query->where('vendeur_id', $user->id);
                })
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Réceptions pour la fiche de livraison',
                'data' => $receptions,
                'count' => $receptions->count()
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur récupération réceptions fiche livraison: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des réceptions',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Récupérer les réceptions d'un transport
     */
    public function getByTransport($transportId)
    {
        try {
            $user = Auth::user();
            
            $receptions = Reception::with([
                    'transport.distillation',
                    'transport.livreur',
                    'vendeur'
                ])
                ->where('transport_id', $transportId)
                ->when($user->role === 'vendeur', function ($query) use ($user) {
                    return $query->where('vendeur_id', $user->id);
                })
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Réceptions pour le transport',
                'data' => $receptions,
                'count' => $receptions->count()
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur récupération réceptions transport: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des réceptions',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Récupérer les réceptions du vendeur connecté
     */
    public function getMesReceptions()
    {
        try {
            $user = Auth::user();
            
            if ($user->role !== 'vendeur') {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès réservé aux vendeurs'
                ], 403);
            }

            $receptions = Reception::with([
                    'ficheLivraison.stockhe',
                    'ficheLivraison.livreur',
                    'transport.distillation',
                    'transport.livreur',
                    'vendeur'
                ])
                ->where('vendeur_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();

            // Ajouter des informations
            $receptions->each(function ($reception) {
                $reception->informations_source = $this->formaterInformationsSource($reception);
                $reception->peut_marquer_receptionne = $reception->estEnAttente();
            });

            // Statistiques
            $stats = [
                'total' => $receptions->count(),
                'en_attente' => $receptions->where('statut', 'en attente')->count(),
                'receptionne' => $receptions->where('statut', 'receptionne')->count(),
                'annule' => $receptions->where('statut', 'annule')->count(),
                'quantite_totale_recue' => $receptions->sum('quantite_recue'),
                'quantite_en_attente' => $receptions->where('statut', 'en attente')->sum('quantite_recue')
            ];

            return response()->json([
                'success' => true,
                'message' => 'Mes réceptions',
                'data' => $receptions,
                'stats' => $stats,
                'count' => $receptions->count(),
                'vendeur_info' => [
                    'id' => $user->id,
                    'nom_complet' => $user->nom . ' ' . $user->prenom,
                    'localisation' => $user->localisation->Nom ?? 'Non défini'
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur récupération mes réceptions: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de vos réceptions',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Formater les informations de la source (fiche livraison ou transport)
     */
    private function formaterInformationsSource(Reception $reception): array
    {
        if ($reception->estFicheLivraison() && $reception->ficheLivraison) {
            return [
                'type' => 'fiche_livraison',
                'nom' => 'Livraison Stock HE',
                'id' => $reception->fiche_livraison_id,
                'quantite_totale' => $reception->ficheLivraison->quantite_a_livrer,
                'livreur' => $reception->ficheLivraison->livreur->nom_complet ?? 'Non défini',
                'date_livraison' => $reception->ficheLivraison->date_heure_livraison,
                'destination' => $reception->ficheLivraison->destination,
                'type_produit' => $reception->ficheLivraison->type_produit
            ];
        } elseif ($reception->estTransport() && $reception->transport) {
            return [
                'type' => 'transport',
                'nom' => 'Transport Distillation',
                'id' => $reception->transport_id,
                'quantite_totale' => $reception->transport->quantite_a_livrer,
                'livreur' => $reception->transport->livreur->nom_complet ?? 'Non défini',
                'date_livraison' => $reception->transport->date_livraison ?? $reception->transport->date_transport,
                'destination' => $reception->transport->site_destination,
                'type_matiere' => $reception->transport->type_matiere,
                'distillation_id' => $reception->transport->distillation_id
            ];
        }

        return ['type' => 'inconnu', 'nom' => 'Source inconnue'];
    }
}