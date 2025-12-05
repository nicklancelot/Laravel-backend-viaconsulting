<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PayementAvance;
use App\Models\MatierePremiere\Fournisseur;
use App\Models\Caissier;
use App\Models\Utilisateur;
use App\Models\SoldeUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use App\Models\TestHuille\FicheReception; 
use App\Models\MatierePremiere\PVReception; 

class PayementEnAvanceController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $paiements = PayementAvance::with(['fournisseur:id,nom,prenom,contact'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $paiements,
                'message' => 'Paiements en avance récupérés avec succès'
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur récupération paiements avance: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des paiements',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
{
    DB::beginTransaction();
    
    try {
        $request->validate([
            'fournisseur_id' => 'required|exists:fournisseurs,id',
            'montant' => 'required|numeric|min:0.01',
            'methode' => 'required|in:espèces,virement,chèque',
            'type' => 'required|in:avance,paiement_complet,acompte,règlement',
            'description' => 'nullable|string|max:500',
            'montantDu' => 'nullable|numeric|min:0',
            'montantAvance' => 'nullable|numeric|min:0',
            'delaiHeures' => 'nullable|integer|min:1',
            'raison' => 'nullable|string|max:255'
        ]);

        $user = Auth::user();
        
        // Vérifier que l'utilisateur est un admin
        if ($user->role !== 'collecteur') {
            return response()->json([
                'success' => false,
                'message' => 'Seuls les collecteur peuvent effectuer des paiements en avance'
            ], 403);
        }

        // VÉRIFICATION : Empêcher si le fournisseur a déjà des paiements non réglés
        $paiementsNonRegles = PayementAvance::where('fournisseur_id', $request->fournisseur_id)
            ->whereIn('statut', ['en_attente', 'arrivé'])
            ->exists();

        if ($paiementsNonRegles) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de créer un nouveau paiement : Ce fournisseur a déjà des paiements en avance non réglés'
            ], 400);
        }

        // VÉRIFIER UNIQUEMENT LE SOLDE DE L'UTILISATEUR
        $soldeUser = SoldeUser::where('utilisateur_id', $user->id)->first();
        $soldeActuel = $soldeUser ? $soldeUser->solde : 0;

        if ($soldeActuel < $request->montant) {
            return response()->json([
                'success' => false,
                'message' => 'Solde utilisateur insuffisant. Solde disponible: ' . number_format($soldeActuel, 0, ',', ' ') . ' Ar - Montant requis: ' . number_format($request->montant, 0, ',', ' ') . ' Ar',
                'solde_actuel' => $soldeActuel,
                'montant_requis' => $request->montant
            ], 400);
        }

        // Générer une référence unique
        $reference = 'PAY_' . now()->format('YmdHis') . '_' . rand(1000, 9999);

        // Créer le paiement en avance avec statut "en_attente"
        $paiement = PayementAvance::create([
            'fournisseur_id' => $request->fournisseur_id,
            'montant' => $request->montant,
            'date' => now(),
            'statut' => 'en_attente',
            'methode' => $request->methode,
            'reference' => $reference,
            'type' => $request->type,
            'description' => $request->description,
            'montantDu' => $request->montantDu,
            'montantAvance' => $request->montantAvance,
            'delaiHeures' => $request->delaiHeures,
            'raison' => $request->raison,
            'montant_utilise' => 0, 
            'montant_restant' => $request->montant 
        ]);

        // DÉCRÉMENTER UNIQUEMENT LE SOLDE UTILISATEUR
        if ($soldeUser) {
            $soldeUser->decrement('solde', $request->montant);
        }

        // Charger les relations pour la réponse
        $paiement->load(['fournisseur:id,nom,prenom,contact']);

        DB::commit();

        return response()->json([
            'success' => true,
            'data' => $paiement,
            'message' => 'Paiement en avance créé avec succès - En attente de confirmation',
            'solde_utilisateur_apres' => $soldeUser ? $soldeUser->solde : 0,
            'delai_heures' => $request->delaiHeures
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
        Log::error('Erreur création paiement avance: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la création du paiement en avance',
            'error' => $e->getMessage()
        ], 500);
    }
}

