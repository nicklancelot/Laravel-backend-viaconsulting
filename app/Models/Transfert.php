<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transfert extends Model
{
    protected $fillable = [
        'admin_id',
        'destinataire_id',
        'montant',
        'type_transfert',
        'reference',
        'raison'
    ];

  

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'admin_id');
    }

    public function destinataire(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'destinataire_id');
    }
}