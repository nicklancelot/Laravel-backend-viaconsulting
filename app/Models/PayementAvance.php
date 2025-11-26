<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class PayementAvance extends Model
{
    protected $fillable = [
        'fournisseur_id',
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
        'raison'
    ];

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(\App\Models\MatierePremiere\Fournisseur::class);
    }

    // Vérifier si le paiement est en retard
    
    public function estEnRetard(): bool
    {
        if (!$this->delaiHeures) {
            return false;
        }
        $dateCreation = Carbon::parse($this->date);
        $dateLimite = $dateCreation->addMinutes($this->delaiHeures);
        
        return now()->greaterThan($dateLimite);
    }

    // Calculer le temps restant avant retard
 
    public function tempsRestant(): ?string
    {
        if (!$this->delaiHeures || $this->statut !== 'en_attente') {
            return null;
        }
        $dateCreation = Carbon::parse($this->date);
        $dateLimite = $dateCreation->addMinutes($this->delaiHeures);
        
        if (now()->greaterThan($dateLimite)) {
            return 'En retard';
        }

        $diff = now()->diff($dateLimite);
        return $diff->i . ' minutes ' . $diff->s . ' secondes';
    }

    
    // Scope pour les paiements en retard - CORRIGÉ
    
    public function scopeEnRetard($query)
    {
        return $query->where('statut', 'en_attente')
            ->where(function($q) {
                $q->whereRaw('DATE_ADD(date, INTERVAL delaiHeures MINUTE) < ?', [now()]);
            });
    }

    
    // Confirmer manuellement le paiement
     
    public function confirmer(): bool
    {
        if ($this->statut !== 'en_attente') {
            return false;
        }
        $this->statut = 'payé';
        return $this->save();
    }
}