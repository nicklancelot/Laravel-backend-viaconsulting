<?php

namespace App\Models\MatierePremiere;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FicheLivraison extends Model
{
    use HasFactory;

    protected $fillable = [
        'pv_reception_id',
        'livreur_id',
        'destinateur_id',
        'date_livraison',
        'lieu_depart',
        'ristourne_regionale',
        'ristourne_communale',
        'quantite_a_livrer',
        'quantite_restante',
        'est_partielle'
    ];

       protected static function boot()
    {
        parent::boot();

        // Calcul automatique avant création
        static::creating(function ($fiche) {
            $pv = $fiche->pvReception;
            $fiche->est_partielle = $fiche->quantite_a_livrer < $pv->quantite_restante;
            $fiche->quantite_restante = $fiche->quantite_a_livrer;
        });
    }


    public function pvReception()
    {
        return $this->belongsTo(PVReception::class);
    }

    public function livraison()
    {
        return $this->hasOne(Livraison::class);
    }
        public function livreur(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Livreur::class);
    }

    public function destinateur(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Destinateur::class);
    }

    // NOUVELLE méthode pour calculer le reste à livrer
    public function getResteALivrerAttribute()
    {
        return $this->quantite_restante;
    }

    // NOUVELLE méthode pour vérifier si complètement livré
    public function getEstCompletementLivreeAttribute()
    {
        return $this->quantite_restante == 0;
    }
    
     // MÉTHODE POUR CONFIRMER UNE LIVRAISON (PARTIELLE OU TOTALE)
    public function confirmerLivraison($quantiteEffectivementLivree = null)
    {
        $quantiteLivree = $quantiteEffectivementLivree ?? $this->quantite_a_livrer;
        
        // Mettre à jour la quantité restante de la fiche
        $this->quantite_restante = max(0, $this->quantite_a_livrer - $quantiteLivree);
        
        // Mettre à jour le PV réception
        $this->pvReception->deduireQuantiteLivree($quantiteLivree);
        
        $this->save();
        
        return $this;
    }

    // Accesseur pour la quantité effectivement livrée
    public function getQuantiteLivreeAttribute()
    {
        return $this->quantite_a_livrer - $this->quantite_restante;
    }


    // Accesseur pour le pourcentage livré de cette fiche
    public function getPourcentageLivreeAttribute()
    {
        if ($this->quantite_a_livrer == 0) return 0;
        return (($this->quantite_a_livrer - $this->quantite_restante) / $this->quantite_a_livrer) * 100;
    }

    
}