<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Transfert;
use App\Models\Caissier;
use App\Models\SoldeUser;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;

class TransfertController extends Controller
{
    public function index()
    {
        try {
            $transferts = Transfert::with([
                'admin:id,nom,prenom,numero',
                'destinataire:id,nom,prenom,numero,role'
            ])
            ->orderBy('created_at', 'desc')
            ->get();

            return response()->json([
                'success' => true,
                'data' => $transferts,
                'message' => 'Transferts récupérés avec succès'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des transferts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
{
    DB::beginTransaction();
    
    try {
        $request->validate([
            'admin_id' => 'required|exists:utilisateurs,id',
            'destinataire_id' => 'required|exists:utilisateurs,id',
            'montant' => 'required|numeric|min:0.01',
            'type_transfert' => 'required|in:especes,mobile,virement',
            'reference' => 'nullable|string|max:50',
            'raison' => 'nullable|string|max:500'
        ]);
        // Vérifier que l'initiateur a le rôle admin ou vendeur
        $initiateur = Utilisateur::find($request->admin_id);
        if (!$initiateur || !in_array($initiateur->role, ['admin', 'vendeur'])) {
            return response()->json([
                'success' => false,
                'message' => 'Seuls les administrateurs ou les vendeurs peuvent effectuer des transferts'
            ], 403);
        }

        // Si l'initiateur est un vendeur, s'assurer que le destinataire est un administrateur
        if ($initiateur->role === 'vendeur') {
            $destinataireUser = Utilisateur::find($request->destinataire_id);
            if (!$destinataireUser || $destinataireUser->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Les vendeurs ne peuvent transférer qu\'aux administrateurs'
                ], 403);
            }
        }

        // Vérifier que le destinataire n'est pas le même que l'admin
        if ($request->admin_id === $request->destinataire_id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez pas vous transférer de l\'argent à vous-même'
            ], 400);
        }

        // Préparer la variable de retour du solde pour éviter les variables non définies
        $nouveauSolde = null;

        // Si l'initiateur est un vendeur, vérifier et décrémenter son solde utilisateur
        if ($initiateur->role === 'vendeur') {
            $soldeInitiateur = SoldeUser::where('utilisateur_id', $initiateur->id)->first();

            if (!$soldeInitiateur || $soldeInitiateur->solde < $request->montant) {
                $dispo = $soldeInitiateur ? number_format($soldeInitiateur->solde, 0, ',', ' ') . ' Ar' : '0 Ar';
                return response()->json([
                    'success' => false,
                    'message' => 'Solde insuffisant du vendeur pour effectuer ce transfert. Solde disponible: ' . $dispo
                ], 400);
            }

            // Créer le transfert
            $transfert = Transfert::create($request->all());

            // Décrémenter le solde du vendeur
            $soldeInitiateur->decrement('solde', $request->montant);

            // Mettre à jour la caisse globale : créer une entrée de type 'recette'
            $derniereTransactionCaisse = Caissier::latest()->first();
            $soldeCaisseActuel = $derniereTransactionCaisse ? $derniereTransactionCaisse->solde : 0;
            $nouveauSoldeCaisse = $soldeCaisseActuel + $request->montant;

            Caissier::create([
                'utilisateur_id' => $request->destinataire_id, 
                'solde' => $nouveauSoldeCaisse,
                'date' => now(),
                'montant' => $request->montant,
                'type' => 'recette',
                'methode' => $request->type_transfert,
                'raison' => $this->genererRaisonTransfert($transfert),
                'reference' => $request->reference
            ]);

            // Pour la réponse on renvoie maintenant le solde global mis à jour
            $nouveauSolde = $nouveauSoldeCaisse;
        } else {
            // Vérifier le solde disponible (dernier solde dans la table caissiers) pour les admins
            $derniereTransaction = Caissier::latest()->first();
            $soldeActuel = $derniereTransaction ? $derniereTransaction->solde : 0;

            if ($soldeActuel < $request->montant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solde insuffisant pour effectuer ce transfert. Solde disponible: ' . number_format($soldeActuel, 0, ',', ' ') . ' Ar'
                ], 400);
            }

            // Créer le transfert
            $transfert = Transfert::create($request->all());

            // Calculer le nouveau solde après transfert
            $nouveauSolde = $soldeActuel - $request->montant;

            // Créer une transaction de dépense dans la caisse
            Caissier::create([
                'utilisateur_id' => $request->admin_id,
                'solde' => $nouveauSolde,
                'date' => now(),
                'montant' => $request->montant,
                'type' => 'depense',
                'methode' => $request->type_transfert,
                'raison' => $this->genererRaisonTransfert($transfert),
                'reference' => $request->reference
            ]);
        }

        // METTRE À JOUR SOLDEUSER - NOUVEAU CODE
        $soldeUser = SoldeUser::where('utilisateur_id', $request->destinataire_id)->first();
        
        if ($soldeUser) {
            // Si le solde existe, l'incrémenter
            $soldeUser->increment('solde', $request->montant);
        } else {
            // Sinon créer un nouveau solde
            SoldeUser::create([
                'utilisateur_id' => $request->destinataire_id,
                'solde' => $request->montant
            ]);
        }

        DB::commit();

        return response()->json([
            'success' => true,
            'data' => $transfert->load(['admin:id,nom,prenom', 'destinataire:id,nom,prenom']),
            'message' => 'Transfert effectué avec succès',
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
            'message' => 'Erreur lors du transfert',
            'error' => $e->getMessage()
        ], 500);
    }
}
    public function show($id)
    {
        try {
            $transfert = Transfert::with([
                'admin:id,nom,prenom,numero,role',
                'destinataire:id,nom,prenom,numero,role'
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $transfert,
                'message' => 'Transfert récupéré avec succès'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Transfert non trouvé'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du transfert',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getUtilisateur($id)
    {
        try {
            $utilisateur = Utilisateur::select('id', 'nom', 'prenom', 'numero', 'role')
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $utilisateur,
                'message' => 'Utilisateur récupéré avec succès'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non trouvé'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l\'utilisateur',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getSoldeActuel()
    {
        try {
            $derniereTransaction = Caissier::latest()->first();
            $soldeActuel = $derniereTransaction ? $derniereTransaction->solde : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'solde_actuel' => $soldeActuel
                ],
                'message' => 'Solde actuel récupéré avec succès'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du solde',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function genererRaisonTransfert(Transfert $transfert)
    {
        $destinataire = $transfert->destinataire;
        $raisonBase = "Transfert à {$destinataire->prenom} {$destinataire->nom}";
        
        if ($transfert->raison) {
            $raisonBase .= " - {$transfert->raison}";
        }
        
        return $raisonBase;
    }
    // Dans TransfertController.php - ajouter cette méthode
public function getTransfertsByUtilisateur($utilisateur_id)
{
    try {
        $transferts = Transfert::with(['admin:id,nom,prenom,numero'])
            ->where('destinataire_id', $utilisateur_id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $transferts,
            'message' => 'Transferts reçus récupérés avec succès'
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la récupération des transferts',
            'error' => $e->getMessage()
        ], 500);
    }
}
}