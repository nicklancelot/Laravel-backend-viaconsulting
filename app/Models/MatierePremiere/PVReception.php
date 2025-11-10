<?php

namespace App\Models\MatierePremiere;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;

class PVReception extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'numero_doc',
        'date_reception',
        'dette_fournisseur',
        'utilisateur_id',
        'fournisseur_id',
        'localisation_id',
        'poids_brut',
        'type_emballage',
        'poids_emballage',
        'poids_net',
        'nombre_colisage',
        'prix_unitaire',
        'taux_humidite',
        'taux_dessiccation',
        'prix_total',
        'quantite_totale',
        'quantite_restante',
        'statut'
    ];

        protected static function boot()
    {
        parent::boot();

        // Calcul automatique de la quantité totale avant création
        static::creating(function ($pv) {
            $pv->quantite_totale = $pv->nombre_colisage * $pv->poids_emballage;
            $pv->quantite_restante = $pv->quantite_totale;
        });

        // Recalcul après modification
        static::updating(function ($pv) {
            if ($pv->isDirty(['nombre_colisage', 'poids_emballage'])) {
                $pv->quantite_totale = $pv->nombre_colisage * $pv->poids_emballage;
                
                // Ajuster la quantité restante proportionnellement
                if ($pv->getOriginal('quantite_totale') > 0) {
                    $ratio = $pv->quantite_totale / $pv->getOriginal('quantite_totale');
                    $pv->quantite_restante = $pv->getOriginal('quantite_restante') * $ratio;
                }
            }
        });
    }
 
    public function mettreAJourStatutLivraison()
    {
        if ($this->quantite_restante <= 0) {
            $this->statut = 'livree';
        } elseif ($this->quantite_restante < $this->quantite_totale) {
            $this->statut = 'partiellement_livree'; // NOUVEAU statut
        } elseif ($this->statut === 'en_attente_livraison') {
            // Garder le statut existant
            $this->statut = 'en_attente_livraison';
        }
        
        $this->save();
    }




    // Relations
    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Utilisateur::class);
    }

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(\App\Models\MatierePremiere\Fournisseur::class);
    }

    public function localisation(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Localisation::class);
    }

    // Scopes
    public function scopeFeuilles($query)
    {
        return $query->where('type', 'FG');
    }

    public function scopeClous($query)
    {
        return $query->where('type', 'CG');
    }

    public function scopeGriffes($query)
    {
        return $query->where('type', 'GG');
    }

    public function scopeNonPayes($query)
    {
        return $query->where('statut', 'non_paye');
    }

    // filtrer par utilisateur selon le rôle
    public function scopeForUser($query, $user)
    {
        if ($user->role === 'admin') {
            return $query;
        }
        return $query->where('utilisateur_id', $user->id);
    }

    // Méthodes de statut
    public function estNonPaye(): bool
    {
        return $this->statut === 'non_paye';
    }

    // Méthode pour calculer le poids net
    public function calculerPoidsNet(): float
    {
        if ($this->type === 'CG') {
            // Pour CG: poids_net = (poids_brut - poids_emballage) * (1 - taux_dessiccation/100)
            $poidsSansEmballage = $this->poids_brut - $this->poids_emballage;
            $dessiccation = $poidsSansEmballage * ($this->taux_dessiccation / 100);
            return $poidsSansEmballage - $dessiccation;
        }
        
        // Pour FG et GG: poids_net = poids_brut - poids_emballage
        return $this->poids_brut - $this->poids_emballage;
    }

    // Méthode pour calculer le prix total
    public function calculerPrixTotal(): float
    {
        return $this->poids_net * $this->prix_unitaire;
    }

    // Méthode pour calculer la dette automatiquement
    public function calculerDetteFournisseur(): float
    {
        // La dette est égale au prix total par défaut
        return $this->prix_total;
    }

  public function getEstCompletementLivreeAttribute()
    {
        return $this->quantite_restante <= 0;
    }




        public function getStockRestantAttribute()
    {
        $quantiteTotale = $this->nombre_colisage * $this->poids_emballage;
        
        // Vérifier si la relation ficheLivraisons existe et n'est pas null
        if ($this->ficheLivraisons && $this->ficheLivraisons->isNotEmpty()) {
            $quantiteLivree = $this->ficheLivraisons->sum(function($fiche) {
                return $fiche->quantite_a_livrer - ($fiche->quantite_restante ?? 0);
            });
        } else {
            $quantiteLivree = 0;
        }
        
        return max(0, $quantiteTotale - $quantiteLivree);
    }

 

    // Assurez-vous que la relation existe
    public function ficheLivraisons()
    {
        return $this->hasMany(FicheLivraison::class);
    }


      // MÉTHODE POUR DÉDUIRE LA QUANTITÉ LIVRÉE
    public function deduireQuantiteLivree($quantiteLivree)
    {
        $this->quantite_restante = max(0, $this->quantite_restante - $quantiteLivree);
        $this->mettreAJourStatutLivraison();
    }

    

    // Accesseur pour savoir si complètement livré
  

    // Accesseur pour savoir si partiellement livré
    public function getEstPartiellementLivreeAttribute()
    {
        return $this->quantite_restante > 0 && $this->quantite_restante < $this->quantite_totale;
    }

    // Accesseur pour le pourcentage livré
    public function getPourcentageLivreeAttribute()
    {
        if ($this->quantite_totale == 0) return 0;
        return (($this->quantite_totale - $this->quantite_restante) / $this->quantite_totale) * 100;
    }

}