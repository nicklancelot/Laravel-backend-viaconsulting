<?php

namespace App\Models\Distillation;

use App\Models\Utilisateur;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stock extends Model
{
    protected $fillable = [
        'distillation_id',
        'distilleur_id',
        'type_produit',
        'reference',
        'matiere',
        'site_production',
        'quantite_initiale',
        'quantite_disponible',
        'quantite_reservee',
        'quantite_sortie',
        'date_entree',
        'date_production',
        'statut',
        'observations'
    ];

    protected $dates = [
        'date_entree',
        'date_production',
        'created_at',
        'updated_at'
    ];

    /**
     * Relation avec la distillation
     */
    public function distillation(): BelongsTo
    {
        return $this->belongsTo(Distillation::class);
    }

    /**
     * Relation avec le distilleur
     */
    public function distilleur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'distilleur_id');
    }

    /**
     * Relation avec les transports
     */
    public function transports()
    {
        return $this->hasMany(Transport::class);
    }

    /**
     * Créer automatiquement une entrée en stock après distillation
     */
    public static function creerDepuisDistillation(Distillation $distillation)
    {
        // Vérifier que la distillation est terminée
        if (!$distillation->estTerminee()) {
            throw new \Exception('La distillation doit être terminée pour créer un stock');
        }

        // Vérifier que la distillation a une quantité de résultat
        if (!$distillation->quantite_resultat || $distillation->quantite_resultat <= 0) {
            throw new \Exception('La distillation doit avoir une quantité de résultat positive');
        }

        // Charger les relations nécessaires
        $distillation->load(['expedition.ficheLivraison']);
        
        // Vérifier que les relations existent
        if (!$distillation->expedition || !$distillation->expedition->ficheLivraison) {
            throw new \Exception('Relations expedition ou ficheLivraison non trouvées');
        }

        return self::create([
            'distillation_id' => $distillation->id,
            'distilleur_id' => $distillation->expedition->ficheLivraison->distilleur_id,
            'type_produit' => $distillation->type_he,
            'reference' => $distillation->reference,
            'matiere' => $distillation->matiere,
            'site_production' => $distillation->site,
            'quantite_initiale' => $distillation->quantite_resultat,
            'quantite_disponible' => $distillation->quantite_resultat,
            'date_entree' => now(),
            'date_production' => $distillation->date_fin,
            'statut' => $distillation->quantite_resultat > 0 ? 'disponible' : 'epuise',
            'observations' => $distillation->observations
        ]);
    }

    /**
     * Réserver une quantité pour un transport
     */
    public function reserverQuantite($quantite): bool
    {
        if ($this->quantite_disponible >= $quantite) {
            $this->quantite_disponible -= $quantite;
            $this->quantite_reservee += $quantite;
            $this->statut = $this->quantite_disponible > 0 ? 'disponible' : 'epuise';
            return $this->save();
        }
        return false;
    }

    /**
     * Libérer une quantité réservée
     */
    public function libererQuantite($quantite): bool
    {
        if ($this->quantite_reservee >= $quantite) {
            $this->quantite_reservee -= $quantite;
            $this->quantite_disponible += $quantite;
            $this->statut = 'disponible';
            return $this->save();
        }
        return false;
    }

    /**
     * Sortir une quantité du stock (après transport effectué)
     */
    public function sortirQuantite($quantite): bool
    {
        if ($this->quantite_reservee >= $quantite) {
            $this->quantite_reservee -= $quantite;
            $this->quantite_sortie += $quantite;
            
            // Mettre à jour le statut
            if ($this->quantite_disponible == 0 && $this->quantite_reservee == 0) {
                $this->statut = 'epuise';
            }
            
            return $this->save();
        }
        return false;
    }

    /**
     * Vérifier si le stock est disponible
     */
    public function estDisponible(): bool
    {
        return $this->statut === 'disponible' && $this->quantite_disponible > 0;
    }

    /**
     * Obtenir la quantité réellement disponible (non réservée)
     */
    public function getQuantiteReellementDisponibleAttribute(): float
    {
        return $this->quantite_disponible;
    }

    /**
     * Obtenir le pourcentage d'utilisation
     */
    public function getPourcentageUtiliseAttribute(): float
    {
        if ($this->quantite_initiale > 0) {
            return (($this->quantite_sortie + $this->quantite_reservee) / $this->quantite_initiale) * 100;
        }
        return 0;
    }
}