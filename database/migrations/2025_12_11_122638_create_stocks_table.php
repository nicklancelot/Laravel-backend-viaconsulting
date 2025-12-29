<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            
            // Référence à la distillation
            $table->foreignId('distillation_id')->constrained('distillations')->onDelete('cascade');
            $table->foreignId('distilleur_id')->constrained('utilisateurs')->onDelete('cascade');
            
            // Informations du produit
            $table->string('type_produit'); // Type d'huile essentielle
            $table->string('reference')->nullable(); // Référence du lot
            $table->string('matiere')->nullable(); // Matière première utilisée
            $table->string('site_production'); // Site de production
            
            // Quantités
            $table->decimal('quantite_initiale', 10, 2); // Quantité après distillation
            $table->decimal('quantite_disponible', 10, 2); // Quantité restante
            $table->decimal('quantite_reservee', 10, 2)->default(0); // Pour transports futurs
            $table->decimal('quantite_sortie', 10, 2)->default(0); // Total déjà transporté
            
            // Dates
            $table->date('date_entree'); // Date d'entrée en stock
            $table->date('date_production'); // Date de fin de distillation
            
            // Statut
            $table->enum('statut', ['disponible', 'reserve', 'epuise'])->default('disponible');
            
            // Informations qualité
            $table->text('observations')->nullable();
            
            $table->timestamps();
            
            // Index pour optimiser les recherches par type de produit
            $table->index(['type_produit', 'statut']);
            $table->index(['distilleur_id', 'date_entree']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};