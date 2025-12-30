<?php

namespace App\Models\Vente;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    /** @use HasFactory<\Database\Factories\Vente\ClientFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     * Only 'nom_entreprise' is required by the frontend; others are nullable.
     *
     * @var array<int,string>
     */
    protected $fillable = [
        'nom_entreprise',
        'utilisateur_id',
        'nom_client',
        'prenom_client',
        'telephone',
        'email',
        'rue_numero',
        'quartier_lot',
        'ville',
        'code_postal',
        'informations',
    ];

    /**
     * Owner (vendeur) relationship
     */
    public function utilisateur()
    {
        return $this->belongsTo(\App\Models\Utilisateur::class, 'utilisateur_id');
    }
}
