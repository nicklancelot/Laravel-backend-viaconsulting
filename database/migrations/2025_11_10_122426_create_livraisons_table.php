<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('livraisons', function (Blueprint $table) {
            $table->id();
            
            // Référence à la fiche de livraison
            $table->foreignId('fiche_livraison_id')->constrained('fiche_livraisons')->onDelete('cascade');
            $table->timestamp('date_confirmation_livraison')->useCurrent();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('livraisons');
    }
};