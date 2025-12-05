<?php

namespace App\Http\Controllers\MatierePremiere;

use App\Http\Controllers\Controller;
use App\Models\MatierePremiere\Fournisseur;
use App\Models\MatierePremiere\PVReception;
use App\Models\PayementAvance;
use App\Models\SoldeUser;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PVReceptionController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $pvReceptions = PVReception::with(['utilisateur', 'fournisseur', 'provenance'])
                ->forUser($user)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $pvReceptions
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des PV de réception: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des PV de réception',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function getFournisseursDisponibles(): JsonResponse
{
    try {
        $fournisseurs = Fournisseur::all();
        
        $fournisseursDisponibles = $fournisseurs->filter(function ($fournisseur) {
            $aDesPaiementsEnAttente = PayementAvance::where('fournisseur_id', $fournisseur->id)
                ->where('statut', 'en_attente')
                ->exists();
            
            return !$aDesPaiementsEnAttente;
        });

        return response()->json([
            'success' => true,
            'data' => $fournisseursDisponibles->values(),
            'message' => 'Fournisseurs disponibles récupérés avec succès',
            'count' => $fournisseursDisponibles->count()
        ]);

    } catch (\Exception $e) {
        Log::error('Erreur récupération fournisseurs disponibles: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la récupération des fournisseurs disponibles',
            'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
        ], 500);
    }
}
   public function store(Request $request): JsonResponse
{
    DB::beginTransaction(); 
    
    try {
        $user = Auth::user();
        
        $rules = [
            'type' => 'required|in:FG,CG,GG',
            'date_reception' => 'required|date',
            'dette_fournisseur' => 'required|numeric|min:0',
            'utilisateur_id' => 'required|exists:utilisateurs,id',
            'fournisseur_id' => 'required|exists:fournisseurs,id',
            'provenance_id' => 'required|exists:provenances,id',
            'poids_brut' => 'required|numeric|min:0',
            'type_emballage' => 'required|in:sac,bidon,fut',
            'poids_emballage' => 'required|numeric|min:0',
            'nombre_colisage' => 'required|integer|min:1',
            'prix_unitaire' => 'required|numeric|min:0',
            'taux_humidite' => 'nullable|numeric|min:0|max:100',
            'taux_dessiccation' => 'nullable|numeric|min:0|max:100',
        ];

        $request->validate($rules);

        // Vérifier si le fournisseur a des paiements en attente
        $paiementsEnAttente = PayementAvance::where('fournisseur_id', $request->fournisseur_id)
            ->where('statut', 'en_attente')
            ->exists();

        if ($paiementsEnAttente) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de créer un PV : Ce fournisseur a des paiements en avance non réglés (en attente)'
            ], 400);
        }

        // Récupérer les paiements disponibles (arrivé et avec montant restant > 0)
        $paiementsDisponibles = PayementAvance::where('fournisseur_id', $request->fournisseur_id)
            ->where('statut', 'arrivé')
            ->where('montant_restant', '>', 0)
            ->orderBy('date', 'asc')
            ->get();

        $soldeUser = SoldeUser::where('utilisateur_id', $request->utilisateur_id)->first();
        $soldeActuel = $soldeUser ? $soldeUser->solde : 0;

        $poidsNetEstime = $this->calculerPoidsNet($request);
        $prixTotalEstime = $poidsNetEstime * $request->prix_unitaire;

        // Vérifier les permissions
        if ($user->role !== 'admin' && $request->utilisateur_id != $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez créer des PV que pour votre propre compte'
            ], 403);
        }

        // Générer le numéro de document
        $lastDoc = PVReception::where('type', $request->type)->orderBy('id', 'desc')->first();
        $docNumber = $request->type . '-' . str_pad(($lastDoc ? intval(explode('-', $lastDoc->numero_doc)[1]) : 0) + 1, 6, '0', STR_PAD_LEFT);

        // Calculer le poids net et prix total
        $poidsNet = $this->calculerPoidsNet($request);
        $prixTotal = $poidsNet * $request->prix_unitaire;

        $montantVerse = $request->dette_fournisseur;

        // CALCUL : Montant à couvrir par les paiements d'avance
        $montantACouvrirParPaiements = max(0, $prixTotal - $montantVerse);
        
        // LOGIQUE D'UTILISATION DES PAIEMENTS D'AVANCE
        $paiementsUtilises = [];
        $totalPaiementsUtilises = 0;
        $montantRestantACouvrir = $montantACouvrirParPaiements;

        foreach ($paiementsDisponibles as $paiement) {
            if ($montantRestantACouvrir <= 0) break;
            
            // Calculer combien utiliser de ce paiement (montant restant disponible)
            $montantDisponiblePaiement = $paiement->montant_restant;
            $montantAUtiliser = min($montantDisponiblePaiement, $montantRestantACouvrir);
            
            if ($montantAUtiliser <= 0) continue;
            
            // Mettre à jour le paiement (partiellement ou totalement)
            $nouveauMontantUtilise = $paiement->montant_utilise + $montantAUtiliser;
            $nouveauMontantRestant = $paiement->montant - $nouveauMontantUtilise;
            
            // Déterminer le statut du paiement
            $nouveauStatutPaiement = ($nouveauMontantRestant == 0) ? 'utilise' : 'arrivé';
            
            $paiement->update([
                'montant_utilise' => $nouveauMontantUtilise,
                'montant_restant' => $nouveauMontantRestant,
                'pv_reception_id' => null, // On mettra à jour après création du PV
                'date_utilisation' => now(),
                'statut' => $nouveauStatutPaiement
            ]);
            
            $paiementsUtilises[] = [
                'paiement' => $paiement,
                'montant_utilise' => $montantAUtiliser,
                'montant_restant_apres' => $nouveauMontantRestant
            ];
            
            $totalPaiementsUtilises += $montantAUtiliser;
            $montantRestantACouvrir -= $montantAUtiliser;
        }

        // Recalculer la dette fournisseur après utilisation des paiements
        $detteFournisseur = max(0, $prixTotal - $montantVerse - $totalPaiementsUtilises);
        $statut = ($detteFournisseur == 0) ? 'paye' : 'non_paye';

        // Créer le PV de réception
        $pvReception = PVReception::create([
            'type' => $request->type,
            'numero_doc' => $docNumber,
            'date_reception' => $request->date_reception,
            'dette_fournisseur' => $detteFournisseur,
            'utilisateur_id' => $request->utilisateur_id,
            'fournisseur_id' => $request->fournisseur_id,
            'provenance_id' => $request->provenance_id,
            'poids_brut' => $request->poids_brut,
            'type_emballage' => $request->type_emballage,
            'poids_emballage' => $request->poids_emballage,
            'poids_net' => $poidsNet,
            'nombre_colisage' => $request->nombre_colisage,
            'prix_unitaire' => $request->prix_unitaire,
            'taux_humidite' => $request->taux_humidite,
            'taux_dessiccation' => $request->taux_dessiccation,
            'prix_total' => $prixTotal,
            'statut' => $statut,
        ]);

        // Maintenant mettre à jour les paiements avec l'ID du PV créé
        foreach ($paiementsUtilises as $item) {
            $paiement = $item['paiement'];
            $paiement->update(['pv_reception_id' => $pvReception->id]);
        }

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'PV de réception créé avec succès',
            'data' => $pvReception->load(['utilisateur', 'fournisseur', 'provenance']),
            'calculs' => [
                'prix_total' => $prixTotal,
                'montant_verse_actuel' => $montantVerse,
                'montant_a_couvrir_par_paiements' => $montantACouvrirParPaiements,
                'paiements_avance_utilises' => $totalPaiementsUtilises,
                'dette_fournisseur_finale' => $detteFournisseur,
                'solde_utilisateur' => $soldeActuel,
                'statut' => $statut,
                'details_paiements' => collect($paiementsUtilises)->map(function($item) {
                    $paiement = $item['paiement'];
                    return [
                        'id' => $paiement->id,
                        'reference' => $paiement->reference,
                        'montant_total' => $paiement->montant,
                        'montant_utilise_avant' => $paiement->montant_utilise - $item['montant_utilise'],
                        'montant_utilise_ce_pv' => $item['montant_utilise'],
                        'montant_utilise_total' => $paiement->montant_utilise,
                        'montant_restant' => $paiement->montant_restant,
                        'type' => $paiement->type,
                        'statut' => $paiement->statut
                    ];
                }),
                'reste_a_couvrir_par_paiements' => max(0, $montantACouvrirParPaiements - $totalPaiementsUtilises),
                'message_paiements' => $montantRestantACouvrir > 0 ? 
                    '⚠️ Paiements d\'avance insuffisants, reste à payer: ' . number_format($montantRestantACouvrir, 0, ',', ' ') . ' Ar' :
                    '✅ Paiements d\'avance suffisants'
            ]
        ], 201);

    } catch (ValidationException $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Erreur de validation',
            'errors' => $e->errors()
        ], 422);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Erreur lors de la création du PV de réception: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la création du PV de réception',
            'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
        ], 500);
    }
}

    public function show(PVReception $pvReception): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if ($user->role !== 'admin' && $pvReception->utilisateur_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé à ce PV de réception'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => $pvReception->load(['utilisateur', 'fournisseur', 'provenance'])
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération du PV de réception: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du PV de réception',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function update(Request $request, PVReception $pvReception): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if ($user->role !== 'admin' && $pvReception->utilisateur_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé pour modifier ce PV de réception'
                ], 403);
            }

            $rules = [
                'date_reception' => 'sometimes|date',
                'dette_fournisseur' => 'sometimes|numeric|min:0',
                'poids_brut' => 'sometimes|numeric|min:0',
                'type_emballage' => 'sometimes|in:sac,bidon,fut',
                'poids_emballage' => 'sometimes|numeric|min:0',
                'nombre_colisage' => 'sometimes|integer|min:1',
                'prix_unitaire' => 'sometimes|numeric|min:0',
                'taux_humidite' => 'nullable|numeric|min:0|max:100',
                'taux_dessiccation' => 'nullable|numeric|min:0|max:100',
            ];

            $request->validate($rules);

            $data = $request->all();
            
            if ($request->hasAny(['poids_brut', 'poids_emballage', 'taux_humidite', 'taux_dessiccation', 'prix_unitaire'])) {
                $poidsNet = $this->calculerPoidsNet($request);
                $prixTotal = $poidsNet * ($request->prix_unitaire ?? $pvReception->prix_unitaire);

                $data['poids_net'] = $poidsNet;
                $data['prix_total'] = $prixTotal;
            }

            if ($request->has('dette_fournisseur')) {
                $data['statut'] = $request->dette_fournisseur == 0 ? 'paye' : 'non_paye';
            }

            $pvReception->update($data);

            return response()->json([
                'success' => true,
                'message' => 'PV de réception mis à jour avec succès',
                'data' => $pvReception->load(['utilisateur', 'fournisseur', 'provenance'])
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour du PV de réception: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du PV de réception',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function destroy(PVReception $pvReception): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if ($user->role !== 'admin' && $pvReception->utilisateur_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé pour supprimer ce PV de réception'
                ], 403);
            }

            $pvReception->delete();

            return response()->json([
                'success' => true,
                'message' => 'PV de réception supprimé avec succès'
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression du PV de réception: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression du PV de réception',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function getByType($type): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $pvReceptions = PVReception::with(['utilisateur', 'fournisseur', 'provenance'])
                ->where('type', $type)
                ->forUser($user)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $pvReceptions
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des PV par type: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des PV de réception',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    public function getByStatut($statut): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $pvReceptions = PVReception::with(['utilisateur', 'fournisseur', 'provenance'])
                ->where('statut', $statut)
                ->forUser($user)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $pvReceptions
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des PV par statut: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des PV de réception',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
            ], 500);
        }
    }

    private function calculerPoidsNet(Request $request, string $type = null): float
    {
        $poidsBrut = $request->poids_brut;
        $poidsEmballage = $request->poids_emballage;
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

    public function getInfosFournisseur($fournisseur_id): JsonResponse
{
    try {
        $fournisseur = Fournisseur::find($fournisseur_id);
        
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
                    'a_des_paiements_disponibles' => $paiementsDisponibles->isNotEmpty()
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
                    'peut_creer_pv' => !$aDesPaiementsEnAttente,
                    'montant_utilisable' => $totalPaiementsDisponibles,
                    'a_des_paiements_disponibles' => $paiementsDisponibles->isNotEmpty(),
                    'a_des_paiements_en_attente' => $aDesPaiementsEnAttente,
                    'a_des_paiements_partiels' => $paiementsPartiels->isNotEmpty(),
                    'alertes' => $aDesPaiementsEnAttente ? 
                        'Ce fournisseur a des paiements en avance non réglés' : 
                        ($paiementsDisponibles->isNotEmpty() ? 
                            'Ce fournisseur a des paiements disponibles à utiliser' : 
                            'Aucune avance disponible')
                ]
            ],
            'message' => 'Informations fournisseur récupérées avec succès'
        ]);

    } catch (\Exception $e) {
        Log::error('Erreur récupération infos fournisseur: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la récupération des informations du fournisseur',
            'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
        ], 500);
    }
}
}