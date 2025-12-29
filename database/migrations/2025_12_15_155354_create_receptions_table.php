<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receptions', function (Blueprint $table) {
            $table->id();
            
            // Soit fiche de livraison HE, soit transport
            $table->foreignId('fiche_livraison_id')->nullable()->constrained('h_e_fiche_livraisons')->onDelete('cascade');
            $table->foreignId('transport_id')->nullable()->constrained('transports')->onDelete('cascade');
            
            $table->foreignId('vendeur_id')->constrained('utilisateurs')->onDelete('cascade');
            $table->date('date_reception');
            $table->time('heure_reception')->nullable();
            $table->enum('statut', ['en attente', 'receptionne', 'annule'])->default('en attente');
            $table->text('observations')->nullable();
            $table->decimal('quantite_recue', 10, 2);
            $table->string('lieu_reception', 100);
            $table->string('type_livraison', 20)->nullable(); 
            $table->string('type_produit', 50)->nullable(); 
          
            $table->timestamp('date_receptionne')->nullable();
            $table->timestamps();
            
            $table->unique(['fiche_livraison_id', 'transport_id']);
            
            // Index
            $table->index(['vendeur_id', 'statut']);
            $table->index(['type_livraison', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receptions');
    }
};