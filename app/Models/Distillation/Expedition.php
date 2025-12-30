<?php

namespace App\Models\Distillation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
     * Boot method pour créer automatiquement la distillation
     */
    protected static function boot()
    {
        parent::boot();

        // Créer une distillation automatique quand l'expédition est marquée comme réceptionnée
        static::updated(function ($expedition) {
            if ($expedition->isDirty('statut') && $expedition->estReceptionne()) {
                $expedition->creerDistillationAutomatique();
            }
        });
    }

    /**
     * Créer une distillation automatique
     */
   public function creerDistillationAutomatique(): void
{
    try {
        // Vérifier qu'il n'y a pas déjà une distillation
        $distillationExistante = Distillation::where('expedition_id', $this->id)->first();
        if ($distillationExistante) {
            return;
        }

        // Récupérer les informations du site
        $siteNom = 'SITE_INCONNU';
        $siteCode = 'SIT';
        
        if ($this->ficheLivraison->distilleur->siteCollecte) {
            $siteNom = $this->ficheLivraison->distilleur->siteCollecte->Nom;
            $siteCode = strtoupper(str_replace(' ', '', substr($siteNom, 0, 3)));
        } elseif ($this->lieu_depart) {
            $siteNom = $this->lieu_depart;
            $siteCode = strtoupper(str_replace(' ', '', substr($this->lieu_depart, 0, 3)));
        } elseif ($this->ficheLivraison->lieu_depart) {
            $siteNom = $this->ficheLivraison->lieu_depart;
            $siteCode = strtoupper(str_replace(' ', '', substr($this->ficheLivraison->lieu_depart, 0, 3)));
        }

        // Générer le numéro de PV
        $date = now()->format('Ymd');
        
        // Compter les distillations existantes aujourd'hui
        $distillationCountToday = Distillation::whereDate('created_at', now()->toDateString())->count();
        
        // Générer le numéro de PV
        $numeroPv = "PV-" . $siteCode . "-" . $date . "-" . str_pad(($distillationCountToday + 1), 3, '0', STR_PAD_LEFT);
        
        // Récupérer les données du stockpv
        $stockpv = $this->ficheLivraison->stockpv ?? null;
        
        // Déterminer le type de matière première
        $typeMatiere = $this->type_matiere ?? 
                      ($stockpv->type_matiere ?? 
                      ($stockpv->type ?? 'Non spécifié'));
        
        // Déterminer les taux d'humidité et dessiccation
        $tauxHumidite = $stockpv->taux_humidite ?? null;
        $tauxDessiccation = $stockpv->taux_dessiccation ?? null;

        // Créer la distillation
        Distillation::create([
            'expedition_id' => $this->id,
            'statut' => 'en_attente',
            'numero_pv' => $numeroPv,
            'type_matiere_premiere' => $typeMatiere,
            'quantite_recue' => $this->quantite_recue ?? $this->quantite_expediee,
            'taux_humidite' => $tauxHumidite,
            'taux_dessiccation' => $tauxDessiccation,
            'observations' => 'Distillation créée automatiquement après réception d\'expédition - Site: ' . $siteNom
        ]);

    } catch (\Exception $e) {
        \Log::error('Erreur création distillation automatique: ' . $e->getMessage());
    }
}

    /**
     * Relation avec la fiche de livraison
     */
    public function ficheLivraison(): BelongsTo
    {
        return $this->belongsTo(\App\Models\MatierePremiere\FicheLivraison::class, 'fiche_livraison_id');
    }

    /**
     * Relation avec la distillation
     */
    public function distillation(): HasOne
    {
        return $this->hasOne(Distillation::class);
    }

    /**
     * Accès au stockpv via la fiche de livraison
     */
    public function getStockpvAttribute()
    {
        return $this->ficheLivraison->stockpv ?? null;
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
     * Marquer comme réceptionné
     */
    public function marquerCommeReceptionne(float $quantiteRecue = null): void
    {
        $this->update([
            'statut' => 'receptionne',
            'date_reception' => now()->format('Y-m-d'),
            'quantite_recue' => $quantiteRecue ?? $this->quantite_expediee
        ]);
    }

    /**
     * Vérifier si l'expédition appartient à un distilleur
     */
    public function appartientADistilleur($distilleurId): bool
    {
        return $this->ficheLivraison && $this->ficheLivraison->distilleur_id == $distilleurId;
    }
}