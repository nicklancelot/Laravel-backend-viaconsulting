<?php

namespace App\Models\TestHuille;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\MatierePremiere\Fournisseur;
use App\Models\SiteCollecte;
use App\Models\Utilisateur;
use Illuminate\Database\Eloquent\Builder;

class FicheReception extends Model
{
    use HasFactory;

    protected $fillable = [
        'numero_document',
        'date_reception',
        'heure_reception',
        'fournisseur_id',
        'site_collecte_id',
        'utilisateur_id',
        'poids_brut',
        'poids_agreer',
        'taux_humidite',
        'taux_dessiccation', 
        'poids_net', 
        'statut',
        'type_emballage',
        'poids_emballage',
        'nombre_colisage',
        'prix_unitaire',
        'prix_total',
            'quantite_totale',
        'quantite_restante'
      
    ];

    // Relations
public function ficheLivraison()
{
    return $this->hasOne(HEFicheLivraison::class)->latest();
}
    
    public function fournisseur()
    {
        return $this->belongsTo(Fournisseur::class);
    }

    public function siteCollecte()
    {
        return $this->belongsTo(SiteCollecte::class);
    }

    public function utilisateur() 
    {
        return $this->belongsTo(Utilisateur::class);
    }

    public function tests()
    {
        return $this->hasMany(HETester::class, 'fiche_reception_id');
    }

    public function validations()
    {
        return $this->hasMany(HEValidation::class, 'fiche_reception_id');
    }

    public function dernierTest()
    {
        return $this->hasOne(HETester::class, 'fiche_reception_id')->latest();
    }

  
    public function scopeForUser(Builder $query, $user)
    {
        if ($user->role === 'admin') {
            return $query;
        }
        return $query->where('utilisateur_id', $user->id);
    }
}