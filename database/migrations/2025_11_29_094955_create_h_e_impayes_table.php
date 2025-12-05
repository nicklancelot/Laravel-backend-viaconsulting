<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('h_e_impayes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facturation_id')->constrained('h_e_facturations')->onDelete('cascade');
            $table->decimal('montant_du', 15, 2);
            $table->decimal('montant_paye', 15, 2)->default(0);
            $table->decimal('reste_a_payer', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('h_e_impayes');
    }
};