<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    
    public function up(): void
    {
        Schema::create('exportations', function (Blueprint $table) {
            $table->id();
           
            $table->bigInteger('numero_contrat')->nullable()->unique();
            $table->date('date_contrat')->nullable();
            $table->string('produit')->nullable();
            $table->string('poids')->nullable();
    
            $table->string('designation')->nullable();
            $table->decimal('prix_unitaire', 15, 2)->nullable();
            $table->decimal('prix_total', 15, 2)->nullable();
            $table->decimal('frais_transport', 15, 2)->nullable();

            // Lien vers client (acheteur)
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();

            // Documents (stocker chemins)
            $table->string('devis_path')->nullable();
            $table->string('proforma_path')->nullable();
            $table->string('phytosanitaire_path')->nullable();
            $table->string('eaux_forets_path')->nullable();
            $table->string('mise_fob_cif_path')->nullable();
            $table->string('livraison_transitaire_path')->nullable();
            $table->string('transmission_documents_path')->nullable();
            $table->string('recouvrement_path')->nullable();
            $table->string('piece_justificative_path')->nullable();

            // Encaissement
            $table->decimal('montant_encaisse', 15, 2)->nullable();

            // Commentaires
            $table->text('commentaires')->nullable();

            // Propriétaire (vendeur/admin qui a créé)
            $table->foreignId('utilisateur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exportations');
    }
};
