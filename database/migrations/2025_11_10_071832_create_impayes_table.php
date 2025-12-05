<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impayes', function (Blueprint $table) {
            $table->id();
            
            // Référence au PV de réception
            $table->foreignId('pv_reception_id')->constrained('p_v_receptions')->onDelete('cascade');
            
            // Informations de facturation (même structure)
            $table->string('numero_facture')->unique();
            $table->date('date_facturation');
            $table->date('date_paiement')->nullable();
            
            // Montants
            $table->decimal('montant_total', 12, 2);
            $table->decimal('montant_paye', 12, 2)->default(0);
            $table->decimal('reste_a_payer', 12, 2);
            
            // Informations de paiement
            $table->enum('mode_paiement', ['especes', 'virement', 'cheque', 'carte', 'mobile_money']);
            $table->string('reference_paiement')->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impayes');
    }
};