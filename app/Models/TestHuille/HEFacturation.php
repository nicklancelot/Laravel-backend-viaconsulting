<?php

namespace App\Models\TestHuille;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\TestHuille\FicheReception;

class HEFacturation extends Model
{
    use HasFactory;

    protected $fillable = [
        'fiche_reception_id',
        'prix_unitaire',
        'montant_total',
        'avance_versee',
        'reste_a_payer',
        'controller_qualite',
        'responsable_commercial'
    ];


    public function ficheReception()
    {
        return $this->belongsTo(FicheReception::class);
    }
}