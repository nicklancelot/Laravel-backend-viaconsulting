<?php

namespace App\Models;

use App\Models\MatierePremiere\PVReception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Utilisateur extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'nom',
        'prenom',
        'numero',
        'localisation_id',
        'site_collecte_id', 
        'CIN',
        'role',
        'password',
        'code_collecteur'
        
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function localisation(): BelongsTo
    {
        return $this->belongsTo(Localisation::class);
    }

    // Ajout de la relation avec site_collecte
    public function siteCollecte(): BelongsTo
    {
        return $this->belongsTo(SiteCollecte::class, 'site_collecte_id');
    }

   
}