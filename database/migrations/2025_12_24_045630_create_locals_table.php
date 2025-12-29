<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('locals', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('numero_contrat')->nullable()->unique();
            $table->date('date_contrat')->nullable();
            $table->string('produit')->nullable();

            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();

            $table->string('test_qualite_path')->nullable();

            $table->date('date_livraison_prevue')->nullable();
            $table->string('produit_bon_livraison')->nullable();
            $table->string('poids_bon_livraison')->nullable();
            $table->string('destinataires')->nullable();

            $table->string('livraison_client_path')->nullable();
            $table->string('agreage_client_path')->nullable();
            $table->string('recouvrement_path')->nullable();
            $table->string('piece_justificative_path')->nullable();

            $table->decimal('montant_encaisse', 15, 2)->nullable();
            $table->text('commentaires')->nullable();

            $table->foreignId('utilisateur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locals');
    }
};
