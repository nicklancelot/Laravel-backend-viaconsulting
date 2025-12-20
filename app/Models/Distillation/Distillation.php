<?php

namespace App\Models\Distillation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\Distillation\Expedition;
use App\Models\Distillation\Transport; 

class Distillation extends Model
{
    protected $fillable = [
        'expedition_id',
        'statut',
        'numero_pv',
        'type_matiere_premiere',
        'quantite_recue',
        'taux_humidite',
        'taux_dessiccation',
        'id_ambalic',
        'date_debut',
        'poids_distiller',
        'usine',
        'duree_distillation',
        'poids_chauffage',
        'carburant',
        'main_oeuvre',
        'reference',
        'matiere',
        'site',
        'quantite_traitee',
        'date_fin',
        'type_he',
        'quantite_resultat',
        'observations'
    ];

    /**
     * Relation avec l'expédition
     */
    public function expedition(): BelongsTo
    {
        return $this->belongsTo(Expedition::class);
    }

    /**
     * Relation avec le transport (AJOUT IMPORTANT)
     */
    public function transport(): HasOne
    {
        return $this->hasOne(Transport::class);
    }

    /**
     * Démarrer la distillation
     */
    public function demarrer(array $donneesDemarrage)
    {
        $this->update(array_merge($donneesDemarrage, [
            'statut' => 'en_cours'
        ]));

        return $this;
    }

    /**
     * Terminer la distillation
     */
    public function terminer(array $donneesFin)
    {
        $this->update(array_merge($donneesFin, [
            'statut' => 'termine'
        ]));

        return $this;
    }

    /**
     * Vérifier si la distillation est en attente
     */
    public function estEnAttente(): bool
    {
        return $this->statut === 'en_attente';
    }

    /**
     * Vérifier si la distillation est en cours
     */
    public function estEnCours(): bool
    {
        return $this->statut === 'en_cours';
    }

    /**
     * Vérifier si la distillation est terminée
     */
    public function estTerminee(): bool
    {
        return $this->statut === 'termine';
    }

    /**
     * Calculer le rendement
     */
    public function getRendementAttribute(): float
    {
        if ($this->quantite_traitee > 0 && $this->quantite_resultat > 0) {
            return ($this->quantite_resultat / $this->quantite_traitee) * 100;
        }
        return 0;
    }

    /**
     * Formater le rendement
     */
    public function getRendementFormateAttribute(): string
    {
        return number_format($this->rendement, 2) . ' %';
    }
}