<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_a_distillers', function (Blueprint $table) {
            $table->id();
            
            // Distilleur propriétaire
            $table->foreignId('distilleur_id')->constrained('utilisateurs')->onDelete('cascade');
            
            // Type de matière (UNIQUE par distilleur)
            $table->string('type_matiere'); // FG, CG, GG
            
            // Quantités (AGREGÉES de toutes les expéditions)
            $table->decimal('quantite_initiale', 10, 2)->default(0);
            $table->decimal('quantite_utilisee', 10, 2)->default(0);
            
            // Informations qualité (moyennes pondérées)
            $table->decimal('taux_humidite_moyen', 5, 2)->nullable();
            $table->decimal('taux_dessiccation_moyen', 5, 2)->nullable();
            
            // Référence
            $table->string('numero_pv_reference')->nullable();
            
            // Statut
            $table->enum('statut', ['disponible', 'en_distillation', 'epuise'])->default('disponible');
            
            // Observations
            $table->text('observations')->nullable();
            
            $table->timestamps();
            
            // UNIQUE : un seul stock par type de matière pour chaque distilleur
            $table->unique(['distilleur_id', 'type_matiere']);
            
            // Index pour optimiser les recherches
            $table->index(['distilleur_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_a_distillers');
    }
};