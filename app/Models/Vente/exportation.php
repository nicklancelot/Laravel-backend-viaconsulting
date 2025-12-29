<?php

namespace App\Models\Vente;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Exportation extends Model
{
    use HasFactory;

    protected $table = 'exportations';

    protected $fillable = [
        'numero_contrat',
        'date_contrat',
        'produit',
        'poids',
        'designation',
        'prix_unitaire',
        'prix_total',
        'frais_transport',
        'client_id',
        'devis_path',
        'proforma_path',
        'phytosanitaire_path',
        'eaux_forets_path',
        'mise_fob_cif_path',
        'livraison_transitaire_path',
        'transmission_documents_path',
        'recouvrement_path',
        'piece_justificative_path',
        'montant_encaisse',
        'commentaires',
        'utilisateur_id',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function utilisateur()
    {
        return $this->belongsTo(\App\Models\Utilisateur::class, 'utilisateur_id');
    }
}
