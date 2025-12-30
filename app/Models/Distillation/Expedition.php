<?php

namespace App\Models\Distillation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Log;

class Expedition extends Model
{
    protected $fillable = [
        'fiche_livraison_id',
        'statut',
        'date_expedition',
        'date_reception',
        'quantite_expediee',
        'quantite_recue',
        'type_matiere',
        'lieu_depart',
        'observations'
    ];

    /**
     * Boot method pour ajouter automatiquement la quantité au stock agrégé
     */
    protected static function boot()
    {
        parent::boot();

        // Ajouter au stock agrégé quand l'expédition est réceptionnée
        static::updated(function ($expedition) {
            if ($expedition->isDirty('statut') && $expedition->estReceptionne()) {
                Log::info('Expédition réceptionnée, ajout au StockADistiller agrégé - ID: ' . $expedition->id);
                $expedition->ajouterAuStockAgrege();
            }
        });

        // Pour les expéditions déjà réceptionnées (migration)
        static::created(function ($expedition) {
            if ($expedition->estReceptionne()) {
                Log::info('Expédition créée déjà réceptionnée, ajout au StockADistiller agrégé - ID: ' . $expedition->id);
                $expedition->ajouterAuStockAgrege();
            }
        });

        // Supprimer du stock agrégé si l'expédition est supprimée
        static::deleted(function ($expedition) {
            if ($expedition->estReceptionne()) {
                Log::info('Expédition supprimée, retrait du StockADistiller agrégé - ID: ' . $expedition->id);
                $expedition->retirerDuStockAgrege();
            }
        });
    }

