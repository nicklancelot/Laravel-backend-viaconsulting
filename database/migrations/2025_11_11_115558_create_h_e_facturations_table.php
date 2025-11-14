<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('h_e_facturations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiche_reception_id')->constrained('fiche_receptions')->onDelete('cascade');
            $table->decimal('prix_unitaire', 15, 2);
            $table->decimal('montant_total', 15, 2);
            $table->decimal('avance_versee', 15, 2)->default(0);
            $table->decimal('reste_a_payer', 15, 2);
            $table->string('controller_qualite', 100);
            $table->string('responsable_commercial', 100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('h_e_facturations');
    }
};