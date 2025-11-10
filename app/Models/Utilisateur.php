<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Utilisateur extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'nom',
        'prenom',
        'numero',
        'localisation_id',
        'CIN',
        'role',
        'password'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function localisation(): BelongsTo
    {
        return $this->belongsTo(Localisation::class);
    }
}