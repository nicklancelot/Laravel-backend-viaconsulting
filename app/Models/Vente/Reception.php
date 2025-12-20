<?php

namespace App\Models\Vente;

use Illuminate\Database\Eloquent\Model;
use App\Models\TestHuille\HEFicheLivraison;
use App\Models\Distillation\Transport;
use App\Models\Utilisateur;

class Reception extends Model
{
    protected $fillable = [
        'fiche_livraison_id',
        'transport_id',
        'vendeur_id',
        'date_reception',
        'heure_reception',
        'statut',
        'observations',
        'quantite_recue',
        'lieu_reception',
        'type_livraison',
        'signataire',
        'date_receptionne'
    ];

    protected $casts = [
        'date_reception' => 'date',
        'date_receptionne' => 'datetime',
    ];

    /**
     * Déterminer automatiquement le type de livraison
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($reception) {
            if ($reception->fiche_livraison_id) {
                $reception->type_livraison = 'fiche_livraison';
            } elseif ($reception->transport_id) {
                $reception->type_livraison = 'transport';
            }
        });
    }

    /**
     * Relation avec la fiche de livraison HE
     */
    public function ficheLivraison()
    {
        return $this->belongsTo(HEFicheLivraison::class, 'fiche_livraison_id');
    }

    /**
     * Relation avec le transport
     */
    public function transport()
    {
        return $this->belongsTo(Transport::class, 'transport_id');
    }

    /**
     * Relation avec le vendeur
     */
    public function vendeur()
    {
        return $this->belongsTo(Utilisateur::class, 'vendeur_id');
    }

    /**
     * Obtenir la source (fiche ou transport)
     */
    public function source()
    {
        if ($this->fiche_livraison_id) {
            return $this->ficheLivraison;
        }
        return $this->transport;
    }

    /**
     * Vérifier si c'est une fiche de livraison
     */
    public function estFicheLivraison(): bool
    {
        return $this->type_livraison === 'fiche_livraison';
    }

    /**
     * Vérifier si c'est un transport
     */
    public function estTransport(): bool
    {
        return $this->type_livraison === 'transport';
    }

    /**
     * Vérifier si la réception est en attente
     */
    public function estEnAttente(): bool
    {
        return $this->statut === 'en attente';
    }

    /**
     * Vérifier si la réception est réceptionnée
     */
    public function estReceptionne(): bool
    {
        return $this->statut === 'receptionne';
    }

    /**
     * Marquer comme réceptionnée
     */
    public function marquerReceptionne(array $data = []): void
    {
        $this->update([
            'statut' => 'receptionne',
            'date_receptionne' => now(),
            'signataire' => $data['signataire'] ?? $this->signataire,
            'observations' => $data['observations'] ?? $this->observations,
        ]);
    }

    /**
     * Marquer comme annulée
     */
    public function marquerAnnule(string $raison = null): void
    {
        $this->update([
            'statut' => 'annule',
            'observations' => $raison ? ($this->observations . "\nAnnulation: " . $raison) : $this->observations,
        ]);
    }

    /**
     * Scope pour les réceptions d'un type spécifique
     */
    public function scopeType($query, $type)
    {
        return $query->where('type_livraison', $type);
    }

    /**
     * Scope pour les réceptions en attente
     */
    public function scopeEnAttente($query)
    {
        return $query->where('statut', 'en attente');
    }
}