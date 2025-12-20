<?php

namespace App\Models\Distillation;

use App\Models\Livreur;
use App\Models\Utilisateur;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transport extends Model
{
    protected $fillable = [
        'distillation_id',
        'vendeur_id',
        'livreur_id',
        'date_transport',
        'lieu_depart',
        'site_destination',
        'type_matiere',
        'quantite_a_livrer',
        'ristourne_regionale',
        'ristourne_communale',
        'observations',
        'statut',
        'date_livraison'
    ];

    /**
     * Boot method pour créer automatiquement les réceptions
     */
    protected static function boot()
    {
        parent::boot();

        // Créer une réception automatique quand le transport est marqué comme livré
        static::updated(function ($transport) {
            if ($transport->isDirty('statut') && $transport->estLivre()) {
                $transport->creerReceptionAutomatique();
            }
        });
    }

    /**
     * Créer une réception automatique
     */
    public function creerReceptionAutomatique(): void
    {
        try {
            \App\Models\Vente\Reception::create([
                'transport_id' => $this->id, // Nouveau champ
                'vendeur_id' => $this->vendeur_id,
                'date_reception' => now()->toDateString(),
                'heure_reception' => now()->format('H:i'),
                'statut' => 'en attente',
                'quantite_recue' => $this->quantite_a_livrer,
                'lieu_reception' => $this->site_destination,
                'type_livraison' => 'transport', // Pour distinguer transport vs fiche_livraison
                'observations' => 'Réception automatique créée après transport'
            ]);
        } catch (\Exception $e) {
            \Log::error('Erreur création réception transport: ' . $e->getMessage());
        }
    }

    /**
     * Relation avec la distillation
     */
    public function distillation(): BelongsTo
    {
        return $this->belongsTo(Distillation::class);
    }

    /**
     * Relation avec le livreur
     */
    public function livreur(): BelongsTo
    {
        return $this->belongsTo(Livreur::class);
    }

    /**
     * Relation avec le vendeur
     */
    public function vendeur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'vendeur_id');
    }

    /**
     * Marquer comme livré (et créer automatiquement une réception)
     */
    public function marquerLivre(string $observations = null): void
    {
        $this->update([
            'statut' => 'livre',
            'date_livraison' => now()->format('Y-m-d'),
            'observations' => $observations ?? $this->observations
        ]);
    }

    /**
     * Vérifier si le transport est en cours
     */
    public function estEnCours(): bool
    {
        return $this->statut === 'en_cours';
    }

    /**
     * Vérifier si le transport est livré
     */
    public function estLivre(): bool
    {
        return $this->statut === 'livre';
    }

    /**
     * Obtenir le nom complet du vendeur
     */
    public function getVendeurNomCompletAttribute(): string
    {
        return $this->vendeur->nom . ' ' . $this->vendeur->prenom;
    }

    /**
     * Obtenir le nom complet du livreur
     */
    public function getLivreurNomCompletAttribute(): string
    {
        return $this->livreur->nom . ' ' . $this->livreur->prenom;
    }
}