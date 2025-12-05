<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Provenances extends Model
{
    protected $fillable = ['Nom'];
    
    protected $table = 'provenances';
        public function utilisateurs(): HasMany
    {
        return $this->hasMany(Utilisateur::class);
    }
}