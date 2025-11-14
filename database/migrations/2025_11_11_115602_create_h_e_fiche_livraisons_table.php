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
            $table->foreignId('fiche_reception_id')->constrained('fiche_receptions')->onDelete('cascade');
            $table->dateTime('date_heure_livraison');
            $table->string('nom_livreur', 100);
            $table->string('prenom_livreur', 100);
            $table->string('telephone_livreur', 20);
            $table->string('numero_vehicule', 50);
            $table->string('nom_destinataire', 100);
            $table->string('prenom_destinataire', 100);
            $table->string('fonction_destinataire', 100);
            $table->string('telephone_destinataire', 20);
            $table->string('lieu_depart', 100);
            $table->string('destination', 100);
            $table->string('type_produit', 100);
            $table->decimal('poids_net', 10, 2);
            $table->decimal('ristourne_regionale', 10, 2)->default(0);
            $table->decimal('ristourne_communale', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('h_e_fiche_livraisons');
    }
};