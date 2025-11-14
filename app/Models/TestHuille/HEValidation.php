<?php

namespace App\Models\TestHuille;

use Illuminate\Database\Eloquent\Model;
use App\Models\TestHuille\FicheReception;
use App\Models\TestHuille\HETester;

class HEValidation extends Model
{
    protected $fillable = [
        'fiche_reception_id',
        'test_id',
        'decision',
        'poids_agreer',
        'observation_ecart_poids',
        'observation_generale'
    ];



    // Relations
    public function ficheReception()
    {
        return $this->belongsTo(FicheReception::class);
    }

    public function test()
    {
        return $this->belongsTo(HETester::class, 'test_id');
    }
}