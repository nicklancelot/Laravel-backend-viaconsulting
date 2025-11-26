<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiche_livraisons', function (Blueprint $table) {
            $table->id();
            
            // Référence au PV de réception
            $table->foreignId('pv_reception_id')->constrained('p_v_receptions')->onDelete('cascade');
            $table->foreignId('livreur_id')->constrained('livreurs')->onDelete('cascade');
            $table->foreignId('destinateur_id')->constrained('destinateurs')->onDelete('cascade');
            
            // Détails de livraison
            $table->date('date_livraison');
            $table->string('lieu_depart');
            $table->decimal('ristourne_regionale', 10, 2)->default(0);
            $table->decimal('ristourne_communale', 10, 2)->default(0); // NOUVEAU
            $table->decimal('quantite_a_livrer', 10, 2);
            $table->decimal('quantite_restante', 10, 2)->default(0); // NOUVEAU - quantité non livrée
            $table->boolean('est_partielle')->default(false); // NOUVEAU - indique si livraison partielle
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiche_livraisons');
    }
};