    /**
     * Ajouter la quantité au stock agrégé
     */
    public function ajouterAuStockAgrege(): void
    {
        try {
            // Vérifier que la fiche de livraison existe
            if (!$this->ficheLivraison) {
                Log::error('FicheLivraison non trouvée pour expédition ID: ' . $this->id);
                return;
            }

            // Vérifier que le distilleur existe
            if (!$this->ficheLivraison->distilleur) {
                Log::error('Distilleur non trouvé pour expédition ID: ' . $this->id);
                return;
            }

            // Récupérer la quantité reçue
            $quantiteRecue = $this->quantite_recue ?? $this->quantite_expediee;
            
            if ($quantiteRecue <= 0) {
                Log::warning('Quantité reçue nulle ou négative pour expédition ID: ' . $this->id);
                return;
            }

            // Récupérer le stockpv pour les taux
            $stockpv = $this->ficheLivraison->stockpv ?? null;

            // Ajouter au stock agrégé
            $stockAgrege = StockADistiller::ajouterDepuisExpedition($this);

            Log::info('Quantité ajoutée au stock agrégé - Expédition ID: ' . $this->id . 
                     ', Type: ' . $this->type_matiere . 
                     ', Quantité: ' . $quantiteRecue . 
                     ', Stock ID: ' . ($stockAgrege->id ?? 'N/A'));

            // Optionnel : Marquer l'expédition comme traitée
            $this->update([
                'observations' => ($this->observations ?? '') . ' - Quantité ajoutée au stock agrégé'
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur ajout au stock agrégé: ' . $e->getMessage() . 
                      ' - Trace: ' . $e->getTraceAsString() . 
                      ' - Expédition ID: ' . $this->id);
        }
    }

    /**
     * Retirer la quantité du stock agrégé (pour suppression ou annulation)
     */
    public function retirerDuStockAgrege(): void
    {
        try {
            // Vérifier que la fiche de livraison existe
            if (!$this->ficheLivraison) {
                Log::error('FicheLivraison non trouvée pour expédition ID: ' . $this->id);
                return;
            }

            // Récupérer la quantité reçue
            $quantiteRecue = $this->quantite_recue ?? $this->quantite_expediee;
            
            if ($quantiteRecue <= 0) {
                return;
            }

            // Chercher le stock agrégé pour ce type de matière
            $stock = StockADistiller::where('distilleur_id', $this->ficheLivraison->distilleur_id)
                ->where('type_matiere', $this->type_matiere)
                ->first();
            
            if (!$stock) {
                Log::warning('Stock agrégé non trouvé pour retrait - Distilleur: ' . 
                           $this->ficheLivraison->distilleur_id . ', Type: ' . $this->type_matiere);
                return;
            }

            // Vérifier qu'il y a assez de quantité à retirer
            if ($stock->quantite_initiale < $quantiteRecue) {
                Log::warning('Quantité insuffisante dans le stock pour retrait - Stock: ' . 
                           $stock->quantite_initiale . ', Demande: ' . $quantiteRecue);
                $quantiteRecue = $stock->quantite_initiale;
            }

            // Retirer la quantité
            $stock->quantite_initiale -= $quantiteRecue;
            
            // Si la quantité devient négative, mettre à zéro
            if ($stock->quantite_initiale < 0) {
                $stock->quantite_initiale = 0;
            }
            
            // Mettre à jour le statut si nécessaire
            if ($stock->quantite_initiale <= 0) {
                $stock->statut = 'epuise';
            } elseif ($stock->statut === 'epuise' && $stock->quantite_initiale > 0) {
                $stock->statut = 'disponible';
            }
            
            $stock->save();
            
            Log::info('Quantité retirée du stock agrégé - Expédition ID: ' . $this->id . 
                     ', Type: ' . $this->type_matiere . 
                     ', Quantité: ' . $quantiteRecue . 
                     ', Stock ID: ' . $stock->id . 
                     ', Nouvelle quantité: ' . $stock->quantite_initiale);

        } catch (\Exception $e) {
            Log::error('Erreur retrait du stock agrégé: ' . $e->getMessage() . 
                      ' - Trace: ' . $e->getTraceAsString() . 
                      ' - Expédition ID: ' . $this->id);
        }
    }

    /**
     * Obtenir le stock agrégé correspondant à cette expédition
     */
    public function stockAgrege()
    {
        if (!$this->ficheLivraison) {
            return null;
        }

        return StockADistiller::where('distilleur_id', $this->ficheLivraison->distilleur_id)
            ->where('type_matiere', $this->type_matiere)
            ->first();
    }

    /**
     * Vérifier si la quantité est disponible dans le stock agrégé
     */
    public function quantiteDisponibleDansStock(): float
    {
        $stock = $this->stockAgrege();
        
        if (!$stock) {
            return 0;
        }
        
        return $stock->quantite_restante;
    }

    /**
     * Obtenir les informations du stock agrégé formatées
     */
    public function getInfoStockAgregeAttribute(): array
    {
        $stock = $this->stockAgrege();
        
        if (!$stock) {
            return [
                'disponible' => false,
                'quantite_restante' => 0,
                'statut' => 'non_trouve'
            ];
        }
        
        return [
            'disponible' => $stock->estDisponible(),
            'quantite_restante' => $stock->quantite_restante,
            'quantite_initiale' => $stock->quantite_initiale,
            'quantite_utilisee' => $stock->quantite_utilisee,
            'statut' => $stock->statut,
            'taux_humidite_moyen' => $stock->taux_humidite_moyen,
            'taux_dessiccation_moyen' => $stock->taux_dessiccation_moyen,
            'stock_id' => $stock->id
        ];
    }

    /**
     * Relation avec la fiche de livraison
     */
    public function ficheLivraison(): BelongsTo
    {
        return $this->belongsTo(\App\Models\MatierePremiere\FicheLivraison::class, 'fiche_livraison_id');
    }

    /**
     * Relation avec le stock à distiller (ancienne relation - conservée pour compatibilité)
     */
    public function stockADistiller(): HasOne
    {
        return $this->hasOne(StockADistiller::class);
    }

    /**
     * Relation avec le stock agrégé
     */
    public function stockAgregeRelation()
    {
        if (!$this->ficheLivraison) {
            return null;
        }

        return $this->hasOne(StockADistiller::class, 'type_matiere', 'type_matiere')
            ->where('distilleur_id', $this->ficheLivraison->distilleur_id);
    }

    /**
     * Vérifier si l'expédition est en attente
     */
    public function estEnAttente(): bool
    {
        return $this->statut === 'en_attente';
    }

    /**
     * Vérifier si l'expédition est réceptionnée
     */
    public function estReceptionne(): bool
    {
        return $this->statut === 'receptionne';
    }

    /**
     * Marquer comme réceptionné (avec mise à jour du stock agrégé)
     */
    public function marquerCommeReceptionne(float $quantiteRecue = null): bool
    {
        try {
            // Mettre à jour l'expédition
            $this->update([
                'statut' => 'receptionne',
                'date_reception' => now()->format('Y-m-d'),
                'quantite_recue' => $quantiteRecue ?? $this->quantite_expediee
            ]);

            // Le boot() method s'occupera d'ajouter au stock agrégé
            return true;

        } catch (\Exception $e) {
            Log::error('Erreur marquage réception expédition: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Annuler la réception (retirer du stock agrégé)
     */
    public function annulerReception(): bool
    {
        try {
            // Vérifier que l'expédition est réceptionnée
            if (!$this->estReceptionne()) {
                return false;
            }

            // Retirer du stock agrégé
            $this->retirerDuStockAgrege();

            // Revenir à en_attente
            $this->update([
                'statut' => 'en_attente',
                'date_reception' => null,
                'quantite_recue' => null
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Erreur annulation réception expédition: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtenir l'historique des ajouts au stock
     */
    public function getHistoriqueStockAttribute(): array
    {
        $historique = [];
        
        // Information de base
        $historique[] = [
            'date' => $this->date_reception ?? $this->created_at,
            'type' => 'ajout',
            'quantite' => $this->quantite_recue ?? $this->quantite_expediee,
            'type_matiere' => $this->type_matiere,
            'source' => 'expedition',
            'source_id' => $this->id,
            'fiche_livraison_id' => $this->fiche_livraison_id,
            'livreur' => $this->ficheLivraison->livreur->nom_complet ?? 'Non défini'
        ];

        // Si on a un stock agrégé, ajouter son état actuel
        $stock = $this->stockAgrege();
        if ($stock) {
            $historique[] = [
                'date' => now(),
                'type' => 'etat_stock',
                'quantite_initiale' => $stock->quantite_initiale,
                'quantite_utilisee' => $stock->quantite_utilisee,
                'quantite_restante' => $stock->quantite_restante,
                'statut' => $stock->statut
            ];
        }

        return $historique;
    }

    /**
     * Accessor pour les informations complètes
     */
    public function getInformationsCompletesAttribute(): array
    {
        return [
            'expedition' => [
                'id' => $this->id,
                'type_matiere' => $this->type_matiere,
                'quantite_expediee' => $this->quantite_expediee,
                'quantite_recue' => $this->quantite_recue,
                'date_expedition' => $this->date_expedition,
                'date_reception' => $this->date_reception,
                'statut' => $this->statut,
                'lieu_depart' => $this->lieu_depart
            ],
            'fiche_livraison' => $this->ficheLivraison ? [
                'id' => $this->ficheLivraison->id,
                'distilleur' => [
                    'id' => $this->ficheLivraison->distilleur_id,
                    'nom_complet' => $this->ficheLivraison->distilleur->nom_complet ?? 'Non défini'
                ],
                'livreur' => [
                    'id' => $this->ficheLivraison->livreur_id,
                    'nom_complet' => $this->ficheLivraison->livreur->nom_complet ?? 'Non défini'
                ],
                'date_livraison' => $this->ficheLivraison->date_livraison
            ] : null,
            'stock_agrege' => $this->info_stock_agrege,
            'peut_distiller' => $this->info_stock_agrege['disponible'] && 
                               $this->info_stock_agrege['quantite_restante'] > 0
        ];
    }
}