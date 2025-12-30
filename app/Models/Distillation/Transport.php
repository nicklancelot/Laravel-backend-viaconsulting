<?php

namespace App\Models\Distillation;

use App\Models\Livreur;
use App\Models\Utilisateur;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

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

        // Quand un transport est créé
        static::created(function ($transport) {
            try {
                // 1. Mettre à jour la quantité dans la distillation
                $transport->mettreAJourQuantiteDistillation();
                
                // 2. Si le statut est "livre", créer la réception automatique
                if ($transport->estLivre()) {
                    Log::info('Transport créé avec statut "livre" - ID: ' . $transport->id);
                    $transport->creerReceptionAutomatique();
                }
                
                Log::info('Transport créé - ID: ' . $transport->id . ', Statut: ' . $transport->statut);
            } catch (\Exception $e) {
                Log::error('Erreur lors de la création du transport: ' . $e->getMessage());
            }
        });

        // Quand un transport est marqué comme "livré" plus tard
        static::updated(function ($transport) {
            if ($transport->isDirty('statut') && $transport->estLivre()) {
                Log::info('Statut transport changé vers "livre" - ID: ' . $transport->id);
                $transport->creerReceptionAutomatique();
            }
        });

        // Quand un transport est supprimé
        static::deleted(function ($transport) {
            try {
                $transport->restaurerQuantiteDistillation();
                Log::info('Transport supprimé - ID: ' . $transport->id . ', Quantité restaurée');
            } catch (\Exception $e) {
                Log::error('Erreur lors de la restauration de la distillation: ' . $e->getMessage());
            }
        });
    
    }

    /**
     * Mettre à jour la quantité restante dans la distillation
     */
    public function mettreAJourQuantiteDistillation(): void
    {
        $distillation = $this->distillation;
        
        if (!$distillation) {
            Log::warning('Distillation non trouvée pour transport ID: ' . $this->id);
            return;
        }

        // Vérifier qu'il y a assez de quantité disponible
        if ($distillation->quantite_resultat < $this->quantite_a_livrer) {
            throw new \Exception('Quantité insuffisante dans la distillation. Disponible: ' . $distillation->quantite_resultat . ', Demandée: ' . $this->quantite_a_livrer);
        }

        // Diminuer la quantité_resultat
        $nouvelleQuantite = max(0, $distillation->quantite_resultat - $this->quantite_a_livrer);
        
        $distillation->update([
            'quantite_resultat' => $nouvelleQuantite
        ]);

        Log::info('Quantité distillation mise à jour - ID: ' . $distillation->id . 
                 ', Ancienne quantité: ' . $distillation->getOriginal('quantite_resultat') . 
                 ', Nouvelle quantité: ' . $nouvelleQuantite . 
                 ', Transport ID: ' . $this->id);
    }

    /**
     * Restaurer la quantité dans la distillation quand un transport est supprimé
     */
    public function restaurerQuantiteDistillation(): void
    {
        $distillation = Distillation::find($this->distillation_id);
        
        if ($distillation) {
            $distillation->increment('quantite_resultat', $this->quantite_a_livrer);
            
            Log::info('Quantité distillation restaurée - Distillation ID: ' . $distillation->id . 
                     ', Transport ID: ' . $this->id . 
                     ', Quantité restaurée: ' . $this->quantite_a_livrer);
        } else {
            Log::warning('Distillation non trouvée pour restaurer quantité - Transport ID: ' . $this->id);
        }
    }


    /**
     * Créer une réception automatique
     */
    public function creerReceptionAutomatique(): void
    {
        try {
            // Vérifier qu'une réception n'existe pas déjà
            $receptionExistante = \App\Models\Vente\Reception::where('transport_id', $this->id)->first();
            
            if ($receptionExistante) {
                Log::info('Réception déjà existante pour le transport ID: ' . $this->id);
                return;
            }

            // Vérifier que la date de livraison est définie
            $dateLivraison = $this->date_livraison ?? now()->format('Y-m-d');
            
            $reception = \App\Models\Vente\Reception::create([
                'transport_id' => $this->id,
                'vendeur_id' => $this->vendeur_id,
                'date_reception' => $dateLivraison,
                'heure_reception' => now()->format('H:i'),
                'statut' => 'en attente',
                'quantite_recue' => $this->quantite_a_livrer,
                'lieu_reception' => $this->site_destination,
                'type_livraison' => 'transport',
                'observations' => $this->observations ?? 'Réception automatique créée après transport'
            ]);
            
            Log::info('Réception automatique créée - Transport ID: ' . $this->id . 
                     ', Réception ID: ' . $reception->id . 
                     ', Quantité: ' . $this->quantite_a_livrer);
        } catch (\Exception $e) {
            Log::error('Erreur création réception transport: ' . $e->getMessage() . 
                      ' - Trace: ' . $e->getTraceAsString());
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