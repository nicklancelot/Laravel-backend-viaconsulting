<?php

namespace App\Models\Distillation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Distillation\Expedition;
use App\Models\Distillation\Transport; 
use Carbon\Carbon;

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
        // Bois de chauffage
        'quantite_bois_chauffage',
        'prix_bois_chauffage',
        // Carburant
        'quantite_carburant',
        'prix_carburant',
        // Main d'œuvre
        'nombre_ouvriers',
        'heures_travail_par_ouvrier',
        'prix_heure_main_oeuvre',
        'prix_main_oeuvre',
        // Données de fin
        'reference',
        'matiere',
        'site',
        'quantite_traitee',
        'date_fin',
        'type_he',
        'quantite_resultat',
        'observations'
    ];

    protected $dates = [
        'date_debut',
        'date_fin',
        'created_at',
        'updated_at'
    ];

    /**
     * Boot method pour calculer automatiquement le prix total de la main d'œuvre
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($distillation) {
            $distillation->calculerPrixMainOeuvre();
        });
    }

    /**
     * Calculer le prix total de la main d'œuvre
     */
    public function calculerPrixMainOeuvre(): void
    {
        if ($this->nombre_ouvriers && $this->heures_travail_par_ouvrier && $this->prix_heure_main_oeuvre) {
            $this->prix_main_oeuvre = $this->nombre_ouvriers * $this->heures_travail_par_ouvrier * $this->prix_heure_main_oeuvre;
        }
    }

    /**
     * Relation avec l'expédition
     */
    public function expedition(): BelongsTo
    {
        return $this->belongsTo(Expedition::class);
    }

    /**
     * Relation avec le transport
     */
    public function transports(): HasMany
    {
        return $this->hasMany(Transport::class);
    }

    /**
     * Relation avec le stock
     */
    public function stock()
    {
        return $this->hasOne(Stock::class);
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
        \Illuminate\Support\Facades\DB::beginTransaction();
        
        try {
            $this->update(array_merge($donneesFin, [
                'statut' => 'termine'
            ]));

            // Créer automatiquement l'entrée en stock
            Stock::creerDepuisDistillation($this);
            
            \Illuminate\Support\Facades\DB::commit();
            
            return $this;
            
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            throw $e;
        }
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
     * Vérifier si le stock a été créé
     */
    public function aStock(): bool
    {
        return $this->stock !== null;
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

    /**
     * Calculer la durée réelle entre date_debut et date_fin
     */
    public function getDureeReelleAttribute(): ?int
    {
        if ($this->date_debut && $this->date_fin) {
            $dateDebut = Carbon::parse($this->date_debut);
            $dateFin = Carbon::parse($this->date_fin);
            
            // Calculer la différence en jours
            return $dateDebut->diffInDays($dateFin);
        }
        
        return null;
    }

    /**
     * Formater la durée réelle
     */
    public function getDureeReelleFormateAttribute(): string
    {
        if ($this->duree_reelle !== null) {
            return $this->duree_reelle . ' jour(s)';
        }
        
        return 'Non disponible';
    }

    /**
     * Comparer la durée estimée (duree_distillation) avec la durée réelle
     */
    public function getDifferenceDureeAttribute(): ?int
    {
        if ($this->duree_distillation && $this->duree_reelle !== null) {
            return $this->duree_reelle - $this->duree_distillation;
        }
        
        return null;
    }

    /**
     * Formater la différence de durée
     */
    public function getDifferenceDureeFormateAttribute(): string
    {
        if ($this->difference_duree !== null) {
            if ($this->difference_duree > 0) {
                return '+' . $this->difference_duree . ' jour(s) (plus long)';
            } elseif ($this->difference_duree < 0) {
                return $this->difference_duree . ' jour(s) (plus court)';
            } else {
                return 'Délai respecté';
            }
        }
        
        return 'Non calculable';
    }

    public function getQuantiteDejaTransporteeAttribute(): float
    {
        return $this->transports()->where('statut', 'en_cours')->sum('quantite_a_livrer');
    }

    public function getQuantiteRestanteAttribute(): float
    {
        if (!$this->quantite_resultat) {
            return 0;
        }
        
        return max(0, $this->quantite_resultat - $this->quantite_deja_transportee);
    }

    /**
     * Calculer le nombre total d'heures de travail
     */
    public function getHeuresTravailTotalesAttribute(): float
    {
        if ($this->nombre_ouvriers && $this->heures_travail_par_ouvrier) {
            return $this->nombre_ouvriers * $this->heures_travail_par_ouvrier;
        }
        return 0;
    }

    /**
     * Formater les heures totales de travail
     */
    public function getHeuresTravailTotalesFormateAttribute(): string
    {
        return number_format($this->heures_travail_totales, 1) . ' heures';
    }

    /**
     * Calculer le coût total du bois de chauffage
     */
    public function getCoutBoisChauffageAttribute(): float
    {
        if ($this->quantite_bois_chauffage && $this->prix_bois_chauffage) {
            return $this->quantite_bois_chauffage * $this->prix_bois_chauffage;
        }
        return 0;
    }

    /**
     * Formater le coût du bois de chauffage
     */
    public function getCoutBoisChauffageFormateAttribute(): string
    {
        return number_format($this->cout_bois_chauffage, 2) . ' MGA';
    }

    /**
     * Calculer le coût total du carburant
     */
    public function getCoutCarburantAttribute(): float
    {
        if ($this->quantite_carburant && $this->prix_carburant) {
            return $this->quantite_carburant * $this->prix_carburant;
        }
        return 0;
    }

    /**
     * Formater le coût du carburant
     */
    public function getCoutCarburantFormateAttribute(): string
    {
        return number_format($this->cout_carburant, 2) . ' MGA';
    }

    /**
     * Calculer le coût total de la main d'œuvre (attribut calculé)
     */
    public function getCoutMainOeuvreAttribute(): float
    {
        // Si le prix total est déjà calculé et sauvegardé
        if ($this->prix_main_oeuvre) {
            return $this->prix_main_oeuvre;
        }
        
        // Sinon, calculer
        if ($this->nombre_ouvriers && $this->heures_travail_par_ouvrier && $this->prix_heure_main_oeuvre) {
            return $this->nombre_ouvriers * $this->heures_travail_par_ouvrier * $this->prix_heure_main_oeuvre;
        }
        
        return 0;
    }

    /**
     * Formater le coût de la main d'œuvre
     */
    public function getCoutMainOeuvreFormateAttribute(): string
    {
        return number_format($this->cout_main_oeuvre, 2) . ' MGA';
    }

    /**
     * Calculer le coût horaire moyen par ouvrier
     */
    public function getCoutHoraireMoyenAttribute(): ?float
    {
        if ($this->nombre_ouvriers && $this->prix_heure_main_oeuvre) {
            return $this->prix_heure_main_oeuvre * $this->nombre_ouvriers;
        }
        return null;
    }

    /**
     * Formater le coût horaire moyen
     */
    public function getCoutHoraireMoyenFormateAttribute(): string
    {
        if ($this->cout_horaire_moyen !== null) {
            return number_format($this->cout_horaire_moyen, 2) . ' MGA/heure';
        }
        return 'Non calculable';
    }

    /**
     * Calculer le coût par heure de travail
     */
    public function getCoutParHeureTravailAttribute(): ?float
    {
        if ($this->heures_travail_totales > 0 && $this->cout_main_oeuvre > 0) {
            return $this->cout_main_oeuvre / $this->heures_travail_totales;
        }
        return null;
    }

    /**
     * Formater le coût par heure de travail
     */
    public function getCoutParHeureTravailFormateAttribute(): string
    {
        if ($this->cout_par_heure_travail !== null) {
            return number_format($this->cout_par_heure_travail, 2) . ' MGA/heure';
        }
        return 'Non calculable';
    }

    /**
     * Calculer le coût total de la distillation
     */
    public function getCoutTotalDistillationAttribute(): float
    {
        return $this->cout_bois_chauffage + $this->cout_carburant + $this->cout_main_oeuvre;
    }

    /**
     * Formater le coût total de la distillation
     */
    public function getCoutTotalDistillationFormateAttribute(): string
    {
        return number_format($this->cout_total_distillation, 2) . ' MGA';
    }

    /**
     * Calculer le coût par litre/kg d'HE produit
     */
    public function getCoutParProduitAttribute(): ?float
    {
        if ($this->quantite_resultat > 0 && $this->cout_total_distillation > 0) {
            return $this->cout_total_distillation / $this->quantite_resultat;
        }
        return null;
    }

    /**
     * Formater le coût par litre/kg d'HE produit
     */
    public function getCoutParProduitFormateAttribute(): string
    {
        if ($this->cout_par_produit !== null) {
            return number_format($this->cout_par_produit, 2) . ' MGA/L';
        }
        return 'Non calculable';
    }
}