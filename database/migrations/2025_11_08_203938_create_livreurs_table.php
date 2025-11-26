<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('livreurs', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->string('prenom');
            $table->string('cin');
            $table->date('date_naissance');
            $table->string('lieu_naissance');
            $table->date('date_delivrance_cin');
            $table->string('contact_famille');
            $table->string('telephone');
            $table->string('numero_vehicule');
            $table->text('observation')->nullable();
            $table->string('zone_livraison');
            $table->foreignId('created_by')->constrained('utilisateurs')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('livreurs');
    }
};