<?php

namespace App\Models\MatierePremiere;

use Illuminate\Database\Eloquent\Model;

class Facturation extends Model
{
    protected $fillable = [
        'pv_reception_id',
        'date_facturation',
        'numero_facture',
        'mode_paiement',
        'reference_paiement',
        'montant_total',
        'montant_paye',
        'reste_a_payer'
    ];

    const MODE_ESPECES = 'especes';
    const MODE_VIREMENT = 'virement';
    const MODE_CHEQUE = 'cheque';
    const MODE_CARTE = 'carte';
    const MODE_MOBILE_MONEY = 'mobile_money';

    /**
     * Relation avec le PV de réception
     */
    public function pvReception()
    {
        return $this->belongsTo(PVReception::class);
    }

    /**
     * Boot du modèle
     */
    protected static function boot()
    {
        parent::boot();

        // Calcul automatique des champs avant sauvegarde
        static::saving(function ($facturation) {
            $facturation->calculerChamps();
        });

        // Mise à jour du statut après création
        static::created(function ($facturation) {
            $facturation->mettreAJourStatutReception();
        });

        // Mise à jour du statut après modification
        static::updated(function ($facturation) {
            $facturation->mettreAJourStatutReception();
        });
    }

    /**
     * Calcul des champs automatiques
     */
    public function calculerChamps()
    {
        $this->reste_a_payer = $this->montant_total - $this->montant_paye;
    }

    /**
     * Mise à jour du statut du PV de réception
     */
    public function mettreAJourStatutReception()
    {
        $reception = $this->pvReception;
        
        if (!$reception) {
            return;
        }

        if ($this->reste_a_payer <= 0) {
            // Facturation entièrement payée
            $reception->update([
                'statut' => 'paye',
                'dette_fournisseur' => 0
            ]);
        } elseif ($this->montant_paye > 0) {
            // Facturation partiellement payée
            $reception->update([
                'statut' => 'incomplet'
                // La dette est mise à jour dans le contrôleur
            ]);
        } else {
            // Facturation non payée
            $reception->update([
                'statut' => 'non_paye'
            ]);
        }
    }

    /**
     * Accesseur pour le statut de la facturation
     */
    public function getStatutAttribute()
    {
        if ($this->reste_a_payer <= 0) {
            return 'payee';
        } elseif ($this->montant_paye > 0) {
            return 'partiel';
        } else {
            return 'impayee';
        }
    }

    /**
     * Accesseur pour le montant restant formaté
     */
    public function getResteAPayerFormateAttribute()
    {
        return number_format($this->reste_a_payer, 2, ',', ' ') . ' Ar';
    }

    /**
     * Accesseur pour le montant total formaté
     */
    public function getMontantTotalFormateAttribute()
    {
        return number_format($this->montant_total, 2, ',', ' ') . ' Ar';
    }

    /**
     * Accesseur pour le montant payé formaté
     */
    public function getMontantPayeFormateAttribute()
    {
        return number_format($this->montant_paye, 2, ',', ' ') . ' Ar';
    }

    /**
     * Accesseur pour le libellé du mode de paiement
     */
    public function getModePaiementLibelleAttribute()
    {
        $modes = [
            self::MODE_ESPECES => 'Espèces',
            self::MODE_VIREMENT => 'Virement',
            self::MODE_CHEQUE => 'Chèque',
            self::MODE_CARTE => 'Carte',
            self::MODE_MOBILE_MONEY => 'Mobile Money'
        ];

        return $modes[$this->mode_paiement] ?? $this->mode_paiement;
    }

    /**
     * Vérifie si la facturation est entièrement payée
     */
    public function estPayee(): bool
    {
        return $this->reste_a_payer <= 0;
    }

    /**
     * Vérifie si la facturation est partiellement payée
     */
    public function estPartiellementPayee(): bool
    {
        return $this->montant_paye > 0 && $this->reste_a_payer > 0;
    }

    /**
     * Vérifie si la facturation est impayée
     */
    public function estImpayee(): bool
    {
        return $this->montant_paye == 0;
    }

    /**
     * Génère un numéro de facture automatique
     */
    public static function genererNumeroFacture()
    {
        $lastFacture = self::orderBy('id', 'desc')->first();
        $number = $lastFacture ? intval(explode('-', $lastFacture->numero_facture)[1]) + 1 : 1;
        
        return 'FACT-' . str_pad($number, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Scope pour les facturations payées
     */
    public function scopePayees($query)
    {
        return $query->where('reste_a_payer', '<=', 0);
    }

    /**
     * Scope pour les facturations impayées
     */
    public function scopeImpayees($query)
    {
        return $query->where('montant_paye', 0);
    }

    /**
     * Scope pour les facturations partiellement payées
     */
    public function scopePartiellementPayees($query)
    {
        return $query->where('montant_paye', '>', 0)
                    ->where('reste_a_payer', '>', 0);
    }

    /**
     * Scope pour les facturations par mode de paiement
     */
    public function scopeParModePaiement($query, $modePaiement)
    {
        return $query->where('mode_paiement', $modePaiement);
    }

    /**
     * Scope pour les facturations d'un PV de réception
     */
    public function scopePourPVReception($query, $pvReceptionId)
    {
        return $query->where('pv_reception_id', $pvReceptionId);
    }

    /**
     * Scope pour les facturations entre deux dates
     */
    public function scopeEntreDates($query, $dateDebut, $dateFin)
    {
        return $query->whereBetween('date_facturation', [$dateDebut, $dateFin]);
    }

    /**
     * Récupère le total des montants payés
     */
    public static function getTotalMontantPaye()
    {
        return self::sum('montant_paye');
    }

    /**
     * Récupère le total des montants restants
     */
    public static function getTotalResteAPayer()
    {
        return self::sum('reste_a_payer');
    }

    /**
     * Récupère le total des montants facturés
     */
    public static function getTotalMontantFacture()
    {
        return self::sum('montant_total');
    }

    /**
     * Formate la date de facturation
     */
    public function getDateFacturationFormateeAttribute()
    {
        return \Carbon\Carbon::parse($this->date_facturation)->format('d/m/Y');
    }

    /**
     * Formate la date de paiement
     */
    public function getDatePaiementFormateeAttribute()
    {
        return $this->date_paiement 
            ? \Carbon\Carbon::parse($this->date_paiement)->format('d/m/Y')
            : 'Non payé';
    }

    /**
     * Vérifie si un paiement partiel peut être effectué
     */
    public function peutEffectuerPaiementPartiel($montant): bool
    {
        return $montant > 0 && $montant <= $this->reste_a_payer;
    }

    /**
     * Effectue un paiement partiel
     */
    public function effectuerPaiementPartiel($montant, $modePaiement, $referencePaiement = null, $datePaiement = null)
    {
        if (!$this->peutEffectuerPaiementPartiel($montant)) {
            throw new \Exception('Montant de paiement invalide');
        }

        $this->montant_paye += $montant;
        $this->mode_paiement = $modePaiement;
        $this->reference_paiement = $referencePaiement;
        $this->date_paiement = $datePaiement ?: now();

        $this->save();

        return $this;
    }
}