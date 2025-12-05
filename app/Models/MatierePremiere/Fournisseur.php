<?php

namespace App\Models\MatierePremiere;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;

class Fournisseur extends Model
{
    use HasFactory;

    protected $fillable = [
        'nom',
        'prenom',
        'adresse',
        'identification_fiscale',
        'localisation_id',
        'contact',
        'utilisateur_id' 
    ];

    public function localisation(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Localisation::class);
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Utilisateur::class);
    }

    // Nouveau scope pour filtrer par utilisateur selon le rôle
    public function scopeForUser($query, $user)
    {
        if ($user->role === 'admin') {
            // L'admin voit tout
            return $query;
        }
        
        // Les autres utilisateurs ne voient que leurs propres fournisseurs
        return $query->where('utilisateur_id', $user->id);
    }
    // Dans App\Models\MatierePremiere\Fournisseur
public function pvReceptions()
{
    return $this->hasMany(\App\Models\MatierePremiere\PVReception::class, 'fournisseur_id');
}
}