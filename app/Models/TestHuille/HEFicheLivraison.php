<?php

namespace App\Models\TestHuille;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\TestHuille\Stockhe;
use App\Models\Livreur;
use App\Models\Utilisateur;

class HEFicheLivraison extends Model
{
    use HasFactory;

    protected $fillable = [
        'stockhe_id', 
        'livreur_id',
        'vendeur_id',
        'date_heure_livraison',
        'fonction_destinataire',
        'lieu_depart',
        'destination',
        'type_produit',
        'poids_net',
        'quantite_a_livrer',
        'ristourne_regionale',
        'ristourne_communale',
        'statut', 
        'date_statut' 
    ];

    /**
     * Boot method pour créer automatiquement les réceptions
     */
    protected static function boot()
    {
        parent::boot();

        // Créer une réception automatique lors de la création d'une livraison
        static::created(function ($livraison) {
            if ($livraison->estLivree()) {
                $livraison->creerReceptionAutomatique();
            }
        });

        // Créer une réception automatique lors de la mise à jour du statut
        static::updated(function ($livraison) {
            if ($livraison->isDirty('statut') && $livraison->estLivree()) {
                // Vérifier si une réception existe déjà
                $receptionExistante = \App\Models\Vente\Reception::where('fiche_livraison_id', $livraison->id)->first();
                if (!$receptionExistante) {
                    $livraison->creerReceptionAutomatique();
                }
            }
        });
    }

    /**
     * Créer une réception automatique
     */
/**
 * Créer une réception automatique
 */
public function creerReceptionAutomatique(): void
{
    try {
        \App\Models\Vente\Reception::create([
            'fiche_livraison_id' => $this->id,
            'vendeur_id' => $this->vendeur_id,
            'date_reception' => now()->toDateString(),
            'heure_reception' => now()->format('H:i'),
            'statut' => 'en attente',
            'quantite_recue' => $this->quantite_a_livrer,
            'lieu_reception' => $this->destination,
            'type_produit' => $this->type_produit, // AJOUTÉ
            'observations' => 'Réception automatique créée après livraison'
        ]);
    } catch (\Exception $e) {
        \Log::error('Erreur création réception automatique: ' . $e->getMessage());
    }
}

    /**
     * Relation avec le stock
     */
    public function stockhe()
    {
        return $this->belongsTo(Stockhe::class);
    }

    public function livreur()
    {
        return $this->belongsTo(Livreur::class);
    }

    public function vendeur()
    {
        return $this->belongsTo(Utilisateur::class, 'vendeur_id');
    }
    
    /**
     * Vérifier si la livraison est livrée
     */
    public function estLivree(): bool
    {
        return $this->statut === 'livree';
    }
    
    /**
     * Vérifier si la livraison est annulée
     */
    public function estAnnulee(): bool
    {
        return $this->statut === 'annulee';
    }
    
    /**
     * Marquer la livraison comme annulée
     */
    public function marquerAnnulee(): void
    {
        if ($this->estLivree()) {
            // Restaurer le stock
            Stockhe::ajouterStock($this->quantite_a_livrer);
        }
        
        $this->update([
            'statut' => 'annulee',
            'date_statut' => now()
        ]);
    }
    
    /**
     * Soustraire la quantité livrée du stock
     */
    public function soustraireDuStock()
    {
        $this->stockhe->retirerStock($this->quantite_a_livrer);
        return $this;
    }
    
    /**
     * Restaurer la quantité dans le stock (si annulation)
     */
    public function restaurerDansStock()
    {
        $this->stockhe->ajouterStock($this->quantite_a_livrer);
        return $this;
    }
}