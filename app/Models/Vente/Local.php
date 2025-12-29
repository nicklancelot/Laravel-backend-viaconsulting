<?php

namespace App\Models\Vente;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Local extends Model
{
    /** @use HasFactory<\Database\Factories\Vente\LocalFactory> */
    use HasFactory;

    protected $table = 'locals';

    protected $fillable = [
        'numero_contrat',
        'date_contrat',
        'produit',
        'client_id',
        'test_qualite_path',
        'date_livraison_prevue',
        'produit_bon_livraison',
        'poids_bon_livraison',
        'destinataires',
        'livraison_client_path',
        'agreage_client_path',
        'recouvrement_path',
        'piece_justificative_path',
        'montant_encaisse',
        'commentaires',
        'utilisateur_id',
    ];

    public function client()
    {
        return $this->belongsTo(\App\Models\Vente\Client::class, 'client_id');
    }

    public function utilisateur()
    {
        return $this->belongsTo(\App\Models\Utilisateur::class, 'utilisateur_id');
    }
}
