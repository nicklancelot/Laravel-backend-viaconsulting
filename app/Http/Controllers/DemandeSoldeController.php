<?php

namespace App\Http\Controllers;

use App\Models\Caissier;
use Illuminate\Http\Request;
use App\Models\DemandeSolde;
use App\Models\SoldeUser;
use App\Models\Transfert;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DemandeSoldeController extends Controller
{
    public function index()
    {
        try {
            $demandes = DemandeSolde::with([
                'utilisateur:id,nom,prenom,numero,role,localisation_id',
                'admin:id,nom,prenom'
            ])
            ->orderBy('created_at', 'desc')
            ->get();

            return response()->json([
                'success' => true,
                'data' => $demandes,
                'message' => 'Demandes de solde récupérées avec succès'
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur récupération demandes solde: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des demandes de solde',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        DB::beginTransaction();
        
        try {
            $request->validate([
                'utilisateur_id' => 'required|exists:utilisateurs,id',
                'montant_demande' => 'required|numeric|min:0.01',
                'raison' => 'required|string|max:500'
            ]);

         
            $utilisateur = Utilisateur::find($request->utilisateur_id);
            if (!$utilisateur) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non trouvé'
                ], 404);
            }

            $demande = DemandeSolde::create([
                'utilisateur_id' => $request->utilisateur_id,
                'montant_demande' => $request->montant_demande,
                'raison' => $request->raison,
                'statut' => 'en_attente',
                'date' => now()
            ]);

            
            $demande->load(['utilisateur:id,nom,prenom,numero,role']);

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $demande,
                'message' => 'Demande de solde créée avec succès'
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
            Log::error('Erreur création demande solde: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de la demande de solde',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $demande = DemandeSolde::with([
                'utilisateur:id,nom,prenom,numero,role,localisation_id',
                'admin:id,nom,prenom'
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $demande,
                'message' => 'Demande de solde récupérée avec succès'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Demande de solde non trouvée'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Erreur récupération demande solde: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la demande de solde',
                'error' => $e->getMessage()
            ], 500);
        }
    }

  public function updateStatut(Request $request, $id)
{
    DB::beginTransaction();
    
    try {
        $request->validate([
            'statut' => 'required|in:approuvee,rejetee',
            'admin_id' => 'required|exists:utilisateurs,id',
            'commentaire_admin' => 'nullable|string|max:500'
        ]);

        // Vérifier que l'admin a le rôle admin
        $admin = Utilisateur::find($request->admin_id);
        if (!$admin || $admin->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Seuls les administrateurs peuvent approuver ou rejeter les demandes'
            ], 403);
        }

        $demande = DemandeSolde::findOrFail($id);
        
        // Vérifier que la demande n'est pas déjà traitée
        if ($demande->statut !== 'en_attente') {
            return response()->json([
                'success' => false,
                'message' => 'Cette demande a déjà été traitée'
            ], 400);
        }

        // Si la demande est approuvée, vérifier le solde et effectuer le transfert
        if ($request->statut === 'approuvee') {
            $derniereTransaction = Caissier::latest()->first();
            $soldeActuel = $derniereTransaction ? $derniereTransaction->solde : 0;

            if ($soldeActuel < $demande->montant_demande) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solde insuffisant pour approuver cette demande. Solde disponible: ' . number_format($soldeActuel, 0, ',', ' ') . ' Ar'
                ], 400);
            }

            // Créer un transfert automatique
            $transfert = Transfert::create([
                'admin_id' => $request->admin_id,
                'destinataire_id' => $demande->utilisateur_id,
                'montant' => $demande->montant_demande,
                'type_transfert' => 'virement',
                'reference' => 'DEMANDE_' . $demande->id,
                'raison' => $demande->raison
            ]);

            $nouveauSolde = $soldeActuel - $demande->montant_demande;

            Caissier::create([
                'utilisateur_id' => $request->admin_id,
                'solde' => $nouveauSolde,
                'date' => now(),
                'montant' => $demande->montant_demande,
                'type' => 'depense',
                'methode' => 'virement',
                'raison' => "Approbation demande solde #{$demande->id} - " . $demande->raison,
                'reference' => 'DEMANDE_' . $demande->id
            ]);

            // METTRE À JOUR SOLDEUSER - NOUVEAU CODE
            $soldeUser = SoldeUser::where('utilisateur_id', $demande->utilisateur_id)->first();
            
            if ($soldeUser) {
                // Si le solde existe, l'incrémenter
                $soldeUser->increment('solde', $demande->montant_demande);
            } else {
            
                SoldeUser::create([
                    'utilisateur_id' => $demande->utilisateur_id,
                    'solde' => $demande->montant_demande
                ]);
            }
        }

        // Mettre à jour le statut de la demande
        $demande->update([
            'statut' => $request->statut,
            'admin_id' => $request->admin_id,
            'commentaire_admin' => $request->commentaire_admin
        ]);

        // Recharger les relations
        $demande->load(['utilisateur:id,nom,prenom,numero,role', 'admin:id,nom,prenom']);

        DB::commit();

        $message = $request->statut === 'approuvee' 
            ? 'Demande approuvée et transfert effectué avec succès'
            : 'Demande rejetée avec succès';

        return response()->json([
            'success' => true,
            'data' => $demande,
            'message' => $message,
            'solde_actuel' => $request->statut === 'approuvee' ? $nouveauSolde : null
        ]);

    } catch (\Illuminate\Validation\ValidationException $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Erreur de validation',
            'errors' => $e->errors()
        ], 422);
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Demande de solde non trouvée'
        ], 404);
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Erreur mise à jour statut demande solde: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la mise à jour du statut',
            'error' => $e->getMessage()
        ], 500);
    }
}
    public function mesDemandes($utilisateur_id)
    {
        try {
            $demandes = DemandeSolde::with([
                'utilisateur:id,nom,prenom,numero,role',
                'admin:id,nom,prenom'
            ])
            ->where('utilisateur_id', $utilisateur_id)
            ->orderBy('created_at', 'desc')
            ->get();

            return response()->json([
                'success' => true,
                'data' => $demandes,
                'message' => 'Mes demandes de solde récupérées avec succès'
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur récupération mes demandes solde: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de vos demandes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function demandesEnAttente()
    {
        try {
            $demandes = DemandeSolde::with([
                'utilisateur:id,nom,prenom,numero,role,localisation_id'
            ])
            ->enAttente()
            ->orderBy('created_at', 'desc')
            ->get();

            return response()->json([
                'success' => true,
                'data' => $demandes,
                'message' => 'Demandes en attente récupérées avec succès'
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur récupération demandes en attente: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des demandes en attente',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();
        
        try {
            $demande = DemandeSolde::findOrFail($id);
            
            // Empêcher la suppression si la demande est déjà traitée
            if ($demande->statut !== 'en_attente') {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de supprimer une demande déjà traitée'
                ], 400);
            }

            $demande->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Demande de solde supprimée avec succès'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Demande de solde non trouvée'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur suppression demande solde: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la demande de solde',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function statistiques()
    {
        try {
            $totalDemandes = DemandeSolde::count();
            $demandesEnAttente = DemandeSolde::enAttente()->count();
            $demandesApprouvees = DemandeSolde::approuvees()->count();
            $demandesRejetees = DemandeSolde::rejetees()->count();
            
            $montantTotalDemande = DemandeSolde::sum('montant_demande');
            $montantApprouve = DemandeSolde::approuvees()->sum('montant_demande');

            return response()->json([
                'success' => true,
                'data' => [
                    'total_demandes' => $totalDemandes,
                    'demandes_en_attente' => $demandesEnAttente,
                    'demandes_approuvees' => $demandesApprouvees,
                    'demandes_rejetees' => $demandesRejetees,
                    'montant_total_demande' => (float) $montantTotalDemande,
                    'montant_approuve' => (float) $montantApprouve
                ],
                'message' => 'Statistiques récupérées avec succès'
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur récupération statistiques demandes: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}