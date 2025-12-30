<?php

namespace App\Models\Distillation;

use App\Models\Livreur;
use App\Models\Utilisateur;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class Transport extends Model
{
    protected $fillable = [
        'distillation_id',
        'stock_id',
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
        'date_livraison',
        'created_by' // AJOUTÉ
    ];

    /**
     * Relation avec la distillation
     */
    public function distillation(): BelongsTo
    {
        return $this->belongsTo(Distillation::class);
    }

    /**
     * Relation avec le stock
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
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
     * Relation avec l'utilisateur qui a créé le transport
     */
    public function createur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'created_by');
    }

    /**
     * Boot method pour gérer les événements
     */
    protected static function boot()
    {
        parent::boot();

        // Quand un transport est créé avec statut "en_cours"
        static::created(function ($transport) {
            try {
                // Créer une réception en attente automatiquement
                $transport->creerReceptionEnAttente();
                
                Log::info('Transport créé - ID: ' . $transport->id . 
                         ', Statut: ' . $transport->statut . 
                         ', Réception créée: en attente');
            } catch (\Exception $e) {
                Log::error('Erreur lors de la création du transport: ' . $e->getMessage());
            }
        });

        // Quand un transport est marqué comme "livré"
        static::updated(function ($transport) {
            if ($transport->isDirty('statut') && $transport->estLivre()) {
                try {
                    // Mettre à jour la réception existante
                    $transport->mettreAJourReceptionLivre();
                    
                    Log::info('Transport marqué comme livré - ID: ' . $transport->id . 
                             ', Réception mise à jour');
                } catch (\Exception $e) {
                    Log::error('Erreur lors de la mise à jour du transport livré: ' . $e->getMessage());
                }
            }
        });
    }

    /**
     * Créer une réception en attente
     */
    public function creerReceptionEnAttente(): void
    {
        try {
            // Vérifier qu'une réception n'existe pas déjà
            $receptionExistante = \App\Models\Vente\Reception::where('transport_id', $this->id)->first();
            
            if ($receptionExistante) {
                Log::info('Réception déjà existante pour le transport ID: ' . $this->id);
                return;
            }

            // Vérifier que le vendeur existe
            if (!$this->vendeur) {
                Log::warning('Vendeur non trouvé pour transport ID: ' . $this->id);
                return;
            }

            // Créer la réception
            $reception = \App\Models\Vente\Reception::create([
                'transport_id' => $this->id,
                'vendeur_id' => $this->vendeur_id,
                'fiche_livraison_id' => null,
                'date_reception' => $this->date_transport,
                'heure_reception' => now()->format('H:i:s'),
                'statut' => 'en attente',
                'quantite_recue' => $this->quantite_a_livrer,
                'lieu_reception' => $this->site_destination,
                'type_livraison' => 'transport',
                'type_produit' => $this->type_matiere,
                'observations' => 'En attente de livraison - Transport ID: ' . $this->id . 
                                ($this->observations ? ' - ' . $this->observations : ''),
                'date_receptionne' => null
            ]);
            
            Log::info('Réception en attente créée - Transport ID: ' . $this->id . 
                     ', Réception ID: ' . $reception->id . 
                     ', Quantité: ' . $this->quantite_a_livrer);
        } catch (\Exception $e) {
            Log::error('Erreur création réception en attente: ' . $e->getMessage() . 
                      ' - Trace: ' . $e->getTraceAsString());
        }
    }

    /**
     * Mettre à jour la réception quand le transport est livré
     */
    public function mettreAJourReceptionLivre(): void
    {
        try {
            // Trouver la réception existante
            $reception = \App\Models\Vente\Reception::where('transport_id', $this->id)->first();
            
            if (!$reception) {
                Log::warning('Aucune réception trouvée pour transport ID: ' . $this->id);
                // Créer une nouvelle réception si elle n'existe pas
                $this->creerReceptionLivre();
                return;
            }

            // Mettre à jour la réception
            $reception->update([
                'statut' => 'receptionne',
                'date_receptionne' => $this->date_livraison ?? now()->format('Y-m-d'),
                'observations' => 'Livré le ' . ($this->date_livraison ?? now()->format('Y-m-d')) . 
                                ($this->observations ? ' - ' . $this->observations : '')
            ]);
            
            Log::info('Réception mise à jour comme livrée - Transport ID: ' . $this->id . 
                     ', Réception ID: ' . $reception->id);
        } catch (\Exception $e) {
            Log::error('Erreur mise à jour réception livrée: ' . $e->getMessage());
        }
    }

    /**
     * Créer une réception directement en statut "livré"
     */
    private function creerReceptionLivre(): void
    {
        try {
            $reception = \App\Models\Vente\Reception::create([
                'transport_id' => $this->id,
                'vendeur_id' => $this->vendeur_id,
                'fiche_livraison_id' => null,
                'date_reception' => $this->date_livraison ?? now()->format('Y-m-d'),
                'heure_reception' => now()->format('H:i:s'),
                'statut' => 'receptionne',
                'quantite_recue' => $this->quantite_a_livrer,
                'lieu_reception' => $this->site_destination,
                'type_livraison' => 'transport',
                'type_produit' => $this->type_matiere,
                'observations' => 'Livré directement - ' . ($this->observations ?? ''),
                'date_receptionne' => $this->date_livraison ?? now()->format('Y-m-d')
            ]);
            
            Log::info('Réception livrée créée directement - Transport ID: ' . $this->id . 
                     ', Réception ID: ' . $reception->id);
        } catch (\Exception $e) {
            Log::error('Erreur création réception livrée: ' . $e->getMessage());
        }
    }

    /**
     * Marquer comme livré (et mettre à jour la réception)
     */
    public function marquerLivre(string $observations = null): void
    {
        DB::transaction(function () use ($observations) {
            // Mettre à jour les observations si fournies
            if ($observations) {
                $this->observations = $observations;
            }
            
            // Sortir la quantité du stock
            if ($this->stock) {
                $this->stock->sortirQuantite($this->quantite_a_livrer);
            }
            
            // Mettre à jour le transport
            $this->update([
                'statut' => 'livre',
                'date_livraison' => now()->format('Y-m-d')
            ]);
            
            // La réception sera mise à jour automatiquement via l'event updated
        });
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
     * Vérifier si le transport est annulé
     */
    public function estAnnule(): bool
    {
        return $this->statut === 'annule';
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