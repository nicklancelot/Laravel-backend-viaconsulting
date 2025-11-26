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
                'delaiHeures' => 'nullable|integer|min:1', // Maintenant en minutes
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

            // SUPPRIMER LA VÉRIFICATION DU SOLDE CAISSE
            // NE PAS DÉCRÉMENTER LA CAISSE

            // Générer une référence unique
            $reference = 'PAY_' . now()->format('YmdHis') . '_' . rand(1000, 9999);

            // Créer le paiement en avance avec statut "en_attente"
            $paiement = PayementAvance::create([
                'fournisseur_id' => $request->fournisseur_id,
                'montant' => $request->montant,
                'date' => now(),
                'statut' => 'en_attente', // Maintenant en attente de confirmation
                'methode' => $request->methode,
                'reference' => $reference,
                'type' => $request->type,
                'description' => $request->description,
                'montantDu' => $request->montantDu,
                'montantAvance' => $request->montantAvance,
                'delaiHeures' => $request->delaiHeures, // Stocké en minutes
                'raison' => $request->raison
            ]);

            // DÉCRÉMENTER UNIQUEMENT LE SOLDE UTILISATEUR
            if ($soldeUser) {
                $soldeUser->decrement('solde', $request->montant);
            }

            // NE PAS CRÉER DE TRANSACTION CAISSIER
            // LA CAISSE GLOBALE N'EST PAS AFFECTÉE

            // Charger les relations pour la réponse
            $paiement->load(['fournisseur:id,nom,prenom,contact']);

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $paiement,
                'message' => 'Paiement en avance créé avec succès - En attente de confirmation',
                'solde_utilisateur_apres' => $soldeUser ? $soldeUser->solde : 0,
                'delai_minutes' => $request->delaiHeures
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

            // Ajouter les informations de retard
            $paiement->est_en_retard = $paiement->estEnRetard();
            $paiement->temps_restant = $paiement->tempsRestant();

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

            // Vérifier que le paiement est en attente
            if ($paiement->statut !== 'en_attente') {
                return response()->json([
                    'success' => false,
                    'message' => 'Seuls les paiements en attente peuvent être confirmés'
                ], 400);
            }

            // Vérifier si le délai est expiré
            if ($paiement->estEnRetard()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de confirmer - Le délai est expiré',
                    'est_en_retard' => true
                ], 400);
            }

            // Confirmer le paiement
            $paiement->confirmer();

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $paiement,
                'message' => 'Paiement confirmé avec succès'
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

            // Vérifier que le paiement peut être annulé
            if (!in_array($paiement->statut, ['en_attente', 'payé'])) {
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

            // NE PAS REMBOURSER LA CAISSE

            // Marquer le paiement comme annulé
            $paiement->update([
                'statut' => 'annulé',
                'raison' => $request->raison_annulation
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $paiement,
                'message' => 'Paiement annulé et montant remboursé avec succès',
                'solde_utilisateur_apres' => $soldeUser ? $soldeUser->solde : 0
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
}