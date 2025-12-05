<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Caissier;
use Illuminate\Support\Facades\DB;
use App\Models\Utilisateur;

class CaissierController extends Controller
{
    public function index()
    {
        try {
            $transactions = Caissier::with(['utilisateur:id,nom,prenom,numero,role'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $transactions,
                'message' => 'Transactions récupérées avec succès'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des transactions',
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
                'montant' => 'required|numeric|min:0.01',
                'type' => 'required|in:revenu,depense',
                'methode' => 'required|string|max:50',
                'raison' => 'required|string|max:100',
                'reference' => 'nullable|string|max:50'
            ]);

            //  Vérifier que l'utilisateur est un admin
            $utilisateur = Utilisateur::find($request->utilisateur_id);
            if (!$utilisateur || $utilisateur->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Seuls les administrateurs peuvent effectuer des transactions'
                ], 403);
            }

            // Calcul du nouveau solde basé sur le dernier solde disponible
            $derniereTransaction = Caissier::latest()->first();
            $ancienSolde = $derniereTransaction ? $derniereTransaction->solde : 0;

            $nouveauSolde = $request->type === 'revenu' 
                ? $ancienSolde + $request->montant
                : $ancienSolde - $request->montant;

            // Vérifier que le solde ne devient pas négatif
            if ($nouveauSolde < 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solde insuffisant pour effectuer cette transaction'
                ], 400);
            }

            $transaction = Caissier::create([
                'utilisateur_id' => $request->utilisateur_id,
                'solde' => $nouveauSolde,
                'date' => now(),
                'montant' => $request->montant,
                'type' => $request->type,
                'methode' => $request->methode,
                'raison' => $request->raison,
                'reference' => $request->reference ?? 'REF_' . now()->timestamp
            ]);

            // Charger la relation utilisateur pour la réponse
            $transaction->load('utilisateur:id,nom,prenom,numero,role');

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $transaction,
                'message' => 'Transaction créée avec succès',
                'solde_actuel' => $nouveauSolde
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
                'message' => 'Erreur lors de la création de la transaction',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $transaction = Caissier::with(['utilisateur:id,nom,prenom,numero,role'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $transaction,
                'message' => 'Transaction récupérée avec succès'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction non trouvée'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la transaction',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, string $id)
    {
        DB::beginTransaction();
        
        try {
            $transaction = Caissier::findOrFail($id);
            
            $request->validate([
                'montant' => 'numeric|min:0.01',
                'type' => 'in:revenu,depense',
                'methode' => 'string|max:50',
                'raison' => 'nullable|string|max:100',
                'reference' => 'nullable|string|max:50'
            ]);

            // MODIFICATION : Vérifier que l'utilisateur est un admin
            if ($request->has('utilisateur_id')) {
                $utilisateur = Utilisateur::find($request->utilisateur_id);
                if (!$utilisateur || $utilisateur->role !== 'admin') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Seuls les administrateurs peuvent modifier des transactions'
                    ], 403);
                }
            }

            // Si le montant ou le type change, recalculer tous les soldes suivants
            if ($request->has('montant') || $request->has('type')) {
                $this->recalculerSoldes($transaction);
            }

            $transaction->update($request->all());
            $transaction->load('utilisateur:id,nom,prenom,numero,role');

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $transaction,
                'message' => 'Transaction mise à jour avec succès'
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
                'message' => 'Transaction non trouvée'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour de la transaction',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        DB::beginTransaction();
        
        try {
            $transaction = Caissier::findOrFail($id);
            
            $transaction->delete();
            
            // calcule  soldes apres suppression
            $this->recalculerTousLesSoldes();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Transaction supprimée avec succès'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Transaction non trouvée'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la transaction',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Fonction pour ajuster le solde
    public function ajusterSolde(Request $request, $utilisateur_id)
    {
        DB::beginTransaction();
        
        try {
            $request->validate([
                'nouveau_solde' => 'required|numeric|min:0',
                'raison' => 'required|string|max:100'
            ]);

            // MODIFICATION : Vérifier que l'utilisateur est un admin
            $utilisateur = Utilisateur::find($utilisateur_id);
            if (!$utilisateur || $utilisateur->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Seuls les administrateurs peuvent ajuster le solde'
                ], 403);
            }

            $ancienSolde = Caissier::latest()->value('solde') ?? 0;

            $difference = $request->nouveau_solde - $ancienSolde;
            $type = $difference >= 0 ? 'revenu' : 'depense';

            $ajustement = Caissier::create([
                'utilisateur_id' => $utilisateur_id,
                'solde' => $request->nouveau_solde,
                'date' => now(),
                'montant' => abs($difference),
                'type' => $type,
                'methode' => 'ajustement',
                'raison' => $request->raison,
                'reference' => 'AJUST_' . now()->timestamp
            ]);

            $ajustement->load('utilisateur:id,nom,prenom,numero,role');

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $ajustement,
                'message' => 'Solde ajusté avec succès',
                'solde_actuel' => $request->nouveau_solde
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
                'message' => 'Erreur lors de l\'ajustement du solde',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Fonction pour retirer du solde
    public function retirerSolde(Request $request, $utilisateur_id)
    {
        DB::beginTransaction();
        
        try {
            $request->validate([
                'montant' => 'required|numeric|min:0.01',
                'raison' => 'required|string|max:100',
                'methode' => 'required|string|max:50',
                'reference' => 'nullable|string|max:50'
            ]);

            // érifier que l'utilisateur est un admin
            $utilisateur = Utilisateur::find($utilisateur_id);
            if (!$utilisateur || $utilisateur->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Seuls les administrateurs peuvent retirer du solde'
                ], 403);
            }

            $ancienSolde = Caissier::latest()->value('solde') ?? 0;

            if ($ancienSolde < $request->montant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solde insuffisant pour effectuer ce retrait'
                ], 400);
            }

            $retrait = Caissier::create([
                'utilisateur_id' => $utilisateur_id,
                'solde' => $ancienSolde - $request->montant,
                'date' => now(),
                'montant' => $request->montant,
                'type' => 'depense',
                'methode' => $request->methode,
                'raison' => $request->raison,
                'reference' => $request->reference ?? 'RETRAIT_' . now()->timestamp
            ]);

            $retrait->load('utilisateur:id,nom,prenom,numero,role');

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $retrait,
                'message' => 'Retrait effectué avec succès',
                'solde_actuel' => $ancienSolde - $request->montant
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
                'message' => 'Erreur lors du retrait',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    private function recalculerSoldes(Caissier $transactionModifiee)
    {
        $transactions = Caissier::where('created_at', '>=', $transactionModifiee->created_at)
            ->orderBy('created_at')
            ->get();

        $soldePrecedent = Caissier::where('created_at', '<', $transactionModifiee->created_at)
            ->latest()
            ->value('solde') ?? 0;

        foreach ($transactions as $transaction) {
            if ($transaction->id === $transactionModifiee->id) {
                $soldePrecedent = $transaction->type === 'revenu' 
                    ? $soldePrecedent + $transaction->montant
                    : $soldePrecedent - $transaction->montant;
            } else {
                $soldePrecedent = $transaction->type === 'revenu' 
                    ? $soldePrecedent + $transaction->montant
                    : $soldePrecedent - $transaction->montant;
            }
            
            $transaction->update(['solde' => $soldePrecedent]);
        }
    }

    private function recalculerTousLesSoldes()
    {
        $transactions = Caissier::orderBy('created_at')->get();

        $solde = 0;
        
        foreach ($transactions as $transaction) {
            $solde = $transaction->type === 'revenu' 
                ? $solde + $transaction->montant
                : $solde - $transaction->montant;
            
            $transaction->update(['solde' => $solde]);
        }
    }

    // Nouvelle méthode pour récupérer les statistiques
    public function getStats()
    {
        try {
            $transactions = Caissier::orderBy('created_at', 'desc')->get();

            $solde_actuel = $transactions->first()->solde ?? 0;
            $total_revenus = $transactions->where('type', 'revenu')->sum('montant');
            $total_depenses = $transactions->where('type', 'depense')->sum('montant');
            $nombre_transactions = $transactions->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'solde_actuel' => $solde_actuel,
                    'total_revenus' => $total_revenus,
                    'total_depenses' => $total_depenses,
                    'nombre_transactions' => $nombre_transactions
                ],
                'message' => 'Statistiques récupérées avec succès'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}