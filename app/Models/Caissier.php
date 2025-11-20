<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Caissier extends Model
{
    protected $fillable = [
        'utilisateur_id',
        'solde',
        'date',
        'montant',
        'type',
        'raison',
        'methode',
        'reference'
    ];



    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class);
    }
}