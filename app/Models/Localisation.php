<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Localisation extends Model
{
    protected $fillable = ['Nom'];

    public function utilisateurs(): HasMany
    {
        return $this->hasMany(Utilisateur::class);
    }
}