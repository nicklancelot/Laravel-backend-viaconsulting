<?php

namespace App\Models\TestHuille;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\TestHuille\FicheReception;

class HEFicheLivraison extends Model
{
    use HasFactory;

    protected $fillable = [
        'fiche_reception_id',
        'date_heure_livraison',
        'nom_livreur',
        'prenom_livreur',
        'telephone_livreur',
        'numero_vehicule',
        'nom_destinataire',
        'prenom_destinataire',
        'fonction_destinataire',
        'telephone_destinataire',
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
}