<?php

namespace App\Http\Controllers\TestHuille;

use App\Http\Controllers\Controller;
use App\Models\PayementAvance;
use App\Models\TestHuille\FicheReception;
use App\Models\SoldeUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class FicheReceptionController extends Controller
{
    
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
                'poids_brut' => 'required|numeric|min:0',
                'poids_agreer' => 'nullable|numeric|min:0',
                'taux_humidite' => 'nullable|numeric|min:0|max:100',
                'taux_dessiccation' => 'nullable|numeric|min:0|max:100',
                'type_emballage' => 'nullable|in:sac,bidon,fut',
                'poids_emballage' => 'nullable|numeric|min:0',
                'nombre_colisage' => 'nullable|integer|min:0',
                'prix_unitaire' => 'nullable|numeric|min:0',
                'prix_total' => 'nullable|numeric|min:0'
            ]);

            // VÉRIFICATION PAIEMENT EN AVANCE
            $paiementEnAttente = PayementAvance::where('fournisseur_id', $validated['fournisseur_id'])
                ->where('statut', 'en_attente')
                ->exists();

            if ($paiementEnAttente) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de créer la fiche : Ce fournisseur a un paiement en avance en attente de confirmation'
                ], 400);
            }

            if ($user->role !== 'admin' && $validated['utilisateur_id'] != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous ne pouvez créer des fiches que pour votre propre compte'
                ], 403);
            }

            $numeroDocument = 'REC-' . date('Ymd') . '-' . Str::upper(Str::random(6));

            // CALCUL DU POIDS NET
            $poidsNet = $this->calculerPoidsNet($request);
            $poidsAgreer = $validated['poids_agreer'] ?? $poidsNet;

            // CALCUL DU PRIX TOTAL
            $prixTotal = $validated['prix_total'] ?? null;
            if (empty($prixTotal) && !empty($validated['prix_unitaire'])) {
                $prixTotal = $poidsNet * $validated['prix_unitaire'];
            }

            // LOGIQUE D'UTILISATION DES PAIEMENTS D'AVANCE
            $paiementsUtilises = [];
            $totalPaiementsUtilises = 0;
            
            // Le statut reste toujours "en attente de teste" quel que soit le paiement
            $statutFiche = 'en attente de teste';

            if ($prixTotal > 0) {
                // Récupérer UN SEUL paiement disponible (le premier arrivé)
                $paiementDisponible = PayementAvance::where('fournisseur_id', $validated['fournisseur_id'])
                    ->where('statut', 'arrivé')
                    ->where('montant_restant', '>', 0)
                    ->orderBy('date', 'asc')
                    ->first();

                if ($paiementDisponible) {
                    // Calculer combien utiliser de ce paiement
                    $montantDisponiblePaiement = $paiementDisponible->montant_restant;
                    $montantAUtiliser = min($montantDisponiblePaiement, $prixTotal);
                    
                    if ($montantAUtiliser > 0) {
                        $nouveauMontantUtilise = $paiementDisponible->montant_utilise + $montantAUtiliser;
                        $nouveauMontantRestant = $paiementDisponible->montant - $nouveauMontantUtilise;
                        $nouveauStatutPaiement = ($nouveauMontantRestant == 0) ? 'utilise' : 'arrivé';
                        
                        $paiementDisponible->update([
                            'montant_utilise' => $nouveauMontantUtilise,
                            'montant_restant' => $nouveauMontantRestant,
                            'fiche_reception_id' => null, // On mettra à jour après création de la fiche
                            'date_utilisation' => now(),
                            'statut' => $nouveauStatutPaiement
                        ]);
                        
                        $paiementsUtilises[] = [
                            'paiement' => $paiementDisponible,
                            'montant_utilise' => $montantAUtiliser,
                            'montant_restant_apres' => $nouveauMontantRestant
                        ];
                        
                        $totalPaiementsUtilises = $montantAUtiliser;
                    }
                }
            }

            // Créer la fiche de réception
            $fiche = FicheReception::create([
                'numero_document' => $numeroDocument,
                'date_reception' => $validated['date_reception'],
                'heure_reception' => $validated['heure_reception'],
                'fournisseur_id' => $validated['fournisseur_id'],
                'site_collecte_id' => $validated['site_collecte_id'],
                'utilisateur_id' => $validated['utilisateur_id'],
                'poids_brut' => $validated['poids_brut'],
                'poids_agreer' => $poidsAgreer,
                'taux_humidite' => $validated['taux_humidite'] ?? null,
                'taux_dessiccation' => $validated['taux_dessiccation'] ?? null,
                'poids_net' => $poidsNet,
                'type_emballage' => $validated['type_emballage'] ?? null,
                'poids_emballage' => $validated['poids_emballage'] ?? null,
                'nombre_colisage' => $validated['nombre_colisage'] ?? null,
                'prix_unitaire' => $validated['prix_unitaire'] ?? null,
                'prix_total' => $prixTotal,
                'statut' => $statutFiche // Toujours "en attente de teste"
            ]);

            // Maintenant mettre à jour les paiements avec l'ID de la fiche créée
            foreach ($paiementsUtilises as $item) {
                $paiement = $item['paiement'];
                $paiement->update(['fiche_reception_id' => $fiche->id]);
            }

            DB::commit();

            $fiche->load(['fournisseur', 'siteCollecte', 'utilisateur']);

            // Préparer le message de réponse
            $messagePaiements = '';
            if ($prixTotal > 0) {
                if ($totalPaiementsUtilises > 0) {
                    $resteAPayer = $prixTotal - $totalPaiementsUtilises;
                    if ($resteAPayer > 0) {
                        $messagePaiements = '✅ Paiement en avance utilisé: ' . number_format($totalPaiementsUtilises, 0, ',', ' ') . 
                                          ' Ar. Reste à payer: ' . number_format($resteAPayer, 0, ',', ' ') . ' Ar';
                    } else {
                        $messagePaiements = '✅ Paiement en avance utilisé entièrement pour couvrir le prix total';
                    }
                } else {
                    $messagePaiements = 'ℹ️ Aucun paiement en avance disponible, prix total à payer: ' . 
                                      number_format($prixTotal, 0, ',', ' ') . ' Ar';
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Fiche de réception créée avec succès',
                'data' => $fiche,
                'calculs' => [
                    'poids_net_calcule' => $poidsNet,
                    'prix_total_calcule' => $prixTotal,
                    'paiement_avance_utilise' => $totalPaiementsUtilises,
                    'reste_a_payer' => $prixTotal > 0 ? max(0, $prixTotal - $totalPaiementsUtilises) : 0,
                    'details_paiement' => !empty($paiementsUtilises) ? [
                        'id' => $paiementsUtilises[0]['paiement']->id,
                        'reference' => $paiementsUtilises[0]['paiement']->reference,
                        'montant_total' => $paiementsUtilises[0]['paiement']->montant,
                        'montant_utilise_avant' => $paiementsUtilises[0]['paiement']->montant_utilise - $paiementsUtilises[0]['montant_utilise'],
                        'montant_utilise_ce_fiche' => $paiementsUtilises[0]['montant_utilise'],
                        'montant_utilise_total' => $paiementsUtilises[0]['paiement']->montant_utilise,
                        'montant_restant' => $paiementsUtilises[0]['paiement']->montant_restant,
                        'type' => $paiementsUtilises[0]['paiement']->type,
                        'statut' => $paiementsUtilises[0]['paiement']->statut
                    ] : null,
                    'message_paiements' => $messagePaiements
                ]
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

            $validated = $request->validate([
                'date_reception' => 'sometimes|date',
                'heure_reception' => 'sometimes|date_format:H:i',
                'fournisseur_id' => 'sometimes|exists:fournisseurs,id',
                'site_collecte_id' => 'sometimes|exists:site_collectes,id',
                'utilisateur_id' => 'sometimes|exists:utilisateurs,id',
                'poids_brut' => 'sometimes|numeric|min:0',
                'poids_agreer' => 'nullable|numeric|min:0',
                'taux_humidite' => 'nullable|numeric|min:0|max:100',
                'taux_dessiccation' => 'nullable|numeric|min:0|max:100',
                'type_emballage' => 'nullable|in:sac,bidon,fut',
                'poids_emballage' => 'nullable|numeric|min:0',
                'nombre_colisage' => 'nullable|integer|min:0',
                'prix_unitaire' => 'nullable|numeric|min:0',
                'prix_total' => 'nullable|numeric|min:0',
                'statut' => 'sometimes|in:en attente de teste,en cours de teste,Accepté,teste validé,teste invalide,En attente de livraison,payé,incomplet,partiellement payé,en attente de paiement,livré,Refusé,A retraiter'
            ]);

            // RECALCUL DU POIDS NET SI NÉCESSAIRE
            if ($request->hasAny(['poids_brut', 'poids_emballage', 'taux_humidite', 'taux_dessiccation'])) {
                $poidsNet = $this->calculerPoidsNet($request);
                $validated['poids_net'] = $poidsNet;
                
                // Si poids_agreer n'est pas fourni, le mettre à jour avec le nouveau poids_net
                if (!$request->has('poids_agreer')) {
                    $validated['poids_agreer'] = $poidsNet;
                }
            }

            // RECALCUL DU PRIX TOTAL SI PRIX_UNITAIRE EST MODIFIÉ
            if ($request->has('prix_unitaire') && !empty($request->prix_unitaire)) {
                $poidsNet = $validated['poids_net'] ?? $fiche->poids_net;
                $validated['prix_total'] = $poidsNet * $request->prix_unitaire;
            }

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
public function getInfosFournisseur($fournisseur_id)
{
    try {
        $user = Auth::user();
        
        $fournisseur = \App\Models\MatierePremiere\Fournisseur::find($fournisseur_id);
        
        if (!$fournisseur) {
            return response()->json([
                'success' => false,
                'message' => 'Fournisseur non trouvé'
            ], 404);
        }

        // Paiements disponibles (arrivé avec montant_restant > 0)
        $paiementsDisponibles = PayementAvance::where('fournisseur_id', $fournisseur_id)
            ->where('statut', 'arrivé')
            ->where('montant_restant', '>', 0)
            ->orderBy('date', 'asc')
            ->get();

        // Paiements en attente
        $paiementsEnAttente = PayementAvance::where('fournisseur_id', $fournisseur_id)
            ->where('statut', 'en_attente')
            ->get();

        // Paiements utilisés (complètement utilisés)
        $paiementsUtilises = PayementAvance::where('fournisseur_id', $fournisseur_id)
            ->where('statut', 'utilise')
            ->get();

        // Paiements partiellement utilisés (arrivé avec montant_restant > 0 mais montant_utilise > 0)
        $paiementsPartiels = PayementAvance::where('fournisseur_id', $fournisseur_id)
            ->where('statut', 'arrivé')
            ->where('montant_restant', '>', 0)
            ->where('montant_utilise', '>', 0)
            ->get();

        // Calcul des totaux CORRIGÉS
        $totalPaiementsDisponibles = $paiementsDisponibles->sum('montant_restant'); // Seulement le montant restant
        $totalPaiementsEnAttente = $paiementsEnAttente->sum('montant');

        // Vérifier si le fournisseur a des paiements en attente
        $aDesPaiementsEnAttente = $paiementsEnAttente->isNotEmpty();

        // Vérifier si l'utilisateur a accès à ce fournisseur
        $estAutorise = ($user->role === 'admin') || ($user->role === 'collecteur');

        return response()->json([
            'success' => true,
            'data' => [
                'fournisseur' => [
                    'id' => $fournisseur->id,
                    'nom' => $fournisseur->nom,
                    'prenom' => $fournisseur->prenom,
                    'contact' => $fournisseur->contact,
                    'adresse' => $fournisseur->adresse,
                    'est_disponible' => !$aDesPaiementsEnAttente,
                    'a_des_paiements_disponibles' => $paiementsDisponibles->isNotEmpty(),
                    'est_autorise' => $estAutorise
                ],
                'paiements_avance' => [
                    'total_disponibles' => $totalPaiementsDisponibles,
                    'total_en_attente' => $totalPaiementsEnAttente,
                    'details_disponibles' => $paiementsDisponibles->map(function($paiement) {
                        return [
                            'id' => $paiement->id,
                            'reference' => $paiement->reference,
                            'montant' => $paiement->montant, // Montant total
                            'montant_utilise' => $paiement->montant_utilise, // Montant déjà utilisé
                            'montant_restant' => $paiement->montant_restant, // Montant encore disponible
                            'type' => $paiement->type,
                            'date' => $paiement->date,
                            'description' => $paiement->description,
                            'pourcentage_utilise' => $paiement->montant > 0 ? 
                                round(($paiement->montant_utilise / $paiement->montant) * 100, 2) : 0,
                            'pourcentage_restant' => $paiement->montant > 0 ? 
                                round(($paiement->montant_restant / $paiement->montant) * 100, 2) : 0
                        ];
                    }),
                    'details_en_attente' => $paiementsEnAttente->map(function($paiement) {
                        return [
                            'id' => $paiement->id,
                            'reference' => $paiement->reference,
                            'montant' => $paiement->montant,
                            'montant_utilise' => $paiement->montant_utilise,
                            'montant_restant' => $paiement->montant_restant,
                            'type' => $paiement->type,
                            'date' => $paiement->date,
                            'delai_heures' => $paiement->delaiHeures,
                            'est_en_retard' => $paiement->estEnRetard(),
                            'temps_restant' => $paiement->tempsRestant(),
                            'description' => $paiement->description
                        ];
                    }),
                    'details_partiels' => $paiementsPartiels->map(function($paiement) {
                        return [
                            'id' => $paiement->id,
                            'reference' => $paiement->reference,
                            'montant' => $paiement->montant,
                            'montant_utilise' => $paiement->montant_utilise,
                            'montant_restant' => $paiement->montant_restant,
                            'type' => $paiement->type,
                            'date' => $paiement->date,
                            'pourcentage_utilise' => round(($paiement->montant_utilise / $paiement->montant) * 100, 2),
                            'description' => $paiement->description
                        ];
                    }),
                    'details_utilises' => $paiementsUtilises->map(function($paiement) {
                        return [
                            'id' => $paiement->id,
                            'reference' => $paiement->reference,
                            'montant' => $paiement->montant,
                            'montant_utilise' => $paiement->montant_utilise,
                            'montant_restant' => $paiement->montant_restant,
                            'type' => $paiement->type,
                            'date' => $paiement->date,
                            'date_utilisation' => $paiement->date_utilisation,
                            'description' => $paiement->description
                        ];
                    })
                ],
                'statistiques' => [
                    'nombre_paiements_disponibles' => $paiementsDisponibles->count(),
                    'nombre_paiements_en_attente' => $paiementsEnAttente->count(),
                    'nombre_paiements_utilises' => $paiementsUtilises->count(),
                    'nombre_paiements_partiels' => $paiementsPartiels->count(),
                    'montant_total_initial' => PayementAvance::where('fournisseur_id', $fournisseur_id)->sum('montant'),
                    'montant_total_utilise' => PayementAvance::where('fournisseur_id', $fournisseur_id)->sum('montant_utilise'),
                    'montant_total_restant' => PayementAvance::where('fournisseur_id', $fournisseur_id)
                        ->whereIn('statut', ['arrivé', 'en_attente'])
                        ->sum('montant_restant')
                ],
                'resume' => [
                    'peut_creer_fiche' => !$aDesPaiementsEnAttente && $estAutorise,
                    'montant_utilisable' => $totalPaiementsDisponibles,
                    'a_des_paiements_disponibles' => $paiementsDisponibles->isNotEmpty(),
                    'a_des_paiements_en_attente' => $aDesPaiementsEnAttente,
                    'a_des_paiements_partiels' => $paiementsPartiels->isNotEmpty(),
                    'alertes' => $aDesPaiementsEnAttente ? 
                        'Ce fournisseur a des paiements en avance non réglés' : 
                        (!$estAutorise ? 'Non autorisé à créer des fiches pour ce fournisseur' :
                        ($paiementsDisponibles->isNotEmpty() ? 
                            'Ce fournisseur a des paiements disponibles à utiliser' : 
                            'Aucune avance disponible'))
                ]
            ],
            'message' => 'Informations fournisseur récupérées avec succès'
        ]);

    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error('Erreur récupération infos fournisseur fiche: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la récupération des informations du fournisseur',
            'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
        ], 500);
    }
}
    private function calculerPoidsNet(Request $request): float
    {
        $poidsBrut = $request->poids_brut;
        $poidsEmballage = $request->poids_emballage ?? 0;
        $tauxHumidite = $request->taux_humidite;
        $tauxDessiccation = $request->taux_dessiccation;
        
        $poidsSansEmballage = $poidsBrut - $poidsEmballage;
        
        if ($tauxHumidite !== null && $tauxDessiccation !== null && $tauxHumidite > $tauxDessiccation) {
            $excesHumidite = $tauxHumidite - $tauxDessiccation;
            $dessiccation = $poidsSansEmballage * ($excesHumidite / 100);
            return $poidsSansEmballage - $dessiccation;
        }
        
        return $poidsSansEmballage;
    } 

    
    
}