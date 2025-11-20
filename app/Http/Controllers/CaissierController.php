<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Caissier;

class CaissierController extends Controller
{
    public function index()
    {
        return response()->json(Caissier::all());
    }

    public function store(Request $request)
    {
        $request->validate([
            'utilisateur_id' => 'required|exists:utilisateurs,id',
            'montant' => 'required|numeric|min:0',
            'type' => 'required|in:revenu,depense',
            'methode' => 'required|string',
            'raison' => 'nullable|string',
            'reference' => 'nullable|string'
        ]);

        // Calcul du nouveau solde
        $ancienSolde = Caissier::where('utilisateur_id', $request->utilisateur_id)
            ->latest()
            ->value('solde') ?? 0;

        $nouveauSolde = $request->type === 'revenu' 
            ? $ancienSolde + $request->montant
            : $ancienSolde - $request->montant;

        $transaction = Caissier::create([
            'utilisateur_id' => $request->utilisateur_id,
            'solde' => $nouveauSolde,
            'date' => now(),
            'montant' => $request->montant,
            'type' => $request->type,
            'methode' => $request->methode,
            'raison' => $request->raison,
            'reference' => $request->reference
        ]);

        return response()->json($transaction, 201);
    }

    public function show(string $id)
    {
        return response()->json(Caissier::findOrFail($id));
    }

    public function update(Request $request, string $id)
    {
        $transaction = Caissier::findOrFail($id);
        
        $request->validate([
            'montant' => 'numeric|min:0',
            'type' => 'in:revenu,depense',
            'methode' => 'string',
            'raison' => 'nullable|string',
            'reference' => 'nullable|string'
        ]);

        $transaction->update($request->all());

        return response()->json($transaction);
    }

    public function destroy(string $id)
    {
        Caissier::findOrFail($id)->delete();
        return response()->json(['message' => 'Transaction supprimée']);
    }

    // Fonction pour ajuster le solde
    public function ajusterSolde(Request $request, $utilisateur_id)
    {
        $request->validate([
            'nouveau_solde' => 'required|numeric|min:0',
            'raison' => 'required|string'
        ]);

        $ancienSolde = Caissier::where('utilisateur_id', $utilisateur_id)
            ->latest()
            ->value('solde') ?? 0;

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

        return response()->json($ajustement);
    }

    // Fonction pour retirer du solde
    public function retirerSolde(Request $request, $utilisateur_id)
    {
        $request->validate([
            'montant' => 'required|numeric|min:0',
            'raison' => 'required|string',
            'methode' => 'required|string'
        ]);

        $ancienSolde = Caissier::where('utilisateur_id', $utilisateur_id)
            ->latest()
            ->value('solde') ?? 0;

        if ($ancienSolde < $request->montant) {
            return response()->json(['error' => 'Solde insuffisant'], 400);
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

        return response()->json($retrait);
    }
}