public function show($id): JsonResponse
{
    try {
        $paiement = PayementAvance::with(['fournisseur:id,nom,prenom,contact,adresse'])
            ->findOrFail($id);
        return response()->json([
            'success' => true,
            'data' => $paiement,
            'message' => 'Paiement récupéré avec succès'
        ]);
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Paiement non trouvé'
        ], 404);
    } catch (\Exception $e) {
        Log::error('Erreur récupération paiement: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la récupération du paiement',
            'error' => $e->getMessage()
        ], 500);
    }
}

    /**
     * Confirmer manuellement un paiement
     */
    public function confirmerPaiement($id): JsonResponse
{
    DB::beginTransaction();
    
    try {
        $user = Auth::user();
        $paiement = PayementAvance::findOrFail($id);

        // Vérifier que l'utilisateur est admin
        if ($user->role !== 'collecteur') {
            return response()->json([
                'success' => false,
                'message' => 'Seuls les collecteur peuvent confirmer les paiements'
            ], 403);
        }

        // Vérifier que le paiement est en attente (même en retard)
        if ($paiement->statut !== 'en_attente') {
            return response()->json([
                'success' => false,
                'message' => 'Seuls les paiements en attente peuvent être confirmés'
            ], 400);
        }

        // Confirmer le paiement avec le nouveau statut "arrivé"
        $paiement->statut = 'arrivé';
        $paiement->save();

        DB::commit();

        $message = 'Paiement confirmé avec succès - Statut: Arrivé';
        if ($paiement->estEnRetard()) {
            $message .= ' (⚠️ Paiement confirmé malgré le retard)';
        }

        return response()->json([
            'success' => true,
            'data' => $paiement,
            'message' => $message,
            'est_en_retard' => $paiement->estEnRetard() 
        ]);

    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Paiement non trouvé'
        ], 404);
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Erreur confirmation paiement: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la confirmation du paiement',
            'error' => $e->getMessage()
        ], 500);
    }
}

    public function getPaiementsEnRetard(): JsonResponse
    {
        try {
            $paiementsRetard = PayementAvance::with(['fournisseur:id,nom,prenom,contact'])
                ->enRetard()
                ->orderBy('date', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $paiementsRetard,
                'message' => 'Paiements en retard récupérés avec succès',
                'nombre_retards' => $paiementsRetard->count()
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur récupération paiements retard: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des paiements en retard',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    public function annulerPaiement(Request $request, $id): JsonResponse
{
    DB::beginTransaction();
    
    try {
        $request->validate([
            'raison_annulation' => 'required|string|max:500'
        ]);

        $user = Auth::user();
        $paiement = PayementAvance::findOrFail($id);

        // Vérifier que le paiement peut être annulé (même en retard)
        if (!in_array($paiement->statut, ['en_attente', 'arrivé'])) {
            return response()->json([
                'success' => false,
                'message' => 'Ce paiement ne peut pas être annulé'
            ], 400);
        }

        // REMBOURSER UNIQUEMENT LE SOLDE UTILISATEUR
        $soldeUser = SoldeUser::where('utilisateur_id', $user->id)->first();
        if ($soldeUser) {
            $soldeUser->increment('solde', $paiement->montant);
        }

        // Marquer le paiement comme annulé
        $paiement->update([
            'statut' => 'annulé',
            'raison' => $request->raison_annulation
        ]);

        DB::commit();

        $message = 'Paiement annulé et montant remboursé avec succès';
        if ($paiement->estEnRetard()) {
            $message .= ' (⚠️ Paiement annulé malgré le retard)';
        }

        return response()->json([
            'success' => true,
            'data' => $paiement,
            'message' => $message,
            'solde_utilisateur_apres' => $soldeUser ? $soldeUser->solde : 0,
            'est_en_retard' => $paiement->estEnRetard() 
        ]);

    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Paiement non trouvé'
        ], 404);
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Erreur annulation paiement: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de l\'annulation du paiement',
            'error' => $e->getMessage()
        ], 500);
    }
}


    public function statistiques(): JsonResponse
    {
        try {
            $totalPaiements = PayementAvance::count();
            $totalMontant = PayementAvance::where('statut', 'payé')->sum('montant');
            $paiementsEnRetard = PayementAvance::enRetard()->count();
            $paiementsAnnules = PayementAvance::where('statut', 'annulé')->count();
            $paiementsEnAttente = PayementAvance::where('statut', 'en_attente')->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'total_paiements' => $totalPaiements,
                    'total_montant' => (float) $totalMontant,
                    'paiements_en_retard' => $paiementsEnRetard,
                    'paiements_annules' => $paiementsAnnules,
                    'paiements_en_attente' => $paiementsEnAttente
                ],
                'message' => 'Statistiques récupérées avec succès'
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur récupération statistiques paiements: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function utiliserPaiement(Request $request, $id): JsonResponse
    {
        DB::beginTransaction();
        
        try {
            $request->validate([
                'montant_utilise' => 'required|numeric|min:0.01',
                'pv_reception_id' => 'nullable|exists:p_v_receptions,id',
                'fiche_reception_id' => 'nullable|exists:fiche_receptions,id' // Ajouté
            ]);

            $user = Auth::user();
            $paiement = PayementAvance::findOrFail($id);

            // Vérifier que le paiement peut être utilisé
            if ($paiement->statut !== 'arrivé') {
                return response()->json([
                    'success' => false,
                    'message' => 'Seuls les paiements arrivés peuvent être utilisés'
                ], 400);
            }

            // Vérifier qu'au moins une relation est fournie
            if (!$request->has('pv_reception_id') && !$request->has('fiche_reception_id')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous devez spécifier un PV de réception ou une Fiche de réception'
                ], 400);
            }

            // Vérifier que le montant à utiliser est disponible
            if ($request->montant_utilise > $paiement->montant_restant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Montant insuffisant dans ce paiement. Montant restant: ' . number_format($paiement->montant_restant, 0, ',', ' ') . ' Ar'
                ], 400);
            }

            // Calculer le nouveau montant utilisé et restant
            $nouveauMontantUtilise = $paiement->montant_utilise + $request->montant_utilise;
            $nouveauMontantRestant = $paiement->montant - $nouveauMontantUtilise;

            // Préparer les données de mise à jour
            $updateData = [
                'montant_utilise' => $nouveauMontantUtilise,
                'montant_restant' => $nouveauMontantRestant,
                'date_utilisation' => now(),
                'statut' => $nouveauMontantRestant == 0 ? 'utilise' : 'arrivé'
            ];

            // Ajouter l'ID du document approprié
            if ($request->pv_reception_id) {
                $updateData['pv_reception_id'] = $request->pv_reception_id;
            }
            
            if ($request->fiche_reception_id) {
                $updateData['fiche_reception_id'] = $request->fiche_reception_id;
            }

            // Mettre à jour le paiement
            $paiement->update($updateData);

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $paiement->load(['fournisseur:id,nom,prenom,contact']),
                'message' => $paiement->statut == 'utilise' 
                    ? 'Paiement entièrement utilisé' 
                    : 'Paiement partiellement utilisé - Reste disponible: ' . number_format($nouveauMontantRestant, 0, ',', ' ') . ' Ar',
                'montant_utilise' => $request->montant_utilise,
                'montant_restant' => $nouveauMontantRestant,
                'statut' => $paiement->statut
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Paiement non trouvé'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur utilisation paiement: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'utilisation du paiement',
                'error' => $e->getMessage()
            ], 500);
        }
    }



