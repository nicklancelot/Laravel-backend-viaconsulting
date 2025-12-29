<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distillation_id')->constrained('distillations')->onDelete('cascade');
             $table->foreignId('stock_id')->nullable()->constrained('stocks')->onDelete('cascade'); 
            $table->foreignId('vendeur_id')->constrained('utilisateurs')->onDelete('cascade'); 
            $table->foreignId('livreur_id')->constrained('livreurs')->onDelete('cascade');
           
            // Informations de transport
            $table->date('date_transport');
            $table->string('lieu_depart'); 
            $table->string('site_destination'); 
            $table->string('type_matiere'); 
            $table->decimal('quantite_a_livrer', 10, 2);
            
            // Ristournes
            $table->decimal('ristourne_regionale', 10, 2)->default(0);
            $table->decimal('ristourne_communale', 10, 2)->default(0);
            
            // Observation
            $table->text('observations')->nullable();
            
            // Statut - seulement "en_cours" et "livre"
            $table->enum('statut', ['en_cours', 'livre'])->default('en_cours');
            $table->date('date_livraison')->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transports');
    }
};