<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Livreur extends Model
{
    protected $fillable = [
        'nom',
        'prenom',
        'cin',
        'date_naissance',
        'lieu_naissance',
        'date_delivrance_cin',
        'contact_famille',
        'telephone',
        'numero_vehicule',
        'observation',
        'zone_livraison',
        'created_by'
    ];



    public function createur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'created_by');
    }
}