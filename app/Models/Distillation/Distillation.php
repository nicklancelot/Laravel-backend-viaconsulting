<?php

namespace App\Models\Distillation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class Distillation extends Model
{
    protected $table = 'distillations';
    
    protected $fillable = [
        // Référence au stock à distiller (optionnel maintenant)
        'stock_a_distiller_id',
        
        // Données du stock (copiées)
        'numero_pv',
        'type_matiere_premiere',
        'quantite_recue',
        'taux_humidite',
        'taux_dessiccation',
        
        // Informations de la distillation
        'statut',
        'id_ambalic',
        'date_debut',
        'date_fin',
        'usine',
        'duree_distillation',
        'poids_distiller', // ← Utiliser cette colonne au lieu de quantite_utilisee
        
        // Coûts
        'quantite_bois_chauffage',
        'prix_bois_chauffage',
        'quantite_carburant',
        'prix_carburant',
        'nombre_ouvriers',
        'heures_travail_par_ouvrier',
        'prix_heure_main_oeuvre',
        'prix_main_oeuvre',
        
        // Résultats
        'reference',
        'matiere',
        'site',
        'quantite_traitee',
        'type_he',
        'quantite_resultat',
        'observations',
        
        // Qui a créé
        'created_by'
    ];
    
    protected $dates = [
        'date_debut',
        'date_fin',
        'created_at',
        'updated_at'
    ];
    
