<?php

namespace App\Http\Controllers\MatierePremiere;

use App\Http\Controllers\Controller;
use App\Models\MatierePremiere\FicheLivraison;
use App\Models\MatierePremiere\Stockpv;
use App\Models\Utilisateur;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\Livreur;

class FicheLivraisonController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $fiches = FicheLivraison::with(['stockpv', 'livreur', 'distilleur.siteCollecte'])
                ->orderBy('created_at', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $fiches
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des fiches de livraison'
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        DB::beginTransaction();
        
        try {
            $validator = Validator::make($request->all(), [
                'stockpvs_id' => 'required|exists:stockpvs,id',
                'livreur_id' => 'required|exists:livreurs,id',
                'distilleur_id' => 'required|exists:utilisateurs,id',
                'date_livraison' => 'required|date',
                'lieu_depart' => 'required|string',
                'ristourne_regionale' => 'nullable|numeric|min:0',
                'ristourne_communale' => 'nullable|numeric|min:0',
                'quantite_a_livrer' => 'required|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Récupérer le distilleur avec son site de collecte
            $distilleur = Utilisateur::with('siteCollecte')
                ->where('id', $request->distilleur_id)
                ->where('role', 'distilleur')
                ->first();

            if (!$distilleur) {
                return response()->json([
                    'success' => false,
                    'message' => 'Distilleur non trouvé ou rôle incorrect'
                ], 404);
            }

            // Vérifier que le distilleur a un site de collecte
            if (!$distilleur->site_collecte_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le distilleur n\'a pas de site de collecte attribué'
                ], 400);
            }

            $stockpv = Stockpv::find($request->stockpvs_id);
            
            if (!$stockpv) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stock non trouvé'
                ], 404);
            }

            // Vérifier le stock disponible
            if ($stockpv->stock_disponible < $request->quantite_a_livrer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stock insuffisant. 
                        Disponible: ' . $stockpv->stock_disponible . ' 
                        Demandé: ' . $request->quantite_a_livrer
                ], 400);
            }

            // Soustraire du stock disponible
            $stockpv->decrement('stock_disponible', $request->quantite_a_livrer);
            
            // Créer la fiche de livraison
            $fiche = FicheLivraison::create([
                'stockpvs_id' => $request->stockpvs_id,
                'livreur_id' => $request->livreur_id,
                'distilleur_id' => $distilleur->id,
                'date_livraison' => $request->date_livraison,
                'lieu_depart' => $request->lieu_depart,
                'ristourne_regionale' => $request->ristourne_regionale ?? 0,
                'ristourne_communale' => $request->ristourne_communale ?? 0,
                'quantite_a_livrer' => $request->quantite_a_livrer,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Fiche de livraison créée avec succès',
                'data' => $fiche->load(['stockpv', 'livreur', 'distilleur.siteCollecte']),
                'destinataire' => [
                    'distilleur_id' => $distilleur->id,
                    'nom_complet' => $distilleur->nom . ' ' . $distilleur->prenom,
                    'site_collecte' => $distilleur->siteCollecte->Nom ?? 'Non défini',
                    'site_collecte_id' => $distilleur->site_collecte_id
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de la fiche de livraison',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $fiche = FicheLivraison::with(['stockpv', 'livreur', 'distilleur.siteCollecte'])
                ->find($id);
            
            if (!$fiche) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fiche de livraison non trouvée'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $fiche
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la fiche de livraison'
            ], 500);
        }
    }

    // Méthode pour récupérer les fiches par site de collecte
    public function getBySiteCollecte($siteCollecteNom): JsonResponse
    {
        try {
            // Récupérer les fiches où le distilleur a ce site de collecte
            $fiches = FicheLivraison::whereHas('distilleur.siteCollecte', function($query) use ($siteCollecteNom) {
                    $query->where('Nom', $siteCollecteNom);
                })
                ->with(['stockpv', 'livreur', 'distilleur.siteCollecte'])
                ->orderBy('created_at', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $fiches,
                'site_collecte' => $siteCollecteNom
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des fiches par site'
            ], 500);
        }
    }

    // Méthode pour récupérer les distillateurs disponibles
    public function getDistillateurs(): JsonResponse
    {
        try {
            $distillateurs = Utilisateur::where('role', 'distilleur')
                ->with('siteCollecte')
                ->get()
                ->map(function ($distilleur) {
                    return [
                        'id' => $distilleur->id,
                        'nom_complet' => $distilleur->nom . ' ' . $distilleur->prenom,
                        'site_collecte' => $distilleur->siteCollecte->Nom ?? 'Non attribué',
                        'site_collecte_id' => $distilleur->site_collecte_id,
                        'numero' => $distilleur->numero,
                        'localisation_id' => $distilleur->localisation_id
                    ];
                });
            
            return response()->json([
                'success' => true,
                'data' => $distillateurs
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des distillateurs'
            ], 500);
        }
    }
}