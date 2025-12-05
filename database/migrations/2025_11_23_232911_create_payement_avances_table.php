<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payement_avances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fournisseur_id')->constrained('fournisseurs')->onDelete('cascade');
            $table->foreignId('pv_reception_id')->nullable()->constrained('p_v_receptions');
            $table->foreignId('fiche_reception_id')->nullable()->constrained('fiche_receptions'); // Corrigé le nom de la colonne
            $table->timestamp('date_utilisation')->nullable();
            $table->decimal('montant', 15, 2);
            $table->dateTime('date');
            $table->enum('statut', ['arrivé', 'en_attente', 'annulé', 'utilise'])->default('en_attente');
            $table->enum('methode', ['espèces', 'virement', 'chèque']);
            $table->string('reference')->unique();
            $table->enum('type', ['avance', 'paiement_complet', 'acompte', 'règlement']);
            $table->text('description')->nullable();
            $table->decimal('montantDu', 15, 2)->nullable();
            $table->decimal('montantAvance', 15, 2)->nullable();
            $table->integer('delaiHeures')->nullable();
            $table->text('raison')->nullable();
            $table->decimal('montant_utilise', 15, 2)->default(0); 
            $table->decimal('montant_restant', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payement_avances');
    }
};