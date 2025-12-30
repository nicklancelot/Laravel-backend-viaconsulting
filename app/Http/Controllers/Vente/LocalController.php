<?php

namespace App\Http\Controllers\Vente;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLocalRequest;
use App\Http\Requests\UpdateLocalRequest;
use App\Models\Vente\Local;
use App\Models\Vente\Client;
use App\Models\Vente\Reception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\StockService;

class LocalController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = Auth::user();
        if (!$user || !in_array($user->role, ['admin', 'vendeur'])) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        if ($user->role === 'admin') {
            $locals = Local::with('client')->get();
        } else {
            $locals = Local::with('client')->where('utilisateur_id', $user->id)->get();
        }

        return response()->json($locals);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return response()->json(['message' => 'Non implémenté'], 404);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreLocalRequest $request)
    {
        $user = Auth::user();
        if (!$user || !in_array($user->role, ['admin', 'vendeur'])) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $validated = $request->validated();

        if (!empty($validated['client_id'])) {
            $client = Client::find($validated['client_id']);
            if (!$client) {
                return response()->json(['message' => 'Client introuvable'], 422);
            }
            if ($user->role === 'vendeur' && $client->utilisateur_id != $user->id) {
                return response()->json(['message' => 'Vous ne pouvez utiliser que vos propres clients'], 403);
            }
        }

        $paths = [];
        foreach (['testQualite', 'livraisonClient', 'agreageClient', 'recouvrement', 'pieceJustificative'] as $field) {
            if ($request->hasFile($field)) {
                $paths[$this->mapFieldToPath($field)] = $request->file($field)->store('locals', 'public');
            }
        }

        $data = array_merge($validated, $paths);
        $data['utilisateur_id'] = $user->id;

        DB::beginTransaction();
        try {
            $local = Local::create($data);

            if (empty($local->numero_contrat)) {
                $local->numero_contrat = $local->id;
                $local->save();
            }

            // Après création, décrémenter les réceptions HE correspondantes si applicable
            try {
                $stockService = new StockService();
                $prod = $local->produit_bon_livraison ?? $local->produit ?? null;
                $poids = is_numeric($local->poids_bon_livraison) ? (float) $local->poids_bon_livraison : 0;
                if ($prod && $poids > 0) {
                    $stockService->decrementForProduct($prod, $poids);
                }
            } catch (\App\Exceptions\StockInsufficientException $e) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Stock insuffisant', 'details' => $e->getMessage()], 400);
            } catch (\Throwable $e) {
                // Logger l'erreur mais ne pas empêcher la création
                Log::error('Erreur lors de la soustraction HE pour local: ' . $e->getMessage(), [
                    'local_id' => $local->id,
                    'produit' => $local->produit_bon_livraison ?? $local->produit,
                    'poids' => $local->poids_bon_livraison ?? null,
                ]);
            }

            DB::commit();

            return response()->json($local->load('client'), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur création local: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
            ]);

            return response()->json(['message' => 'Erreur serveur lors de la création du local'], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Local $local)
    {
        $user = Auth::user();
        if (!$user || !in_array($user->role, ['admin', 'vendeur'])) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        if ($user->role === 'admin' || $local->utilisateur_id == $user->id) {
            return response()->json($local->load('client'));
        }

        return response()->json(['message' => 'Accès non autorisé'], 403);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Local $local)
    {
        return response()->json(['message' => 'Non implémenté'], 404);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateLocalRequest $request, Local $local)
    {
        $user = Auth::user();
        if (!$user || !in_array($user->role, ['admin', 'vendeur'])) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        if ($user->role === 'vendeur' && $local->utilisateur_id != $user->id) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $validated = $request->validated();

        if (!empty($validated['client_id'])) {
            $client = Client::find($validated['client_id']);
            if (!$client) {
                return response()->json(['message' => 'Client introuvable'], 422);
            }
            if ($user->role === 'vendeur' && $client->utilisateur_id != $user->id) {
                return response()->json(['message' => 'Vous ne pouvez utiliser que vos propres clients'], 403);
            }
        }

        $paths = [];
        foreach (['testQualite', 'livraisonClient', 'agreageClient', 'recouvrement', 'pieceJustificative'] as $field) {
            if ($request->hasFile($field)) {
                $paths[$this->mapFieldToPath($field)] = $request->file($field)->store('locals', 'public');
            }
        }

        // Stocker ancien produit/poids pour ajustement du stock
        $ancienProduit = $local->produit_bon_livraison ?? $local->produit ?? null;
        $ancienPoids = $local->poids_bon_livraison ?? null;

        DB::beginTransaction();
        try {
            $local->update(array_merge($validated, $paths));

            // Ajuster le stock HE si besoin
                try {
                    $stockService = new StockService();
                    $nouveauProduit = $local->produit_bon_livraison ?? $local->produit ?? null;
                    $nouveauPoids = is_numeric($local->poids_bon_livraison) ? (float) $local->poids_bon_livraison : 0;

                    if ($ancienProduit === $nouveauProduit) {
                        $diff = $nouveauPoids - (float) $ancienPoids;
                        if ($diff > 0) {
                            $stockService->decrementForProduct($nouveauProduit, $diff);
                        } elseif ($diff < 0) {
                            $stockService->restoreForProduct($nouveauProduit, abs($diff));
                        }
                    } else {
                        if (!empty($ancienProduit) && is_numeric($ancienPoids) && $ancienPoids > 0) {
                            $stockService->restoreForProduct($ancienProduit, (float) $ancienPoids);
                        }
                        if (!empty($nouveauProduit) && $nouveauPoids > 0) {
                            $stockService->decrementForProduct($nouveauProduit, $nouveauPoids);
                        }
                    }
                } catch (\App\Exceptions\StockInsufficientException $e) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Stock insuffisant', 'details' => $e->getMessage()], 400);
                } catch (\Throwable $e) {
                    Log::error('Erreur ajustement stock pour local mise à jour: ' . $e->getMessage(), [
                        'local_id' => $local->id,
                    ]);
                }

            DB::commit();

            return response()->json($local->load('client'));
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur mise à jour local: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'local_id' => $local->id,
            ]);

            return response()->json(['message' => 'Erreur serveur lors de la mise à jour du local'], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Local $local)
    {
        $user = Auth::user();
        if (!$user || !in_array($user->role, ['admin', 'vendeur'])) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        if ($user->role === 'vendeur' && $local->utilisateur_id != $user->id) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        DB::beginTransaction();
        try {
            // Restaurer le stock HE associé au local supprimé
            try {
                $stockService = new StockService();
                $prod = $local->produit_bon_livraison ?? $local->produit ?? null;
                $poids = is_numeric($local->poids_bon_livraison) ? (float) $local->poids_bon_livraison : 0;
                if ($prod && $poids > 0) {
                    $stockService->restoreForProduct($prod, $poids);
                }
            } catch (\Throwable $e) {
                Log::error('Erreur restauration stock lors de suppression local: ' . $e->getMessage(), ['local_id' => $local->id]);
            }

            $local->delete();

            DB::commit();

            return response()->noContent();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur suppression local: ' . $e->getMessage(), ['local_id' => $local->id]);
            return response()->json(['message' => 'Erreur serveur lors de la suppression du local'], 500);
        }
    }

    private function mapFieldToPath(string $field): string
    {
        return match ($field) {
            'testQualite' => 'test_qualite_path',
            'livraisonClient' => 'livraison_client_path',
            'agreageClient' => 'agreage_client_path',
            'recouvrement' => 'recouvrement_path',
            'pieceJustificative' => 'piece_justificative_path',
            default => $field . '_path',
        };
    }

    /**
     * Soustrait la quantité HE reçue lors de la création d'un Local.
     *
     * Fonction similaire à subtractHeFromReception dans ExportationController mais
     * adaptée aux champs `produit_bon_livraison` et `poids_bon_livraison` du modèle Local.
     */
    private function subtractHeFromReceptionForLocal(Local $local)
    {
        $produit = $local->produit_bon_livraison ?? $local->produit ?? null;
        $poids = $local->poids_bon_livraison ?? null;

        if (empty($produit) || empty($poids) || !is_numeric($poids)) {
            return;
        }

        // Ne traiter que les produits HE
        if (stripos($produit, 'HE') === false) {
            return;
        }

        $remaining = (float) $poids;

        Log::debug('subtractHeFromReceptionForLocal start', ['local_id' => $local->id, 'produit' => $produit, 'poids' => $poids]);

        $searchPattern = '%'.strtolower(str_replace(['_', '-'], ' ', trim($produit))).'%';
        Log::debug('receptions search pattern for local', ['pattern' => $searchPattern]);

        $receptions = Reception::whereRaw('LOWER(REPLACE(REPLACE(type_produit, "_", " "), "-", " ")) LIKE ?', [$searchPattern])
            ->where('quantite_recue', '>', 0)
            ->orderBy('id')
            ->get();

        Log::debug('receptions found for product (local)', ['count' => $receptions->count()]);

        foreach ($receptions as $reception) {
            if ($remaining <= 0) {
                break;
            }

            $available = (float) $reception->quantite_recue;
            if ($available <= 0) {
                continue;
            }

            if ($available >= $remaining) {
                $reception->quantite_recue = $available - $remaining;
                $reception->save();

                Log::debug('reception partially consumed (local)', ['reception_id' => $reception->id, 'before' => $available, 'after' => $reception->quantite_recue, 'debited' => $remaining]);

                $remaining = 0;
            } else {
                $reception->quantite_recue = 0;
                $reception->save();

                Log::debug('reception fully consumed (local)', ['reception_id' => $reception->id, 'before' => $available, 'debited' => $available]);

                $remaining -= $available;
            }
        }

        if ($remaining > 0) {
            Log::warning('Local HE: quantité demandée supérieure au stock HE disponible', [
                'local_id' => $local->id,
                'produit' => $produit,
                'poids_demande' => $poids,
                'reste_non_debite' => $remaining,
            ]);
        } else {
            Log::info('Quantité HE soustraite des réceptions pour local', [
                'local_id' => $local->id,
                'produit' => $produit,
                'poids' => $poids,
            ]);
        }
    }
}