public function getPaiementsUtilisables($fournisseur_id = null): JsonResponse
{
    try {
        $query = PayementAvance::with(['fournisseur:id,nom,prenom,contact'])
            ->where('statut', 'arrivé')
            ->where('montant_restant', '>', 0);

        if ($fournisseur_id) {
            $query->where('fournisseur_id', $fournisseur_id);
        }

        $paiements = $query->orderBy('date', 'asc')->get();

        return response()->json([
            'success' => true,
            'data' => $paiements,
            'message' => 'Paiements utilisables récupérés avec succès',
            'total_montant_disponible' => $paiements->sum('montant_restant')
        ]);
    } catch (\Exception $e) {
        Log::error('Erreur récupération paiements utilisables: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la récupération des paiements utilisables',
            'error' => $e->getMessage()
        ], 500);
    }
}
// Dans FicheReceptionController, ajouter cette méthode après la méthode calculerPoidsNet

public function utiliserPaiementPourFiche(Request $request, $id)
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

        $request->validate([
            'paiement_id' => 'required|exists:payement_avances,id',
            'montant_utilise' => 'required|numeric|min:0.01'
        ]);

        $paiement = PayementAvance::find($request->paiement_id);

        // Vérifier que le paiement est disponible
        if (!$paiement->estDisponible()) {
            return response()->json([
                'success' => false,
                'message' => 'Ce paiement n\'est pas disponible pour utilisation'
            ], 400);
        }

        // Vérifier que le paiement appartient au bon fournisseur
        if ($paiement->fournisseur_id != $fiche->fournisseur_id) {
            return response()->json([
                'success' => false,
                'message' => 'Ce paiement ne correspond pas au fournisseur de la fiche'
            ], 400);
        }

        // Utiliser le paiement
        $paiement->marquerCommeUtilise(null, $fiche->id);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Paiement utilisé pour la fiche de réception',
            'data' => [
                'fiche' => $fiche,
                'paiement' => $paiement
            ]
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de l\'utilisation du paiement',
            'error' => $e->getMessage()
        ], 500);
    }
}
}