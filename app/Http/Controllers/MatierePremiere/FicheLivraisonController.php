<?php

namespace App\Http\Controllers\MatierePremiere;

use App\Http\Controllers\Controller;
use App\Models\MatierePremiere\FicheLivraison;
use App\Models\MatierePremiere\PVReception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\Livreur;
use App\Models\Destinateur;

class FicheLivraisonController extends Controller
{
    public function store(Request $request)
{
    try {
        $validator = Validator::make($request->all(), [
            'pv_reception_id' => 'required|exists:p_v_receptions,id',
            'livreur_id' => 'required|exists:livreurs,id',
            'destinateur_id' => 'required|exists:destinateurs,id',
            'date_livraison' => 'required|date',
            'lieu_depart' => 'required|string|max:255',
            'ristourne_regionale' => 'nullable|numeric|min:0',
            'ristourne_communale' => 'nullable|numeric|min:0',
            'quantite_a_livrer' => 'required|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur de validation',
                'errors' => $validator->errors()
            ], 422);
        }

        $pvReception = PVReception::find($request->pv_reception_id);
        
        $statutsAutorises = ['paye', 'partiellement_livre'];
        if (!$pvReception) {
            return response()->json([
                'status' => 'error',
                'message' => 'PV de réception non trouvé'
            ], 404);
        }

        if (!in_array($pvReception->statut, $statutsAutorises)) {
            return response()->json([
                'status' => 'error',
                'message' => 'PV non autorisé pour livraison. Statut actuel: ' . $pvReception->statut
            ], 422);
        }

        // VÉRIFIER LE STOCK RESTANT
        if ($request->quantite_a_livrer > $pvReception->quantite_restante) {
            return response()->json([
                'status' => 'error',
                'message' => 'Quantité à livrer supérieure au stock disponible. Stock restant: ' . $pvReception->quantite_restante . ' kg'
            ], 422);
        }

        // Vérifier s'il existe déjà une fiche en attente pour ce PV
        $ficheEnAttente = FicheLivraison::where('pv_reception_id', $request->pv_reception_id)
            ->whereDoesntHave('livraison')
            ->first();

        if ($ficheEnAttente) {
            return response()->json([
                'status' => 'error',
                'message' => 'Une fiche de livraison est déjà en attente pour ce PV. Veuillez confirmer la livraison en cours avant d\'en créer une nouvelle.'
            ], 422);
        }

        // Déterminer si c'est une livraison partielle
        $estPartielle = $request->quantite_a_livrer < $pvReception->quantite_restante;

        // Créer la fiche avec les références aux modèles
        $ficheLivraison = FicheLivraison::create([
            'pv_reception_id' => $request->pv_reception_id,
            'livreur_id' => $request->livreur_id,
            'destinateur_id' => $request->destinateur_id,
            'date_livraison' => $request->date_livraison,
            'lieu_depart' => $request->lieu_depart,
            'ristourne_regionale' => $request->ristourne_regionale ?? 0,
            'ristourne_communale' => $request->ristourne_communale ?? 0,
            'quantite_a_livrer' => $request->quantite_a_livrer,
            'quantite_restante' => $request->quantite_a_livrer,
            'est_partielle' => $estPartielle,
        ]);

        // Mettre à jour statut PV
        if ($pvReception->statut === 'paye') {
            $pvReception->update(['statut' => 'en_attente_livraison']);
        } elseif ($pvReception->statut === 'partiellement_livre') {
            $pvReception->update(['statut' => 'en_attente_livraison']);
        }

        // Charger les relations pour la réponse
        $ficheLivraison->load([
            'pvReception.fournisseur', 
            'pvReception.provenance',
            'livreur',
            'destinateur'
        ]);

        $message = 'Fiche de livraison créée avec succès';
        if ($estPartielle) {
            $message .= ' (livraison partielle)';
        }

        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $ficheLivraison
        ], 201);

    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Erreur lors de la création de la fiche de livraison: ' . $e->getMessage()
        ], 500);
    }
}


    public function livrer($id)
    {
        try {
            $ficheLivraison = FicheLivraison::with(['pvReception'])->find($id);
            
            if (!$ficheLivraison) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Fiche de livraison non trouvée'
                ], 404);
            }

            // Vérifier si déjà livrée (via existence de livraison)
            if ($ficheLivraison->livraison) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Fiche déjà livrée'
                ], 400);
            }

            // Vérifier que le PV réception existe
            if (!$ficheLivraison->pvReception) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'PV de réception associé non trouvé'
                ], 404);
            }

            $pvReception = $ficheLivraison->pvReception;

            // Vérifier que le statut du PV permet la livraison
            $statutsAutorises = ['en_attente_livraison', 'partiellement_livre'];
            if (!in_array($pvReception->statut, $statutsAutorises)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Le PV n\'est pas en attente de livraison. Statut actuel: ' . $pvReception->statut
                ], 422);
            }

            // Vérifier que la quantité à livrer ne dépasse pas le stock restant
            if ($ficheLivraison->quantite_a_livrer > $pvReception->quantite_restante) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Quantité à livrer supérieure au stock disponible. Stock restant: ' . $pvReception->quantite_restante . ' kg'
                ], 422);
            }

            // Démarrer une transaction pour assurer la cohérence des données
            DB::beginTransaction();

            try {
                // 1. Créer l'enregistrement de livraison
                $livraison = $ficheLivraison->livraison()->create([
                    'date_confirmation_livraison' => now()
                ]);

                // 2. Mettre à jour la quantité restante de la fiche de livraison (0 = complètement livrée)
                $ficheLivraison->update([
                    'quantite_restante' => 0
                ]);

                // 3. Déduire la quantité livrée du stock du PV réception
                $quantiteLivree = $ficheLivraison->quantite_a_livrer;
                $nouveauStockRestant = max(0, $pvReception->quantite_restante - $quantiteLivree);
                
                $pvReception->update([
                    'quantite_restante' => $nouveauStockRestant
                ]);

                // 4. Mettre à jour le statut du PV réception selon le nouveau stock
                if ($nouveauStockRestant <= 0) {
                    // Tout le stock a été livré
                    $pvReception->update(['statut' => 'livree']);
                } else {
                    // Il reste du stock à livrer
                    $pvReception->update(['statut' => 'partiellement_livre']);
                }

                // Valider la transaction
                DB::commit();

                // Recharger les relations pour la réponse
                $ficheLivraison->load(['pvReception.fournisseur', 'pvReception.provenance', 'livraison']);
                $pvReception->refresh();

                // Préparer le message de succès
                $message = 'Livraison confirmée avec succès';
                if ($nouveauStockRestant > 0) {
                    $message .= ' (livraison partielle - stock restant: ' . $nouveauStockRestant . ' kg)';
                } else {
                    $message .= ' (livraison complète)';
                }

                return response()->json([
                    'status' => 'success',
                    'message' => $message,
                    'data' => [
                        'fiche_livraison' => $ficheLivraison,
                        'livraison' => $livraison,
                        'pv_reception' => [
                            'id' => $pvReception->id,
                            'numero_doc' => $pvReception->numero_doc,
                            'statut' => $pvReception->statut,
                            'quantite_totale' => $pvReception->quantite_totale,
                            'quantite_restante' => $pvReception->quantite_restante,
                            'quantite_livree' => $pvReception->quantite_totale - $pvReception->quantite_restante,
                            'pourcentage_livree' => $pvReception->quantite_totale > 0 ? 
                                (($pvReception->quantite_totale - $pvReception->quantite_restante) / $pvReception->quantite_totale) * 100 : 0
                        ]
                    ]
                ], 200);

            } catch (\Exception $e) {
                // Annuler la transaction en cas d'erreur
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la confirmation de livraison: ' . $e->getMessage()
            ], 500);
        }
    }

    // NOUVELLE MÉTHODE POUR LIVRAISON PARTIELLE AVEC QUANTITÉ SPÉCIFIQUE
    public function livrerPartielle(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'quantite_livree' => 'required|numeric|min:0.01'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            $ficheLivraison = FicheLivraison::with(['pvReception'])->find($id);
            
            if (!$ficheLivraison) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Fiche de livraison non trouvée'
                ], 404);
            }

            // VÉRIFIER QUE LA QUANTITÉ LIVRÉE NE DÉPASSE PAS LA QUANTITÉ PRÉVUE
            if ($request->quantite_livree > $ficheLivraison->quantite_a_livrer) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Quantité livrée supérieure à la quantité prévue'
                ], 422);
            }

            // VÉRIFIER QUE LA QUANTITÉ LIVRÉE NE DÉPASSE PAS LE STOCK DISPONIBLE DU PV
            if ($request->quantite_livree > $ficheLivraison->pvReception->quantite_restante) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Quantité livrée supérieure au stock disponible du PV'
                ], 422);
            }

            // Démarrer une transaction
            DB::beginTransaction();

            try {
                // 1. Créer l'enregistrement de livraison
                $livraison = $ficheLivraison->livraison()->create([
                    'date_confirmation_livraison' => now()
                ]);

                // 2. Mettre à jour la quantité restante de la fiche
                $nouvelleQuantiteRestanteFiche = max(0, $ficheLivraison->quantite_a_livrer - $request->quantite_livree);
                $ficheLivraison->update([
                    'quantite_restante' => $nouvelleQuantiteRestanteFiche
                ]);

                // 3. Déduire la quantité livrée du stock du PV
                $pvReception = $ficheLivraison->pvReception;
                $nouveauStockRestant = max(0, $pvReception->quantite_restante - $request->quantite_livree);
                $pvReception->update([
                    'quantite_restante' => $nouveauStockRestant
                ]);

                // 4. Mettre à jour le statut du PV selon le nouveau stock
                if ($nouveauStockRestant <= 0) {
                    $pvReception->update(['statut' => 'livree']);
                } else {
                    $pvReception->update(['statut' => 'partiellement_livre']);
                }

                DB::commit();

                // Recharger les relations
                $ficheLivraison->load(['pvReception.fournisseur', 'pvReception.provenance', 'livraison']);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Livraison partielle confirmée avec succès',
                    'data' => [
                        'fiche_livraison' => $ficheLivraison,
                        'livraison' => $livraison,
                        'quantite_livree' => $request->quantite_livree,
                        'statut_pv' => $pvReception->statut,
                        'quantite_restante_pv' => $pvReception->quantite_restante,
                        'quantite_restante_fiche' => $ficheLivraison->quantite_restante
                    ]
                ], 200);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la livraison partielle: ' . $e->getMessage()
            ], 500);
        }
    }

    // GET /fiche-livraisons (liste, avec filtres optionnels)
   public function index(Request $request)
{
    try {
        $query = FicheLivraison::with([
            'pvReception.fournisseur', 
            'livreur',
            'destinateur',
            'livraison'
        ])->orderBy('created_at', 'desc');

        if ($request->pv_reception_id) {
            $query->where('pv_reception_id', $request->pv_reception_id);
        }

        $ficheLivraisons = $query->get();

        return response()->json([
            'status' => 'success',
            'data' => $ficheLivraisons
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Erreur lors de la récupération des fiches de livraison'
        ], 500);
    }
}

    // GET /fiche-livraisons/{id}
   public function show($id)
{
    try {
        $ficheLivraison = FicheLivraison::with([
            'pvReception.fournisseur', 
            'livreur',
            'destinateur',
            'livraison'
        ])->find($id);

        if (!$ficheLivraison) {
            return response()->json([
                'status' => 'error',
                'message' => 'Fiche de livraison non trouvée'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $ficheLivraison
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Erreur lors de la récupération de la fiche de livraison'
        ], 500);
    }
}
}