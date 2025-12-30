<?php

namespace App\Models\Distilleur;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Utilisateur;

class Historique extends Model
{
    protected $fillable = [
        'utilisateur_id',
        'type_operation',
        'montant',
        'solde_avant',
        'solde_apres',
        'motif',
        'reference',
        'statut'
    ];

    protected $casts = [
        'montant' => 'decimal:2',
        'solde_avant' => 'decimal:2',
        'solde_apres' => 'decimal:2'
    ];

    /**
     * Relation avec l'utilisateur
     */
    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class);
    }

    /**
     * Générer une référence unique
     */
    public static function generateReference(): string
    {
        return 'HIS' . time() . rand(1000, 9999);
    }
}