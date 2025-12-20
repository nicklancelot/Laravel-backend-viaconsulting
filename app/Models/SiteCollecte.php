<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteCollecte extends Model
{
    protected $fillable = ['Nom'];
    
    protected $table = 'site_collectes';
    
    /**
     * Relation avec les utilisateurs
     */
    public function utilisateurs(): HasMany
    {
        return $this->hasMany(Utilisateur::class, 'site_collecte_id');
    }
}