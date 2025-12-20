<?php

namespace App\Models\MatierePremiere;

use App\Models\Utilisateur;
use App\Models\Livreur;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FicheLivraison extends Model
{
    protected $fillable = [
        'stockpvs_id',
        'livreur_id',
        'distilleur_id',
        'date_livraison',
        'lieu_depart',
        'ristourne_regionale',
        'ristourne_communale',
        'quantite_a_livrer',
    ];

    /**
     * Boot method pour créer automatiquement l'expédition
     */
    protected static function boot()
    {
        parent::boot();

        static::created(function ($ficheLivraison) {
            $ficheLivraison->creerExpeditionAutomatique();
        });
    }

    /**
     * Créer une expédition automatique
     */
    public function creerExpeditionAutomatique(): void
    {
        try {
            // Charger la relation stockpv si ce n'est pas déjà fait
            if (!$this->relationLoaded('stockpv')) {
                $this->load('stockpv');
            }
            
            // Déterminer le type de matière
            $typeMatiere = 'Non spécifié';
            if ($this->stockpv && isset($this->stockpv->type_matiere)) {
                $typeMatiere = $this->stockpv->type_matiere;
            }
            
            // Créer l'expédition
            \App\Models\Distillation\Expedition::create([
                'fiche_livraison_id' => $this->id,
                'statut' => 'en_attente',
                'date_expedition' => now()->format('Y-m-d'),
                'quantite_expediee' => $this->quantite_a_livrer,
                'type_matiere' => $typeMatiere,
                'lieu_depart' => $this->lieu_depart,
                'observations' => 'Expédition automatique créée après livraison'
            ]);
            
            \Log::info('Expédition créée automatiquement pour fiche ID: ' . $this->id);
            
        } catch (\Exception $e) {
            \Log::error('Erreur création expédition automatique pour fiche ID ' . $this->id . ': ' . $e->getMessage());
            \Log::error('Trace: ' . $e->getTraceAsString());
        }
    }

    public function stockpv(): BelongsTo
    {
        return $this->belongsTo(Stockpv::class, 'stockpvs_id');
    }

    public function livreur(): BelongsTo
    {
        return $this->belongsTo(Livreur::class);
    }

    public function distilleur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'distilleur_id')->with('siteCollecte');
    }

    // Relation avec l'expédition
    public function expedition(): HasOne
    {
        return $this->hasOne(\App\Models\Distillation\Expedition::class, 'fiche_livraison_id');
    }

    // Accès au site de collecte via le distilleur
    public function getSiteCollecteAttribute()
    {
        return $this->distilleur->siteCollecte ?? null;
    }

    /**
     * Vérifier si une expédition existe déjà
     */
    public function aUneExpedition(): bool
    {
        return $this->expedition()->exists();
    }
}