<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SoldeUser extends Model
{
    protected $table = 'solde_users';
    
    protected $fillable = ['utilisateur_id', 'solde'];


    /**
     * Relation avec l'utilisateur
     */
    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }

    /**
     * Vérifie si le solde est suffisant
     */
    public function soldeSuffisant(float $montant): bool
    {
        return $this->solde >= $montant;
    }

    /**
     * Débite le solde
     */
    public function debiter(float $montant): bool
    {
        if (!$this->soldeSuffisant($montant)) {
            return false;
        }
        
        $this->solde -= $montant;
        return $this->save();
    }

    /**
     * Crédite le solde
     */
    public function crediter(float $montant): bool
    {
        $this->solde += $montant;
        return $this->save();
    }

    /**
     * Met à jour le solde (pour augmenter ou diminuer)
     */
    public function updateSolde(float $montant, string $operation = 'credit'): bool
    {
        if ($operation === 'debit') {
            return $this->debiter($montant);
        } else {
            return $this->crediter($montant);
        }
    }
}