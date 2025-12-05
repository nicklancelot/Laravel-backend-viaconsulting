<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandeSolde extends Model
{
    protected $fillable = [
        'utilisateur_id',
        'montant_demande',
        'raison',
        'statut',
        'admin_id',
        'commentaire_admin',
        'date'
    ];



    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'admin_id');
    }

    // Scope pour les demandes en attente
    public function scopeEnAttente($query)
    {
        return $query->where('statut', 'en_attente');
    }

    // Scope pour les demandes approuvées
    public function scopeApprouvees($query)
    {
        return $query->where('statut', 'approuvee');
    }

    // Scope pour les demandes rejetées
    public function scopeRejetees($query)
    {
        return $query->where('statut', 'rejetee');
    }
}