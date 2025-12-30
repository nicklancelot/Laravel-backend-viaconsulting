<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expeditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiche_livraison_id')->constrained('fiche_livraisons')->onDelete('cascade');
            $table->enum('statut', ['en_attente', 'receptionne'])->default('en_attente');
            $table->date('date_expedition')->nullable();
            $table->date('date_reception')->nullable();
            $table->decimal('quantite_expediee', 10, 2); 
            $table->decimal('quantite_recue', 10, 2)->nullable(); 
            $table->string('type_matiere'); 
             $table->string('lieu_depart')->nullable(); 
            $table->text('observations')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expeditions');
    }
};