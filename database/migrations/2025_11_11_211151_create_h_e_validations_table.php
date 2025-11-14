<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('h_e_validations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiche_reception_id')->constrained('fiche_receptions')->onDelete('cascade');
            $table->foreignId('test_id')->constrained('h_e_testers')->onDelete('cascade');
            $table->enum('decision', ['Accepter', 'Refuser', 'A retraiter']);
            $table->decimal('poids_agreer', 10, 2)->nullable();
            $table->text('observation_ecart_poids')->nullable();
            $table->text('observation_generale')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('h_e_validations');
    }
};