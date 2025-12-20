<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiche_receptions', function (Blueprint $table) {
            $table->id();
            $table->string('numero_document')->unique();
            $table->date('date_reception');
            $table->time('heure_reception');
            $table->foreignId('utilisateur_id')->constrained('utilisateurs')->onDelete('cascade'); 
            $table->foreignId('fournisseur_id')->constrained()->onDelete('cascade');
            $table->foreignId('site_collecte_id')->constrained()->onDelete('cascade');
            $table->decimal('poids_brut', 10, 2);
            $table->decimal('poids_agreer', 10, 2)->nullable(); 
            $table->decimal('taux_humidite', 5, 2)->nullable();
            $table->decimal('taux_dessiccation', 5, 2)->nullable();
            $table->decimal('poids_net', 10, 2)->nullable();
            $table->enum('type_emballage', ['sac', 'bidon', 'fut'])->nullable();
            $table->decimal('poids_emballage', 10, 2)->nullable(); // en kg
            $table->integer('nombre_colisage')->nullable();
            $table->decimal('prix_unitaire', 10, 2)->nullable();
            $table->decimal('prix_total', 12, 2)->nullable();
            $table->decimal('quantite_totale', 10, 2)->nullable();
            $table->decimal('quantite_restante', 10, 2)->nullable(); // Mis à jour après chaque livraison
            $table->enum('statut', [ 
                //Fiche-livraison
                'en attente de teste',
                //lancer le teste
                'en cours de teste',  
                //validation:
                'Accepté',
                'Refusé',           
                'A retraiter',
                //payememt
                'payé',  
                'incomplet', 
                'payement incomplète',
                //fiche de livraison
                'En attente de livraison',
                'en cours de livraison',    
                //livraisom
                'livré',
                'partiellement_livre'  
            ])->default('en attente de teste');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiche_receptions');
    }
};