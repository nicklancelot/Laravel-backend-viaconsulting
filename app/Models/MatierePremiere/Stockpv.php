<?php

namespace App\Models\MatierePremiere;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Stockpv extends Model
{
    protected $table = 'stockpvs';

    protected $fillable = [
 
        'type_matiere',
        'stock_total',
        'stock_disponible',
    
    ];


    /**
     * Relation avec le PV de réception
     */
    public function pvReception(): BelongsTo
    {
        return $this->belongsTo(PVReception::class);
    }

    /**
     * Obtenir le stock total disponible par type
     */
    public static function getStockDisponibleParType(string $type): float
    {
        return self::where('type_matiere', $type)->sum('stock_disponible') ?? 0;
    }

    /**
     * Obtenir tous les stocks disponibles pour un type (trié par date)
     */
    public static function getStocksDisponiblesParType(string $type)
    {
        return self::where('type_matiere', $type)
            ->where('stock_disponible', '>', 0)
         
            ->get();
    }

    /**
     * Vérifier si une quantité est disponible pour un type
     */
    public static function verifierDisponibilite(string $type, float $quantite): bool
    {
        $stockTotal = self::getStockDisponibleParType($type);
        return $stockTotal >= $quantite;
    }

      public function ficheLivraison(): HasOne
    {
        return $this->hasOne(FicheLivraison::class);
    }
     public function getALivraisonEnAttenteAttribute(): bool
    {
        return $this->ficheLivraison && !$this->ficheLivraison->livraison;
    } 
    public function ficheLivraisons()
{
    return $this->hasMany(FicheLivraison::class, 'stockpvs_id');
}
}