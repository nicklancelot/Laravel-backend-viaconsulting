<?php

namespace App\Http\Controllers\Vente;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExportationRequest;
use App\Http\Requests\UpdateExportationRequest;
use App\Models\Vente\Exportation;
use App\Models\Vente\Client;
use App\Models\SoldeUser;
use App\Models\Vente\Reception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Services\StockService;

class ExportationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = Auth::user();
        if (!$user || !in_array($user->role, ['admin', 'vendeur'])) {
            return response()->json(['success' => false, 'message' => 'Accès non autorisé'], 403);
        }

        if ($user->role === 'admin') {
            $exportations = Exportation::with('client')->get();
        } else {
            $exportations = Exportation::with('client')->where('utilisateur_id', $user->id)->get();
        }

        return response()->json(['success' => true, 'data' => $exportations]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return response()->json(['success' => false, 'message' => 'Non implémenté'], 404);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreExportationRequest $request)
    {
        // NOTE: frontend should send a multipart/form-data when files are included
        try {
            $user = Auth::user();
            if (!$user || !in_array($user->role, ['admin', 'vendeur'])) {
                return response()->json(['message' => 'Accès non autorisé'], 403);
            }

            $validated = $request->validated();

            // Verify client exists and ownership (if vendeur)
            $client = null;
            if (!empty($validated['client_id'])) {
                $client = Client::find($validated['client_id']);
                if (!$client) {
                    return response()->json(['message' => 'Client introuvable'], 422);
                }
                if ($user->role === 'vendeur' && $client->utilisateur_id != $user->id) {
                    return response()->json(['message' => 'Vous ne pouvez utiliser que vos propres clients'], 403);
                }
            }

            // Map request file fields (camelCase) to DB column names (snake_case)
            $fileFieldToColumn = [
                'devis' => 'devis_path',
                'proforma' => 'proforma_path',
                'phytosanitaire' => 'phytosanitaire_path',
                'eauxForets' => 'eaux_forets_path',
                'miseFobCif' => 'mise_fob_cif_path',
                'livraisonTransitaire' => 'livraison_transitaire_path',
                'transmissionDocuments' => 'transmission_documents_path',
                'recouvrement' => 'recouvrement_path',
                'pieceJustificative' => 'piece_justificative_path',
            ];

            // Handle files and store paths
            $paths = [];
            foreach ($fileFieldToColumn as $field => $column) {
                if ($request->hasFile($field)) {
                    $paths[$column] = $request->file($field)->store('exportations', 'public');
                }
            }

            $data = array_merge($validated, $paths);
            $data['utilisateur_id'] = $user->id;

            // Début transaction pour garantir l'intégrité des données
            DB::beginTransaction();

            try {
                $exportation = Exportation::create($data);

                // Si l'exportation concerne un produit HE, soustraire la quantité depuis les réceptions HE
                try {
                    $stockService = new StockService();
                    $stockService->decrementForProduct($exportation->produit, (float) $exportation->poids);
                } catch (\App\Exceptions\StockInsufficientException $e) {
                    // Renvoyer une erreur explicite au client et annuler la transaction
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Stock insuffisant', 'details' => $e->getMessage()], 400);
                } catch (\Throwable $e) {
                    // Ne pas casser la création si la mise à jour des réceptions échoue, mais logger
                    Log::error('Erreur lors de la soustraction HE pour exportation: ' . $e->getMessage(), [
                        'exportation_id' => $exportation->id,
                        'produit' => $exportation->produit,
                        'poids' => $exportation->poids,
                    ]);
                }

                // Set numero_contrat to id if not provided
                if (empty($exportation->numero_contrat)) {
                    $exportation->numero_contrat = $exportation->id;
                    $exportation->save();
                }

                // Mettre à jour le solde de l'utilisateur
                $this->updateUserSoldeForExportation($exportation);

                DB::commit();

                return response()->json(['success' => true, 'data' => $exportation->load('client')], 201);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Throwable $e) {
            Log::error('Erreur création exportation: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
            ]);

            $payload = ['success' => false, 'message' => 'Erreur serveur lors de la création de l\'exportation'];
            if (config('app.debug')) {
                $payload['details'] = $e->getMessage();
            }

            return response()->json($payload, 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Exportation $exportation)
    {
        $user = Auth::user();
        if (!$user || !in_array($user->role, ['admin', 'vendeur'])) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }
        if ($user->role === 'admin' || $exportation->utilisateur_id == $user->id) {
            return response()->json(['success' => true, 'data' => $exportation->load('client')]);
        }

        return response()->json(['success' => false, 'message' => 'Accès non autorisé'], 403);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Exportation $exportation)
    {
        return response()->json(['success' => false, 'message' => 'Non implémenté'], 404);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateExportationRequest $request, Exportation $exportation)
    {
        try {
            $user = Auth::user();
            if (!$user || !in_array($user->role, ['admin', 'vendeur'])) {
                return response()->json(['message' => 'Accès non autorisé'], 403);
            }

            if ($user->role === 'vendeur' && $exportation->utilisateur_id != $user->id) {
                return response()->json(['message' => 'Accès non autorisé'], 403);
            }

            $validated = $request->validated();

            // If client_id provided, validate ownership
            if (!empty($validated['client_id'])) {
                $client = Client::find($validated['client_id']);
                if (!$client) {
                    return response()->json(['message' => 'Client introuvable'], 422);
                }
                if ($user->role === 'vendeur' && $client->utilisateur_id != $user->id) {
                    return response()->json(['message' => 'Vous ne pouvez utiliser que vos propres clients'], 403);
                }
            }

            $fileFieldToColumn = [
                'devis' => 'devis_path',
                'proforma' => 'proforma_path',
                'phytosanitaire' => 'phytosanitaire_path',
                'eauxForets' => 'eaux_forets_path',
                'miseFobCif' => 'mise_fob_cif_path',
                'livraisonTransitaire' => 'livraison_transitaire_path',
                'transmissionDocuments' => 'transmission_documents_path',
                'recouvrement' => 'recouvrement_path',
                'pieceJustificative' => 'piece_justificative_path',
            ];

            // Handle file updates
            $paths = [];
            foreach ($fileFieldToColumn as $field => $column) {
                if ($request->hasFile($field)) {
                    $paths[$column] = $request->file($field)->store('exportations', 'public');
                }
            }

            // Stocker les anciennes valeurs avant mise à jour pour ajustement du solde et du stock
            $ancienPrixTotal = $exportation->prix_total;
            $ancienFraisTransport = $exportation->frais_transport;
            $ancienProduit = $exportation->produit;
            $ancienPoids = $exportation->poids;

            DB::beginTransaction();

            try {
                $exportation->update(array_merge($validated, $paths));

                // Mettre à jour le solde de l'utilisateur après modification
                if ($ancienPrixTotal != $exportation->prix_total || $ancienFraisTransport != $exportation->frais_transport) {
                    $this->adjustUserSoldeForUpdate($exportation, $ancienPrixTotal, $ancienFraisTransport);
                }

                // Ajuster le stock HE si produit/poids ont changé
                try {
                    $stockService = new StockService();
                    $nouveauProduit = $exportation->produit;
                    $nouveauPoids = is_numeric($exportation->poids) ? (float) $exportation->poids : 0;

                    // Si même produit, ajuster par différence
                    if ($ancienProduit === $nouveauProduit) {
                        $diff = $nouveauPoids - (float) $ancienPoids;
                        if ($diff > 0) {
                            $stockService->decrementForProduct($nouveauProduit, $diff);
                        } elseif ($diff < 0) {
                            $stockService->restoreForProduct($nouveauProduit, abs($diff));
                        }
                    } else {
                        // produit changé: restaurer ancien puis décrémenter nouveau
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
                    Log::error('Erreur ajustement stock pour exportation mise à jour: ' . $e->getMessage(), [
                        'exportation_id' => $exportation->id,
                    ]);
                }

                DB::commit();

                return response()->json(['success' => true, 'data' => $exportation->load('client')]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Throwable $e) {
            Log::error('Erreur mise à jour exportation: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'exportation_id' => $exportation->id ?? null,
            ]);

            $payload = ['success' => false, 'message' => 'Erreur serveur lors de la mise à jour de l\'exportation'];
            if (config('app.debug')) {
                $payload['details'] = $e->getMessage();
            }

            return response()->json($payload, 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Exportation $exportation)
    {
        try {
            $user = Auth::user();
            if (!$user || !in_array($user->role, ['admin', 'vendeur'])) {
                return response()->json(['message' => 'Accès non autorisé'], 403);
            }

            if ($user->role === 'vendeur' && $exportation->utilisateur_id != $user->id) {
                return response()->json(['message' => 'Accès non autorisé'], 403);
            }

            DB::beginTransaction();

            try {
                // Restaurer le solde avant suppression
                $this->restoreUserSoldeForDeletion($exportation);

                // Restaurer le stock HE correspondant à cette exportation
                try {
                    $stockService = new StockService();
                    if (!empty($exportation->produit) && is_numeric($exportation->poids) && $exportation->poids > 0) {
                        $stockService->restoreForProduct($exportation->produit, (float) $exportation->poids);
                    }
                } catch (\App\Exceptions\StockInsufficientException $e) {
                    // Si restauration impossible, loguer et continuer (ne devrait pas arriver normalement)
                    Log::warning('Impossible de restaurer le stock lors de suppression exportation: ' . $e->getMessage(), ['exportation_id' => $exportation->id]);
                } catch (\Throwable $e) {
                    Log::error('Erreur restauration stock lors de suppression exportation: ' . $e->getMessage(), ['exportation_id' => $exportation->id]);
                }

                $exportation->delete();

                DB::commit();

                return response()->json(['success' => true], 204);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Throwable $e) {
            Log::error('Erreur suppression exportation: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'exportation_id' => $exportation->id ?? null,
            ]);

            $payload = ['success' => false, 'message' => 'Erreur serveur lors de la suppression de l\'exportation'];
            if (config('app.debug')) {
                $payload['details'] = $e->getMessage();
            }

            return response()->json($payload, 500);
        }
    }

    /**
     * Mettre à jour le solde de l'utilisateur lors de la création d'une exportation
     */
    private function updateUserSoldeForExportation(Exportation $exportation)
    {
        $userId = $exportation->utilisateur_id;
        if (!$userId) {
            return;
        }

        // Récupérer ou créer le solde de l'utilisateur
        $soldeUser = SoldeUser::firstOrCreate(
            ['utilisateur_id' => $userId],
            ['solde' => 0]
        );

        $nouveauSolde = $soldeUser->solde;

        // Ajouter le prix total au solde
        if ($exportation->prix_total && is_numeric($exportation->prix_total)) {
            $nouveauSolde += (float) $exportation->prix_total;
        }

        // Soustraire les frais de transport
        if ($exportation->frais_transport && is_numeric($exportation->frais_transport)) {
            $nouveauSolde -= (float) $exportation->frais_transport;
        }

        $soldeUser->solde = $nouveauSolde;
        $soldeUser->save();

        Log::info('Solde mis à jour pour exportation création', [
            'user_id' => $userId,
            'ancien_solde' => $soldeUser->getOriginal('solde'),
            'nouveau_solde' => $nouveauSolde,
            'prix_total' => $exportation->prix_total,
            'frais_transport' => $exportation->frais_transport,
            'exportation_id' => $exportation->id
        ]);
    }

    /**
     * Ajuster le solde lors de la mise à jour d'une exportation
     */
    private function adjustUserSoldeForUpdate(Exportation $exportation, $ancienPrixTotal, $ancienFraisTransport)
    {
        $userId = $exportation->utilisateur_id;
        if (!$userId) {
            return;
        }

        $soldeUser = SoldeUser::where('utilisateur_id', $userId)->first();
        if (!$soldeUser) {
            $soldeUser = SoldeUser::create([
                'utilisateur_id' => $userId,
                'solde' => 0
            ]);
        }

        $nouveauSolde = $soldeUser->solde;

        // Ajustement pour le prix total
        if ($ancienPrixTotal != $exportation->prix_total) {
            // Soustraire l'ancien prix total
            if ($ancienPrixTotal && is_numeric($ancienPrixTotal)) {
                $nouveauSolde -= (float) $ancienPrixTotal;
            }
            // Ajouter le nouveau prix total
            if ($exportation->prix_total && is_numeric($exportation->prix_total)) {
                $nouveauSolde += (float) $exportation->prix_total;
            }
        }

        // Ajustement pour les frais de transport
        if ($ancienFraisTransport != $exportation->frais_transport) {
            // Ajouter l'ancien frais transport (puisqu'on l'avait soustrait)
            if ($ancienFraisTransport && is_numeric($ancienFraisTransport)) {
                $nouveauSolde += (float) $ancienFraisTransport;
            }
            // Soustraire le nouveau frais transport
            if ($exportation->frais_transport && is_numeric($exportation->frais_transport)) {
                $nouveauSolde -= (float) $exportation->frais_transport;
            }
        }

        $soldeUser->solde = $nouveauSolde;
        $soldeUser->save();

        Log::info('Solde ajusté pour exportation mise à jour', [
            'user_id' => $userId,
            'ancien_solde' => $soldeUser->getOriginal('solde'),
            'nouveau_solde' => $nouveauSolde,
            'ancien_prix_total' => $ancienPrixTotal,
            'nouveau_prix_total' => $exportation->prix_total,
            'ancien_frais_transport' => $ancienFraisTransport,
            'nouveau_frais_transport' => $exportation->frais_transport,
            'exportation_id' => $exportation->id
        ]);
    }

    /**
     * Restaurer le solde lors de la suppression d'une exportation
     */
    private function restoreUserSoldeForDeletion(Exportation $exportation)
    {
        $userId = $exportation->utilisateur_id;
        if (!$userId) {
            return;
        }

        $soldeUser = SoldeUser::where('utilisateur_id', $userId)->first();
        if (!$soldeUser) {
            return;
        }

        $nouveauSolde = $soldeUser->solde;

        // Soustraire le prix total (puisqu'on l'avait ajouté)
        if ($exportation->prix_total && is_numeric($exportation->prix_total)) {
            $nouveauSolde -= (float) $exportation->prix_total;
        }

        // Ajouter les frais de transport (puisqu'on les avait soustraits)
        if ($exportation->frais_transport && is_numeric($exportation->frais_transport)) {
            $nouveauSolde += (float) $exportation->frais_transport;
        }

        $soldeUser->solde = $nouveauSolde;
        $soldeUser->save();

        Log::info('Solde restauré pour exportation suppression', [
            'user_id' => $userId,
            'ancien_solde' => $soldeUser->getOriginal('solde'),
            'nouveau_solde' => $nouveauSolde,
            'prix_total' => $exportation->prix_total,
            'frais_transport' => $exportation->frais_transport,
            'exportation_id' => $exportation->id
        ]);
    }

    /**
     * Soustrait la quantité HE reçue lors de la création d'une exportation HE.
     *
     * Logique: si le champ `produit` contient "HE" on va soustraire le poids
     * fourni ($exportation->poids) depuis les réceptions (table `receptions`) qui
     * correspondent à `type_produit` = produit. Le décrément se fait FIFO (ordre
     * des réceptions) jusqu'à épuisement du poids demandé.
     */
    private function subtractHeFromReception(Exportation $exportation)
    {
        $produit = $exportation->produit;
        $poids = $exportation->poids;

        if (empty($produit) || empty($poids) || !is_numeric($poids)) {
            return;
        }

        // Ne traiter que les produits HE
        if (stripos($produit, 'HE') === false) {
            return;
        }

        $remaining = (float) $poids;

        Log::debug('subtractHeFromReception start', ['exportation_id' => $exportation->id, 'produit' => $produit, 'poids' => $poids]);

        // Récupérer les réceptions avec quantité positive pour ce type de produit
        // Normaliser les underscores/tirets en espaces côté DB et côté recherche pour couvrir
        // variations comme "HE_Feuilles" vs "HE feuilles".
        $searchPattern = '%'.strtolower(str_replace(['_', '-'], ' ', trim($produit))).'%';
        Log::debug('receptions search pattern', ['pattern' => $searchPattern]);

        $receptions = Reception::whereRaw('LOWER(REPLACE(REPLACE(type_produit, "_", " "), "-", " ")) LIKE ?', [$searchPattern])
            ->where('quantite_recue', '>', 0)
            ->orderBy('id')
            ->get();

        Log::debug('receptions found for product', ['count' => $receptions->count()]);

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

                Log::debug('reception partially consumed', ['reception_id' => $reception->id, 'before' => $available, 'after' => $reception->quantite_recue, 'debited' => $remaining]);

                $remaining = 0;
            } else {
                $reception->quantite_recue = 0;
                $reception->save();

                Log::debug('reception fully consumed', ['reception_id' => $reception->id, 'before' => $available, 'debited' => $available]);

                $remaining -= $available;
            }
        }

        if ($remaining > 0) {
            // Pas assez de stock HE: on logue l'événement pour information.
            Log::warning('Exportation HE: quantité demandée supérieure au stock HE disponible', [
                'exportation_id' => $exportation->id,
                'produit' => $produit,
                'poids_demande' => $poids,
                'reste_non_debite' => $remaining,
            ]);
        } else {
            Log::info('Quantité HE soustraite des réceptions pour exportation', [
                'exportation_id' => $exportation->id,
                'produit' => $produit,
                'poids' => $poids,
            ]);
        }
    }
}