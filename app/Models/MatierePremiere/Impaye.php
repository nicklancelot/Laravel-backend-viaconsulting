<?php

namespace App\Models\MatierePremiere;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Impaye extends Model
{
    protected $fillable = [
        'pv_reception_id',
        'date_facturation', // ✅ Ajout pour cohérence avec la table
        'date_paiement',
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

    protected $dates = [
        'date_facturation',
        'date_paiement',
    ];

    public function pvReception()
    {
        return $this->belongsTo(PVReception::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($impaye) {
            // ✅ Set defaults pour dates non nullables si non fournies
            if (is_null($impaye->date_facturation)) {
                $impaye->date_facturation = Carbon::now()->format('Y-m-d');
            }
            if (is_null($impaye->date_paiement)) {
                $impaye->date_paiement = null; // Nullable, OK
            }
            
            $impaye->calculerChamps();
        });

        static::saving(function ($impaye) {
            $impaye->calculerChamps();
        });

        static::created(function ($impaye) {
            $impaye->mettreAJourStatutReception();
        });

        static::updated(function ($impaye) {
            $impaye->mettreAJourStatutReception();
        });
    }

    public function calculerChamps()
    {
        $this->reste_a_payer = $this->montant_total - $this->montant_paye;
    }

    public function mettreAJourStatutReception()
    {
        $reception = $this->pvReception;
        
        if ($reception) { 
            if ($this->reste_a_payer <= 0) {
                $reception->update([
                    'statut' => 'paye',
                    'dette_fournisseur' => 0 
                ]);
            } else {
                $reception->update([
                    'dette_fournisseur' => $this->reste_a_payer
                ]);
            }
        }
    }

    public function getStatutAttribute()
    {
        return $this->reste_a_payer <= 0 ? 'regle' : 'en_cours';
    }

    // ✅ Correction robuste : Gère les cas où numero_facture est null, mal formaté ou absent
    public static function genererNumeroImpaye()
    {
        try {
            $lastImpaye = self::orderBy('id', 'desc')->first();
            
            if ($lastImpaye && $lastImpaye->numero_facture && strpos($lastImpaye->numero_facture, 'IMP-') === 0) {
                $parts = explode('-', $lastImpaye->numero_facture);
                if (count($parts) === 2 && is_numeric($parts[1])) {
                    $number = intval($parts[1]) + 1;
                } else {
                    $number = self::count() + 1;
                }
            } else {
                $number = self::count() + 1;
            }
            
            return 'IMP-' . str_pad($number, 6, '0', STR_PAD_LEFT);
        } catch (\Exception $e) {
         
            return 'IMP-' . str_pad(self::count() + 1, 6, '0', STR_PAD_LEFT);
        }
    }
}