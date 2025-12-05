<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('h_e_testers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiche_reception_id')->constrained('fiche_receptions')->onDelete('cascade');
            $table->date('date_test');
            $table->time('heure_debut');
            $table->time('heure_fin_prevue');
            $table->time('heure_fin_reelle')->nullable();
            $table->decimal('densite', 8, 4)->nullable();
            $table->enum('presence_huile_vegetale', ['Oui', 'Non'])->default('Non');
            $table->enum('presence_lookhead', ['Oui', 'Non'])->default('Non');
            $table->decimal('teneur_eau', 5, 2)->nullable(); 
            $table->text('observations')->nullable();
            $table->timestamp('test_expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('h_e_testers');
    }
};