<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   
    public function up(): void
    {
        Schema::create('transferts', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('admin_id')->constrained('utilisateurs')->onDelete('cascade');
            $table->foreignId('destinataire_id')->constrained('utilisateurs')->onDelete('cascade');
            $table->decimal('montant', 15, 2);
            $table->enum('type_transfert', ['especes', 'mobile', 'virement']);
            $table->string('reference', 50)->nullable();
            $table->text('raison')->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transferts');
    }
};