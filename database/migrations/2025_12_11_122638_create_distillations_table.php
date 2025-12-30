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
            
            // Références
          
            $table->foreignId('stock_a_distiller_id')->nullable()->constrained('stock_a_distillers')->onDelete('cascade');
            
            // Statut du processus
            $table->enum('statut', ['en_attente', 'en_cours', 'termine'])->default('en_attente');
            
            // Données provenant du StockADistiller (copiées)
            $table->string('numero_pv'); 
            $table->string('type_matiere_premiere'); // FG, CG, GG
            $table->decimal('quantite_recue', 10, 2); 
            $table->decimal('taux_humidite', 5, 2)->nullable();
            $table->decimal('taux_dessiccation', 5, 2)->nullable();
            
            // Données de démarrage de distillation (remplies par l'utilisateur)
            $table->string('id_ambalic')->nullable();
            $table->date('date_debut')->nullable();
            $table->decimal('poids_distiller', 10, 2)->nullable(); // Poids à distiller (choisi par l'utilisateur)
            $table->string('usine')->nullable();
            $table->integer('duree_distillation')->nullable(); // Durée estimée en jours

            // Bois de chauffage
            $table->decimal('quantite_bois_chauffage', 10, 2)->nullable(); // quantité en kg
            $table->decimal('prix_bois_chauffage', 10, 2)->nullable(); // prix par kg

            // Carburant
            $table->decimal('quantite_carburant', 10, 2)->nullable(); // quantité en litres
            $table->decimal('prix_carburant', 10, 2)->nullable(); // prix par litre

            // Main d'œuvre
            $table->decimal('nombre_ouvriers', 10, 2)->nullable(); // nombre d'ouvriers
            $table->decimal('heures_travail_par_ouvrier', 5, 2)->nullable(); // heures de travail par ouvrier
            $table->decimal('prix_heure_main_oeuvre', 10, 2)->nullable(); // prix par heure par ouvrier
            $table->decimal('prix_main_oeuvre', 10, 2)->nullable(); // prix total main d'œuvre (calculé automatiquement)
            
            // Données de fin de distillation (remplies par l'utilisateur)
            $table->string('reference')->nullable();
            $table->string('matiere')->nullable();
            $table->string('site')->nullable();
            $table->decimal('quantite_traitee', 10, 2)->nullable();
            $table->date('date_fin')->nullable();
            $table->string('type_he')->nullable(); // Type d'huile essentielle
            $table->decimal('quantite_resultat', 10, 2)->nullable();
            $table->text('observations')->nullable();
            
            // Audit
            $table->foreignId('created_by')->nullable()->constrained('utilisateurs')->onDelete('set null');
            
            $table->timestamps();
            
            // Index pour optimiser les recherches
            $table->index(['statut', 'date_debut']);
            $table->index(['stock_a_distiller_id', 'statut']);
            $table->index(['type_matiere_premiere', 'statut']);
            $table->index(['type_he', 'date_fin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distillations');
    }
};