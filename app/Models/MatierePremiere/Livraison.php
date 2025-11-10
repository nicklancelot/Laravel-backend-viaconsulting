<?php

namespace App\Models\MatierePremiere;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Livraison extends Model
{
    protected $fillable = [
        'fiche_livraison_id',
        'date_confirmation_livraison'
    ];

    // Relation avec FicheLivraison
    public function ficheLivraison(): BelongsTo
    {
        return $this->belongsTo(FicheLivraison::class);
    }
}