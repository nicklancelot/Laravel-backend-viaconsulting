<?php

namespace App\Models\TestHuille;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class HETester extends Model
{
    use HasFactory;

    protected $fillable = [
        'fiche_reception_id',
        'date_test',
        'heure_debut',
        'heure_fin_prevue',
        'heure_fin_reelle',
        'densite',
        'presence_huile_vegetale',
        'presence_lookhead',
        'teneur_eau',
        'observations',
    ];

    public function ficheReception()
    {
        return $this->belongsTo(FicheReception::class);
    }
}