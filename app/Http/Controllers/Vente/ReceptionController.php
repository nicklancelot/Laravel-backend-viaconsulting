<?php

namespace App\Http\Controllers\Vente;

use App\Http\Controllers\Controller;
use App\Models\Vente\Reception;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse; // ← Ajout manquant
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReceptionController extends Controller
{
    /**
     * Afficher la liste de toutes les réceptions (en attente en premier)
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();
        
        $receptions = Reception::with(['ficheLivraison', 'vendeur'])
            ->when($user->role === 'vendeur', function ($query) use ($user) {
                return $query->where('vendeur_id', $user->id);
            })
            ->orderByRaw("CASE WHEN statut = 'en attente' THEN 0 ELSE 1 END")
            ->orderBy('created_at', 'desc')
            ->get();
        
        // Ajouter le type de produit pour chaque réception
        $receptions->each(function ($reception) {
            $reception->type_produit = $reception->type_produit ?? 
                ($reception->ficheLivraison?->type_produit ?? 'Inconnu');
        });
        
        return response()->json([
            'success' => true,
            'message' => 'Liste des réceptions',
            'data' => $receptions,
            'stats' => [
                'total' => $receptions->count(),
                'en_attente' => $receptions->where('statut', 'en attente')->count(),
                'receptionne' => $receptions->where('statut', 'receptionne')->count(),
            ]
        ]);
    }

    /**
     * Afficher les réceptions réceptionnées
     */
    public function getRecues(): JsonResponse
    {
        $user = Auth::user();
        
        $receptions = Reception::with(['ficheLivraison', 'vendeur'])
            ->where('statut', 'receptionne')
            ->when($user->role === 'vendeur', function ($query) use ($user) {
                return $query->where('vendeur_id', $user->id);
            })
            ->orderBy('date_receptionne', 'desc')
            ->get();
        
        return response()->json([
            'success' => true,
            'message' => 'Réceptions réceptionnées',
            'data' => $receptions,
            'count' => $receptions->count()
        ]);
    }

    /**
     * Marquer une réception comme réceptionnée
     */
    public function marquerReceptionne(Request $request, $id): JsonResponse
    {
        DB::beginTransaction();

        try {
            $user = Auth::user();
            $reception = Reception::find($id);

            if (!$reception) {
                return response()->json([
                    'success' => false,
                    'message' => 'Réception non trouvée'
                ], 404);
            }

            // Vérifier que seul le vendeur concerné peut marquer comme réceptionné
            if ($user->role === 'vendeur' && $reception->vendeur_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Seul le vendeur concerné peut marquer la réception comme réceptionnée'
                ], 403);
            }

            // Vérifier que la réception est en attente
            if ($reception->statut !== 'en attente') {
                return response()->json([
                    'success' => false,
                    'message' => 'Seules les réceptions en attente peuvent être marquées comme réceptionnées'
                ], 400);
            }

            $validated = $request->validate([
                'observations' => 'nullable|string'
            ]);

            // Marquer comme réceptionnée
            $reception->statut = 'receptionne';
            $reception->date_receptionne = now();
            if (!empty($validated['observations'] ?? '')) {
                $reception->observations = $reception->observations 
                    ? $reception->observations . "\n" . $validated['observations']
                    : $validated['observations'];
            }
            $reception->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Réception marquée comme réceptionnée avec succès',
                'data' => $reception->load(['ficheLivraison', 'vendeur'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du marquage de la réception',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Récupérer les réceptions du vendeur connecté
     */
    public function getMesReceptions(): JsonResponse
    {
        $user = Auth::user();
        
        if ($user->role !== 'vendeur') {
            return response()->json([
                'success' => false,
                'message' => 'Accès réservé aux vendeurs'
            ], 403);
        }

        $receptions = Reception::with(['ficheLivraison', 'vendeur'])
            ->where('vendeur_id', $user->id)
            ->orderByRaw("CASE WHEN statut = 'en attente' THEN 0 ELSE 1 END")
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Mes réceptions',
            'data' => $receptions,
            'stats' => [
                'total' => $receptions->count(),
                'en_attente' => $receptions->where('statut', 'en attente')->count(),
                'receptionne' => $receptions->where('statut', 'receptionne')->count(),
            ]
        ]);
    }

    /**
     * Récupérer les statistiques simplifiées pour les cartes
     */
   public function getStatsCartes(): JsonResponse
{
    try {
        $user = Auth::user();

        // Requête groupée sur receptions
        $rows = Reception::select(
                'type_produit',
                'statut',
                DB::raw('SUM(quantite_recue) as total_quantite')
            )
            ->when($user->role === 'vendeur', function ($query) use ($user) {
                $query->where('vendeur_id', $user->id);
            })
            ->groupBy('type_produit', 'statut')
            ->get();

        // Structure des cards
        $stats = [
            'feuilles' => ['en_attente' => 0, 'receptionne' => 0, 'total' => 0],
            'clous'    => ['en_attente' => 0, 'receptionne' => 0, 'total' => 0],
            'griffes'  => ['en_attente' => 0, 'receptionne' => 0, 'total' => 0],
        ];
foreach ($rows as $row) {
    if (!$row->type_produit) {
        continue;
    }

    $rawType = strtolower($row->type_produit);

    if (str_contains($rawType, 'feuille')) {
        $type = 'feuilles';
    } elseif (str_contains($rawType, 'clou')) {
        $type = 'clous';
    } elseif (str_contains($rawType, 'griffe')) {
        $type = 'griffes';
    } else {
        continue;
    }

    // Normaliser la clé de statut pour correspondre aux clefs du tableau $stats
    // (par ex. 'en attente' -> 'en_attente')
    $statusKey = null;
    if ($row->statut === 'en attente') {
        $statusKey = 'en_attente';
    } elseif ($row->statut === 'receptionne') {
        $statusKey = 'receptionne';
    } else {
        // Si un autre statut apparait, on l'ignore pour les cartes
        continue;
    }

    // S'assurer que la clef existe
    if (!isset($stats[$type][$statusKey])) {
        $stats[$type][$statusKey] = 0;
    }

    $stats[$type][$statusKey] += (float) $row->total_quantite;
    $stats[$type]['total'] += (float) $row->total_quantite;
}

        // Totaux globaux (tous produits confondus)
        $totauxGlobaux = [
            'en_attente' => $rows->where('statut', 'en attente')->sum('total_quantite'),
            'receptionne' => $rows->where('statut', 'receptionne')->sum('total_quantite'),
            'total' => $rows->sum('total_quantite'),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Statistiques des réceptions',
            'data' => [
                'cards' => $stats,
                'totaux' => $totauxGlobaux,
                'user' => [
                    'role' => $user->role,
                    'nom_complet' => trim($user->nom . ' ' . $user->prenom),
                ],
                'generated_at' => now()->toDateTimeString(),
            ]
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors du calcul des statistiques',
            'error' => app()->environment('local') ? $e->getMessage() : null
        ], 500);
    }
}


    /**
     * Déterminer le type de produit d'une réception
     */
    private function determinerTypeProduit(Reception $reception): string
    {
        $type = null;

        // Priorité 1 : champ direct sur la réception
        if ($reception->type_produit) {
            $type = strtolower($reception->type_produit);
        }
        // Priorité 2 : via fiche de livraison
        elseif ($reception->ficheLivraison?->type_produit) {
            $type = strtolower($reception->ficheLivraison->type_produit);
        }
        // Priorité 3 : via transport (si relation existe)
        elseif ($reception->relationLoaded('transport') && $reception->transport?->type_matiere) {
            $type = strtolower($reception->transport->type_matiere);
        }

        if ($type) {
            if (str_contains($type, 'feuilles')) return 'feuilles';
            if (str_contains($type, 'clous'))    return 'clous';
            if (str_contains($type, 'griffes'))  return 'griffes';
        }

        // Valeur par défaut
        return 'feuilles';
    }
}