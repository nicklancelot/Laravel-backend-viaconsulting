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
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            // Nom de l'entreprise (required)
            $table->string('nom_entreprise');

            // Nom/prénom du client
            $table->string('nom_client')->nullable();
            $table->string('prenom_client')->nullable();

            // Contact
            $table->string('telephone')->nullable();
            $table->string('email')->nullable();

            // Adresse
            $table->string('rue_numero')->nullable();
            $table->string('quartier_lot')->nullable();
            $table->string('ville')->nullable();
            $table->string('code_postal')->nullable();

            // Infos supplémentaires
            $table->text('informations')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
