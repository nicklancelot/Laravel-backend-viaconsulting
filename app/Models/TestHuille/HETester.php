<?php

namespace App\Models\TestHuille;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\TestHuille\FicheReception;
use Illuminate\Support\Carbon;

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
        'test_expires_at'
    ];

 

    public function ficheReception()
    {
        return $this->belongsTo(FicheReception::class);
    }

}