    /**
     * Créer une nouvelle distillation
     */
    public static function creerNouvelle(array $donnees, StockADistiller $stock): self
    {
        DB::beginTransaction();
        
        try {
            // Réserver la quantité dans le stock
            if (!$stock->reserverPourDistillation($donnees['poids_distiller'])) {
                throw new \Exception('Quantité insuffisante dans le stock');
            }
            
            // Créer la distillation
            $distillation = self::create([
                'type_matiere_premiere' => $stock->type_matiere,
                'poids_distiller' => $donnees['poids_distiller'], // ← Utiliser poids_distiller
                'statut' => 'en_cours',
                'id_ambalic' => $donnees['id_ambalic'],
                'date_debut' => $donnees['date_debut'],
                'usine' => $donnees['usine'],
                'duree_distillation' => $donnees['duree_distillation'],
                'quantite_bois_chauffage' => $donnees['quantite_bois_chauffage'],
                'prix_bois_chauffage' => $donnees['prix_bois_chauffage'],
                'quantite_carburant' => $donnees['quantite_carburant'],
                'prix_carburant' => $donnees['prix_carburant'],
                'nombre_ouvriers' => $donnees['nombre_ouvriers'],
                'heures_travail_par_ouvrier' => $donnees['heures_travail_par_ouvrier'],
                'prix_heure_main_oeuvre' => $donnees['prix_heure_main_oeuvre'],
                'created_by' => $donnees['created_by'],
                'observations' => 'Démarrée avec ' . $donnees['poids_distiller'] . ' kg de ' . $stock->type_matiere
            ]);
            
            DB::commit();
            return $distillation;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
    
    /**
     * Boot method
     */
    protected static function boot()
    {
        parent::boot();

        // Calcul automatique du prix total de la main d'œuvre
        static::saving(function ($distillation) {
            $distillation->calculerPrixMainOeuvre();
        });

        // Créer un stock de produit fini quand la distillation est terminée
        static::updated(function ($distillation) {
            if ($distillation->isDirty('statut') && $distillation->estTerminee()) {
                $distillation->creerStockProduitFini();
            }
        });
    }

    /**
     * Synchroniser les données depuis le StockADistiller
     */
    public function syncFromStockADistiller(): void
    {
        if ($this->stockADistiller && $this->estEnAttente()) {
            $this->update([
                'numero_pv' => $this->stockADistiller->numero_pv_reference,
                'type_matiere_premiere' => $this->stockADistiller->type_matiere,
                'quantite_recue' => $this->stockADistiller->quantite_restante,
                'taux_humidite' => $this->stockADistiller->taux_humidite_moyen,
                'taux_dessiccation' => $this->stockADistiller->taux_dessiccation_moyen,
                'observations' => 'Distillation créée depuis stock agrégé: ' . $this->stockADistiller->type_matiere
            ]);
        }
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
     * Créer un stock de produit fini
     */
    public function creerStockProduitFini(): void
    {
        try {
            // Vérifier qu'un stock n'existe pas déjà
            if ($this->stock) {
                return;
            }

            // Vérifier que la distillation a une quantité de résultat
            if (!$this->quantite_resultat || $this->quantite_resultat <= 0) {
                return;
            }

            // Créer le stock
            Stock::creerDepuisDistillation($this);

        } catch (\Exception $e) {
            \Log::error('Erreur création stock produit fini: ' . $e->getMessage());
        }
    }

    /**
     * Relation avec le stock à distiller
     */
    public function stockADistiller(): BelongsTo
    {
        return $this->belongsTo(StockADistiller::class, 'stock_a_distiller_id');
    }

    /**
     * Relation avec le stock de produit fini
     */
    public function stock(): HasOne
    {
        return $this->hasOne(Stock::class);
    }

    /**
     * Relation avec les transports
     */
    public function transports(): HasMany
    {
        return $this->hasMany(Transport::class);
    }

    /**
     * Relation avec l'utilisateur créateur
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Utilisateur::class, 'created_by');
    }

    /**
     * Démarrer la distillation
     */
    public function demarrer(array $donneesDemarrage): self
    {
        $this->update(array_merge($donneesDemarrage, [
            'statut' => 'en_cours'
        ]));

        return $this;
    }

    /**
     * Terminer la distillation
     */
    public function terminer(array $donnees): bool
    {
        DB::beginTransaction();
        
        try {
            $this->update([
                'statut' => 'termine',
                'date_fin' => $donnees['date_fin'],
                'quantite_traitee' => $donnees['quantite_traitee'],
                'type_he' => $donnees['type_he'],
                'quantite_resultat' => $donnees['quantite_resultat'],
                'reference' => $donnees['reference'] ?? null,
                'matiere' => $donnees['matiere'] ?? null,
                'site' => $donnees['site'] ?? null,
                'observations' => $donnees['observations'] ?? $this->observations
            ]);
            
            // Créer le stock produit fini
            $this->creerStockProduitFini();
            
            DB::commit();
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
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
     * Accessor pour obtenir la quantité utilisée (alias de poids_distiller)
     */
    public function getQuantiteUtiliseeAttribute(): float
    {
        return (float) $this->poids_distiller;
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
     * Calculer le coût total de la main d'œuvre
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

    /**
     * Calculer la quantité déjà transportée
     */
    public function getQuantiteDejaTransporteeAttribute(): float
    {
        return $this->transports()->where('statut', 'en_cours')->sum('quantite_a_livrer');
    }

    /**
     * Calculer la quantité restante dans le stock de produit fini
     */
    public function getQuantiteRestanteAttribute(): float
    {
        if ($this->stock) {
            return $this->stock->quantite_disponible;
        }
        return 0;
    }

    /**
     * Obtenir le pourcentage d'utilisation du stock à distiller
     */
    public function getPourcentageUtiliseStockAttribute(): ?float
    {
        if ($this->poids_distiller > 0) {
            if ($this->stockADistiller) {
                $quantiteInitiale = $this->stockADistiller->getOriginal('quantite_recue');
                if ($quantiteInitiale > 0) {
                    return ($this->poids_distiller / $quantiteInitiale) * 100;
                }
            } elseif ($this->quantite_recue > 0) {
                return ($this->poids_distiller / $this->quantite_recue) * 100;
            }
        }
        return null;
    }

    /**
     * Scope pour les distillations en attente de démarrage
     */
    public function scopeEnAttente($query)
    {
        return $query->where('statut', 'en_attente');
    }

    /**
     * Créer une distillation automatique pour un stock
     */
    public static function creerPourStock(StockADistiller $stock): self
    {
        return self::create([
            'type_matiere_premiere' => $stock->type_matiere,
            'numero_pv' => $stock->numero_pv_reference,
            'quantite_recue' => $stock->quantite_restante,
            'taux_humidite' => $stock->taux_humidite_moyen,
            'taux_dessiccation' => $stock->taux_dessiccation_moyen,
            'statut' => 'en_attente',
            'observations' => 'Distillation créée pour stock agrégé: ' . $stock->type_matiere,
            'created_by' => $stock->distilleur_id
        ]);
    }
}