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
            $table->enum('statut', [ 
                'en attente de teste', 
                'en cours de teste',  
                'Accepté',
                'Teste terminée',
                'teste validé', 
                'teste invalide', 
                'En attente de livraison',
                'en cours de livraison',
                'payé',  
                'incomplet',                  
                'partiellement payé',     
                'en attente de paiement',
                'payement incomplète',
                'livré' ,
                'Refusé',           
                'A retraiter'       
            ])->default('en attente de teste');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiche_receptions');
    }
};