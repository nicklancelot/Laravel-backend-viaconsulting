<?php

namespace App\Models\TestHuille;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Stockhe extends Model
{
    protected $table = 'stockhes';
    
    protected $fillable = [
        'stock_total',
        'stock_disponible',
        'utilisateur_id',
        'niveau_stock'
    ];

    /**
     * Relation avec l'utilisateur
     */
    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Utilisateur::class);
    }

    /**
     * Boot method pour gérer les actions automatiques
     */
    protected static function boot()
    {
        parent::boot();

        // Assurer qu'il n'y a qu'un seul stock global
        static::creating(function ($stock) {
            if ($stock->utilisateur_id === null && $stock->niveau_stock === 'global') {
                // Vérifier s'il existe déjà un stock global
                $existingGlobal = static::whereNull('utilisateur_id')
                    ->where('niveau_stock', 'global')
                    ->first();
                    
                if ($existingGlobal) {
                    return false; // Empêcher la création d'un deuxième stock global
                }
            }
        });
    }

    /**
     * Ajouter au stock (global et/ou utilisateur)
     */
    public static function ajouterStock(float $quantite, ?int $utilisateurId = null): bool
    {
        try {
            DB::beginTransaction();

            // 1. Toujours ajouter au stock global
            $stockGlobal = self::firstOrCreate(
                [
                    'utilisateur_id' => null,
                    'niveau_stock' => 'global'
                ],
                [
                    'stock_total' => 0,
                    'stock_disponible' => 0,
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            );
            
            $stockGlobal->increment('stock_total', $quantite);
            $stockGlobal->increment('stock_disponible', $quantite);

            // 2. Si un utilisateur est spécifié, ajouter aussi à son stock personnel
            if ($utilisateurId) {
                $stockUtilisateur = self::firstOrCreate(
                    [
                        'utilisateur_id' => $utilisateurId,
                        'niveau_stock' => 'utilisateur'
                    ],
                    [
                        'stock_total' => 0,
                        'stock_disponible' => 0,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                );
                
                $stockUtilisateur->increment('stock_total', $quantite);
                $stockUtilisateur->increment('stock_disponible', $quantite);
            }

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur ajout stock HE: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Retirer du stock (priorité: utilisateur -> global)
     */
    public static function retirerStock(float $quantite, ?int $utilisateurId = null): bool
    {
        try {
            DB::beginTransaction();

            $quantiteRestante = $quantite;
            $retraitDetails = [];

            // 1. D'abord retirer du stock utilisateur si spécifié
            if ($utilisateurId) {
                $stockUtilisateur = self::where('utilisateur_id', $utilisateurId)
                    ->where('niveau_stock', 'utilisateur')
                    ->first();
                    
                if ($stockUtilisateur) {
                    $quantiteRetirable = min($stockUtilisateur->stock_disponible, $quantiteRestante);
                    
                    if ($quantiteRetirable > 0) {
                        $stockUtilisateur->decrement('stock_disponible', $quantiteRetirable);
                        $quantiteRestante -= $quantiteRetirable;
                        $retraitDetails[] = [
                            'source' => 'utilisateur',
                            'utilisateur_id' => $utilisateurId,
                            'quantite' => $quantiteRetirable
                        ];
                    }
                }
            }

            // 2. Si besoin, retirer du stock global
            if ($quantiteRestante > 0) {
                $stockGlobal = self::whereNull('utilisateur_id')
                    ->where('niveau_stock', 'global')
                    ->first();
                    
                if ($stockGlobal && $stockGlobal->stock_disponible >= $quantiteRestante) {
                    $stockGlobal->decrement('stock_disponible', $quantiteRestante);
                    $retraitDetails[] = [
                        'source' => 'global',
                        'quantite' => $quantiteRestante
                    ];
                    $quantiteRestante = 0;
                }
            }

            DB::commit();

            if ($quantiteRestante > 0) {
                \Log::warning('Stock insuffisant pour retirer ' . $quantite . ' (reste: ' . $quantiteRestante . ')');
                return false;
            }

            \Log::info('Retrait stock HE réussi', $retraitDetails);
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur retrait stock HE: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Vérifier la disponibilité (priorité: utilisateur -> global)
     */
    public static function verifierDisponibilite(float $quantite, ?int $utilisateurId = null): bool
    {
        $quantiteRestante = $quantite;

        // 1. Vérifier le stock utilisateur d'abord
        if ($utilisateurId) {
            $stockUtilisateur = self::where('utilisateur_id', $utilisateurId)
                ->where('niveau_stock', 'utilisateur')
                ->first();
                
            if ($stockUtilisateur) {
                $quantiteDisponible = $stockUtilisateur->stock_disponible;
                if ($quantiteDisponible >= $quantiteRestante) {
                    return true;
                }
                $quantiteRestante -= $quantiteDisponible;
            }
        }

        // 2. Vérifier le stock global
        $stockGlobal = self::whereNull('utilisateur_id')
            ->where('niveau_stock', 'global')
            ->first();
            
        return $stockGlobal && $stockGlobal->stock_disponible >= $quantiteRestante;
    }

    /**
     * Obtenir le stock actuel selon le contexte
     */
    public static function getStockActuel(?int $utilisateurId = null, bool $includeGlobal = true)
    {
        if ($utilisateurId) {
            // Stock de l'utilisateur spécifique
            $stockUtilisateur = self::where('utilisateur_id', $utilisateurId)
                ->where('niveau_stock', 'utilisateur')
                ->first();
                
            if ($includeGlobal) {
                $stockGlobal = self::whereNull('utilisateur_id')
                    ->where('niveau_stock', 'global')
                    ->first();
                    
                return [
                    'utilisateur' => $stockUtilisateur,
                    'global' => $stockGlobal,
                    'total_disponible' => ($stockUtilisateur ? $stockUtilisateur->stock_disponible : 0) + 
                                        ($stockGlobal ? $stockGlobal->stock_disponible : 0)
                ];
            }
            
            return $stockUtilisateur;
        }
        
        // Stock global seulement
        return self::whereNull('utilisateur_id')
            ->where('niveau_stock', 'global')
            ->first();
    }

    /**
     * Obtenir le stock total disponible
     */
    public static function getStockTotalDisponible(?int $utilisateurId = null): float
    {
        $total = 0;

        // Stock global
        $stockGlobal = self::whereNull('utilisateur_id')
            ->where('niveau_stock', 'global')
            ->first();
            
        if ($stockGlobal) {
            $total += $stockGlobal->stock_disponible;
        }

        // Stock utilisateur si spécifié
        if ($utilisateurId) {
            $stockUtilisateur = self::where('utilisateur_id', $utilisateurId)
                ->where('niveau_stock', 'utilisateur')
                ->first();
                
            if ($stockUtilisateur) {
                $total += $stockUtilisateur->stock_disponible;
            }
        }

        return $total;
    }

    /**
     * Obtenir le stock disponible détaillé
     */
    public static function getStockDetaille(?int $utilisateurId = null): array
    {
        $stockGlobal = self::whereNull('utilisateur_id')
            ->where('niveau_stock', 'global')
            ->first();
            
        $stockUtilisateur = null;
        
        if ($utilisateurId) {
            $stockUtilisateur = self::where('utilisateur_id', $utilisateurId)
                ->where('niveau_stock', 'utilisateur')
                ->first();
        }

        return [
            'global' => [
                'id' => $stockGlobal ? $stockGlobal->id : null,
                'stock_total' => $stockGlobal ? $stockGlobal->stock_total : 0,
                'stock_disponible' => $stockGlobal ? $stockGlobal->stock_disponible : 0,
                'utilisateur_id' => null,
                'niveau_stock' => 'global'
            ],
            'utilisateur' => $stockUtilisateur ? [
                'id' => $stockUtilisateur->id,
                'stock_total' => $stockUtilisateur->stock_total,
                'stock_disponible' => $stockUtilisateur->stock_disponible,
                'utilisateur_id' => $stockUtilisateur->utilisateur_id,
                'niveau_stock' => 'utilisateur'
            ] : null,
            'total_disponible' => ($stockGlobal ? $stockGlobal->stock_disponible : 0) + 
                                ($stockUtilisateur ? $stockUtilisateur->stock_disponible : 0),
            'utilisateur_id' => $utilisateurId
        ];
    }

    /**
     * Transférer du stock
     */
    public static function transfererStock(
        float $quantite,
        string $sourceType, // 'global' ou 'utilisateur'
        ?int $sourceUtilisateurId = null,
        int $destUtilisateurId
    ): bool {
        try {
            DB::beginTransaction();

            // 1. Retirer de la source
            if ($sourceType === 'global') {
                $stockSource = self::whereNull('utilisateur_id')
                    ->where('niveau_stock', 'global')
                    ->first();
                    
                if (!$stockSource || $stockSource->stock_disponible < $quantite) {
                    throw new \Exception('Stock global insuffisant');
                }
                
                $stockSource->decrement('stock_disponible', $quantite);
                
            } else if ($sourceType === 'utilisateur' && $sourceUtilisateurId) {
                $stockSource = self::where('utilisateur_id', $sourceUtilisateurId)
                    ->where('niveau_stock', 'utilisateur')
                    ->first();
                    
                if (!$stockSource || $stockSource->stock_disponible < $quantite) {
                    throw new \Exception('Stock utilisateur source insuffisant');
                }
                
                $stockSource->decrement('stock_disponible', $quantite);
            } else {
                throw new \Exception('Type de source invalide');
            }

            // 2. Ajouter à la destination
            $stockDest = self::firstOrCreate(
                [
                    'utilisateur_id' => $destUtilisateurId,
                    'niveau_stock' => 'utilisateur'
                ],
                [
                    'stock_total' => 0,
                    'stock_disponible' => 0,
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            );
            
            $stockDest->increment('stock_total', $quantite);
            $stockDest->increment('stock_disponible', $quantite);

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur transfert stock HE: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtenir l'historique des mouvements de stock (méthode de base)
     */
    public static function getHistoriqueMouvements(?int $utilisateurId = null, ?int $limit = 50)
    {
        // Cette méthode peut être étendue selon vos besoins
        // Par exemple, si vous avez une table de logs de stock
        return [
            'message' => 'Historique non implémenté. À développer selon vos besoins.',
            'utilisateur_id' => $utilisateurId
        ];
    }

    /**
     * Scopes pour faciliter les requêtes
     */
    public function scopeGlobal($query)
    {
        return $query->whereNull('utilisateur_id')->where('niveau_stock', 'global');
    }

    public function scopeUtilisateur($query, int $utilisateurId)
    {
        return $query->where('utilisateur_id', $utilisateurId)->where('niveau_stock', 'utilisateur');
    }

    public function scopeDisponible($query)
    {
        return $query->where('stock_disponible', '>', 0);
    }

    /**
     * Accesseurs
     */
    public function getEstGlobalAttribute(): bool
    {
        return $this->utilisateur_id === null && $this->niveau_stock === 'global';
    }

    public function getEstUtilisateurAttribute(): bool
    {
        return $this->utilisateur_id !== null && $this->niveau_stock === 'utilisateur';
    }

    public function getStockUtiliseAttribute(): float
    {
        return $this->stock_total - $this->stock_disponible;
    }

    public function getPourcentageUtilisationAttribute(): float
    {
        return $this->stock_total > 0 ? ($this->stock_utilise / $this->stock_total) * 100 : 0;
    }
}