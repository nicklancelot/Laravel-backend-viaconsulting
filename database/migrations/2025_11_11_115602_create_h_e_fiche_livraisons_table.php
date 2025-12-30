<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
Schema::create('h_e_fiche_livraisons', function (Blueprint $table) {
    $table->id();
    $table->foreignId('stockhe_id')->constrained('stockhes')->onDelete('cascade'); 
    $table->foreignId('livreur_id')->nullable()->constrained('livreurs')->onDelete('set null');
    $table->foreignId('vendeur_id')->constrained('utilisateurs')->onDelete('cascade');
    $table->dateTime('date_heure_livraison');
    $table->string('fonction_destinataire', 100);
    $table->string('lieu_depart', 100);
    $table->string('destination', 100);
    $table->string('type_produit', 100);
    $table->decimal('poids_net', 10, 2);
    $table->decimal('ristourne_regionale', 10, 2)->default(0);
    $table->decimal('ristourne_communale', 10, 2)->default(0);
    $table->decimal('quantite_a_livrer', 10, 2);
    $table->enum('statut', ['livree', 'annulee'])->default('livree');
    $table->timestamp('date_statut')->nullable();
    $table->timestamps();
    
    // Index pour optimiser les requêtes
    $table->index(['stockhe_id', 'created_at']);
});
    }

    public function down(): void
    {
        Schema::dropIfExists('h_e_fiche_livraisons');
    }
};