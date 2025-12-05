<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class PayementAvance extends Model
{
    protected $fillable = [
        'fournisseur_id',
        'pv_reception_id', 
        'fiche_reception_id', // Ajouté
        'date_utilisation', 
        'montant',
        'date',
        'statut',
        'methode',
        'reference',
        'type',
        'description',
        'montantDu',
        'montantAvance',
        'delaiHeures',
        'raison',
        'montant_utilise', 
        'montant_restant',
    ];

    protected $appends = ['est_en_retard', 'temps_restant'];

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(\App\Models\MatierePremiere\Fournisseur::class);
    }

    public function pvReception(): BelongsTo
    {
        return $this->belongsTo(\App\Models\MatierePremiere\PVReception::class);
    }

    public function ficheReception(): BelongsTo // Nouvelle relation
    {
        return $this->belongsTo(\App\Models\TestHuille\FicheReception::class);
    }

    /** ACCESSEUR est_en_retard */
    public function getEstEnRetardAttribute(): bool
    {
        if (!$this->delaiHeures) {
            return false;
        }

        $dateCreation = Carbon::parse($this->date);
        $dateLimite = $dateCreation->copy()->addHours($this->delaiHeures);

        return now()->greaterThan($dateLimite);
    }

    /** ACCESSEUR temps_restant */
    public function getTempsRestantAttribute(): ?string
    {
        if (!$this->delaiHeures || $this->statut !== 'en_attente') {
            return null;
        }

        $dateCreation = Carbon::parse($this->date);
        $dateLimite = $dateCreation->copy()->addHours($this->delaiHeures);

        if (now()->greaterThan($dateLimite)) {
            return 'En retard';
        }

        $diff = now()->diff($dateLimite);

        if ($diff->d > 0) {
            return $diff->d . ' jours ' . $diff->h . ' heures';
        } elseif ($diff->h > 0) {
            return $diff->h . ' heures ' . $diff->i . ' minutes';
        } else {
            return $diff->i . ' minutes';
        }
    }

    /** Méthode estEnRetard originale */
    public function estEnRetard(): bool
    {
        return $this->getEstEnRetardAttribute();
    }

    public function tempsRestant(): ?string
    {
        return $this->getTempsRestantAttribute();
    }

    /** SCOPE : paiements en retard */
    public function scopeEnRetard($query)
    {
        return $query->where('statut', 'en_attente')
            ->whereRaw('DATE_ADD(date, INTERVAL delaiHeures HOUR) < ?', [now()]);
    }

    public function marquerCommeUtilise($pvReceptionId = null, $ficheReceptionId = null): bool
    {
        $updateData = [
            'date_utilisation' => now(),
            'statut' => 'utilise' 
        ];

        if ($pvReceptionId) {
            $updateData['pv_reception_id'] = $pvReceptionId;
        }

        if ($ficheReceptionId) {
            $updateData['fiche_reception_id'] = $ficheReceptionId;
        }

        return $this->update($updateData);
    }

    public function scopeDisponibles($query)
    {
        return $query->where('statut', 'arrivé')
                    ->whereNull('pv_reception_id')
                    ->whereNull('fiche_reception_id');
    }

    public function estDisponible(): bool
    {
        return $this->statut === 'arrivé' && 
               is_null($this->pv_reception_id) && 
               is_null($this->fiche_reception_id);
    }
}