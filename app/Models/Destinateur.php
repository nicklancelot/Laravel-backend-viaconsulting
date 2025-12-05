<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Destinateur extends Model
{
    protected $fillable = [
        'nom_entreprise',
        'nom_prenom',
        'contact',
        'observation',
        'created_by'
    ];

    public function createur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'created_by');
    }
}