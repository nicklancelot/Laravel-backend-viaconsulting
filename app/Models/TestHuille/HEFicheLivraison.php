<?php

namespace App\Models\TestHuille;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\TestHuille\FicheReception;
use App\Models\Livreur;
use App\Models\Destinateur;

class HEFicheLivraison extends Model
{
    use HasFactory;

    protected $fillable = [
        'fiche_reception_id',
        'livreur_id',
        'destinateur_id',
        'date_heure_livraison',
        'fonction_destinataire',
        'lieu_depart',
        'destination',
        'type_produit',
        'poids_net',
        'ristourne_regionale',
        'ristourne_communale'
    ];

    public function ficheReception()
    {
        return $this->belongsTo(FicheReception::class);
    }

    public function livreur()
    {
        return $this->belongsTo(Livreur::class);
    }

    public function destinateur()
    {
        return $this->belongsTo(Destinateur::class);
    }
}