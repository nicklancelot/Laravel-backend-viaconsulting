<?php

namespace App\Models\Distillation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class StockADistiller extends Model
{
    protected $table = 'stock_a_distillers';
    
    protected $fillable = [
        'distilleur_id',
        'type_matiere',
        'quantite_initiale',
        'quantite_utilisee',
        'taux_humidite_moyen',
        'taux_dessiccation_moyen',
        'numero_pv_reference',
        'statut',
        'observations'
    ];

    /**
     * Calculer la quantité restante (accessor)
     */
    public function getQuantiteRestanteAttribute(): float
    {
        return (float) $this->quantite_initiale - (float) $this->quantite_utilisee;
    }

    /**
     * Vérifier si disponible pour distillation
     */
    public function estDisponible(): bool
    {
        // Permettre d'utiliser le stock même s'il est en_distillation
        // Tant qu'il reste de la quantité
        return $this->quantite_restante > 0;
    }

    /**
     * Vérifier si on peut démarrer une nouvelle distillation
     */
    public function peutDemarrerNouvelleDistillation(): bool
    {
        return $this->quantite_restante > 0;
    }

    /**
     * Vérifier si en distillation
     */
    public function estEnDistillation(): bool
    {
        return $this->statut === 'en_distillation';
    }

    /**
     * Vérifier si épuisé
     */
    public function estEpuise(): bool
    {
        return $this->statut === 'epuise' || $this->quantite_restante <= 0;
    }

    /**
     * Ajouter une quantité depuis une expédition
     */
    public static function ajouterDepuisExpedition(Expedition $expedition): self
    {
        DB::beginTransaction();
        
        try {
            $distilleurId = $expedition->ficheLivraison->distilleur_id;
            $typeMatiere = $expedition->type_matiere;
            
            // Chercher ou créer le stock pour ce type de matière
            $stock = self::where('distilleur_id', $distilleurId)
                ->where('type_matiere', $typeMatiere)
                ->first();
            
            if (!$stock) {
                $stock = self::create([
                    'distilleur_id' => $distilleurId,
                    'type_matiere' => $typeMatiere,
                    'quantite_initiale' => 0,
                    'quantite_utilisee' => 0,
                    'statut' => 'disponible',
                    'numero_pv_reference' => $expedition->ficheLivraison->stockpv->numero_pv ?? 'PV-REF-' . date('Ymd'),
                    'observations' => 'Stock créé automatiquement'
                ]);
            }
            
            // Quantité reçue
            $quantiteRecue = $expedition->quantite_recue ?? $expedition->quantite_expediee;
            
            // Ajouter à la quantité initiale
            $stock->quantite_initiale += $quantiteRecue;
            
            // Mettre à jour les taux moyens
            $stock->mettreAJourTauxMoyens($expedition, $quantiteRecue);
            
            $stock->save();
            
            DB::commit();
            
            return $stock;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Mettre à jour les taux moyens (moyenne pondérée)
     */
    private function mettreAJourTauxMoyens(Expedition $expedition, float $quantiteRecue): void
    {
        $stockpv = $expedition->ficheLivraison->stockpv ?? null;
        
        if (!$stockpv) {
            return;
        }
        
        // Ancienne quantité (avant ajout)
        $ancienneQuantite = $this->quantite_initiale - $quantiteRecue;
        
        // Taux d'humidité
        if ($stockpv->taux_humidite !== null) {
            if ($ancienneQuantite <= 0) {
                $this->taux_humidite_moyen = $stockpv->taux_humidite;
            } else {
                $ancienTotal = $ancienneQuantite * ($this->taux_humidite_moyen ?? 0);
                $nouveauTotal = $quantiteRecue * $stockpv->taux_humidite;
                $this->taux_humidite_moyen = ($ancienTotal + $nouveauTotal) / $this->quantite_initiale;
            }
        }
        
        // Taux de dessiccation
        if ($stockpv->taux_dessiccation !== null) {
            if ($ancienneQuantite <= 0) {
                $this->taux_dessiccation_moyen = $stockpv->taux_dessiccation;
            } else {
                $ancienTotal = $ancienneQuantite * ($this->taux_dessiccation_moyen ?? 0);
                $nouveauTotal = $quantiteRecue * $stockpv->taux_dessiccation;
                $this->taux_dessiccation_moyen = ($ancienTotal + $nouveauTotal) / $this->quantite_initiale;
            }
        }
    }

    /**
     * Réserver une quantité pour distillation
     */
    public function reserverPourDistillation(float $quantite): bool
    {
        if ($quantite > $this->quantite_restante) {
            return false;
        }
        
        DB::beginTransaction();
        
        try {
            $this->quantite_utilisee += $quantite;
            
            // Toujours mettre en 'en_distillation' si on utilise une quantité
            $this->statut = 'en_distillation';
            
            // Seulement 'epuise' si complètement vide
            if ($this->quantite_restante <= 0) {
                $this->statut = 'epuise';
            }
            
            $this->save();
            
            DB::commit();
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }
    }

    /**
     * Libérer une quantité réservée (annulation)
     */
    public function libererQuantite(float $quantite): bool
    {
        if ($quantite > $this->quantite_utilisee) {
            return false;
        }
        
        DB::beginTransaction();
        
        try {
            $this->quantite_utilisee -= $quantite;
            
            // Revenir à disponible si de la quantité est libérée et il reste du stock
            if ($this->quantite_restante > 0) {
                $this->statut = 'disponible';
            } else {
                $this->statut = 'epuise';
            }
            
            $this->save();
            
            DB::commit();
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            return false;
        }
    }

    /**
     * Relation avec le distilleur
     */
    public function distilleur(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Utilisateur::class, 'distilleur_id');
    }

    /**
     * Obtenir les informations formatées pour l'API
     */
    public function formatPourApi(): array
    {
        return [
            'id' => $this->id,
            'type_matiere' => $this->type_matiere,
            'quantite_initiale' => $this->quantite_initiale,
            'quantite_utilisee' => $this->quantite_utilisee,
            'quantite_restante' => $this->quantite_restante,
            'taux_humidite_moyen' => $this->taux_humidite_moyen,
            'taux_dessiccation_moyen' => $this->taux_dessiccation_moyen,
            'statut' => $this->statut,
            'peut_distiller' => $this->peutDemarrerNouvelleDistillation(),
            'est_disponible' => $this->estDisponible(),
            'est_en_distillation' => $this->estEnDistillation(),
            'est_epuise' => $this->estEpuise(),
            'numero_pv_reference' => $this->numero_pv_reference,
            'distilleur' => [
                'id' => $this->distilleur_id,
                'nom_complet' => $this->distilleur->nom . ' ' . $this->distilleur->prenom
            ]
        ];
    }
}