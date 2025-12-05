<?php

namespace App\Models\TestHuille;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HEImpaye extends Model
{
    use HasFactory;

    protected $table = 'h_e_impayes';

    protected $fillable = [
        'facturation_id',
        'montant_du',
        'montant_paye', 
        'reste_a_payer'
    ];

    // Relation avec la facturation
    public function facturation()
    {
        return $this->belongsTo(HEFacturation::class, 'facturation_id');
    }
}