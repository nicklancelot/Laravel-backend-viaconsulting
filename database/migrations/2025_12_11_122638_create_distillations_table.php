<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distillations', function (Blueprint $table) {
            $table->id();
            
            // Référence à l'expédition
            $table->foreignId('expedition_id')->constrained('expeditions')->onDelete('cascade');
            
            // Statut du processus
            $table->enum('statut', ['en_attente', 'en_cours', 'termine'])->default('en_attente');
            
            // Données de réception (provenant du PV)
            $table->string('numero_pv'); // Numéro du PV de réception
            $table->string('type_matiere_premiere'); // FG, CG, GG
            $table->decimal('quantite_recue', 10, 2); // Poids net du PV
            $table->decimal('taux_humidite', 5, 2)->nullable();
            $table->decimal('taux_dessiccation', 5, 2)->nullable();
            
            // Données de démarrage de distillation (à remplir)
            $table->string('id_ambalic')->nullable();
            $table->date('date_debut')->nullable();
            $table->decimal('poids_distiller', 10, 2)->nullable();
            $table->string('usine')->nullable();
            $table->integer('duree_distillation')->nullable(); // en heures
            $table->decimal('poids_chauffage', 10, 2)->nullable();
            $table->string('carburant')->nullable();
            $table->decimal('main_oeuvre', 10, 2)->nullable();
            
            // Données de fin de distillation (à remplir)
            $table->string('reference')->nullable();
            $table->string('matiere')->nullable();
            $table->string('site')->nullable();
            $table->decimal('quantite_traitee', 10, 2)->nullable();
            $table->date('date_fin')->nullable();
            $table->string('type_he')->nullable(); 
            $table->decimal('quantite_resultat', 10, 2)->nullable();
            $table->text('observations')->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distillations');
    }
};