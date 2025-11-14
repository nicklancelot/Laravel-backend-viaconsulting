<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('p_v_receptions', function (Blueprint $table) {
            $table->id();
            
            // Informations générales
            $table->enum('type', ['FG', 'CG', 'GG']);
            $table->string('numero_doc')->unique();
            $table->dateTime('date_reception');
            $table->decimal('dette_fournisseur', 12, 2)->default(0); 
            $table->foreignId('utilisateur_id')->constrained('utilisateurs')->onDelete('cascade');
            $table->foreignId('fournisseur_id')->constrained('fournisseurs')->onDelete('cascade');
            $table->foreignId('provenance_id')->constrained('provenances')->onDelete('cascade'); 
            
            // Données de poids et emballage
            $table->decimal('poids_brut', 10, 2); // en kg
            $table->enum('type_emballage', ['sac', 'bidon', 'fut']);
            $table->decimal('poids_emballage', 10, 2); // en kg
            $table->decimal('poids_net', 10, 2); // en kg (calculé: poids_brut - poids_emballage)
            $table->integer('nombre_colisage');
            
            // Données de prix
            $table->decimal('prix_unitaire', 10, 2);
            $table->decimal('prix_total', 12, 2);
            
            // Données techniques (spécifiques selon le type)
            $table->decimal('taux_humidite', 5, 2)->nullable(); // en %
            $table->decimal('taux_dessiccation', 5, 2)->nullable(); // seulement pour CG (%/kg)
            
            // Statut et workflow
            $table->enum('statut', ['non_paye', 'paye', 'incomplet', 'en_attente_livraison', 'partiellement_livre',  'livree'])->default('non_paye');
            $table->decimal('quantite_totale', 10, 2); // Calculé: nombre_colisage * poids_emballage
            $table->decimal('quantite_restante', 10, 2); // Mis à jour après chaque livraison
        
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('p_v_receptions');
    }
};