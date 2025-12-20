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
            $table->foreignId('stockpvs_id')->constrained('stockpvs')->onDelete('cascade');
            $table->foreignId('livreur_id')->constrained('livreurs')->onDelete('cascade');
   
            $table->foreignId('distilleur_id')->constrained('utilisateurs')->onDelete('cascade');
            
            // Informations de livraison
            $table->date('date_livraison');
            $table->string('lieu_depart');
            $table->decimal('ristourne_regionale', 10, 2)->default(0);
            $table->decimal('ristourne_communale', 10, 2)->default(0);
            // Quantité livrée
            $table->decimal('quantite_a_livrer', 10, 2);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiche_livraisons');
    }
};