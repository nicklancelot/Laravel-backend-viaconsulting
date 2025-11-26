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
        Schema::create('payement_avances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fournisseur_id')->constrained('fournisseurs')->onDelete('cascade');
            $table->decimal('montant', 15, 2);
            $table->dateTime('date');
            $table->enum('statut', ['payé', 'en_attente', 'annulé'])->default('en_attente');
            $table->enum('methode', ['espèces', 'virement', 'chèque']);
            $table->string('reference')->unique();
            $table->enum('type', ['avance', 'paiement_complet', 'acompte', 'règlement']);
            $table->text('description')->nullable();
            $table->decimal('montantDu', 15, 2)->nullable();
            $table->decimal('montantAvance', 15, 2)->nullable();
            $table->integer('delaiHeures')->nullable();
            $table->text('raison')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payement_avances');
    }
};