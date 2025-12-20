<?php

namespace App\Models\TestHuille;

use Illuminate\Database\Eloquent\Model;

class Stockhe extends Model
{
    protected $table = 'stockhes';
    
    protected $fillable = [
        'stock_total',
        'stock_disponible'
    ];
    

    public static function ajouterStock(float $quantite): void
    {
        $stock = self::first();
        
        if (!$stock) {
            $stock = self::create([
                'stock_total' => $quantite,
                'stock_disponible' => $quantite
            ]);
        } else {
            $stock->increment('stock_total', $quantite);
            $stock->increment('stock_disponible', $quantite);
        }
    }
    
    /**
     * Retirer du stock
     */
    public static function retirerStock(float $quantite): bool
    {
        $stock = self::first();
        
        if (!$stock || $stock->stock_disponible < $quantite) {
            return false;
        }
        
        $stock->decrement('stock_disponible', $quantite);
        return true;
    }
    
    /**
     * Vérifier la disponibilité
     */
    public static function verifierDisponibilite(float $quantite): bool
    {
        $stock = self::first();
        return $stock && $stock->stock_disponible >= $quantite;
    }
    
    /**
     * Obtenir le stock actuel
     */
    public static function getStockActuel()
    {
        return self::first();
    }
}