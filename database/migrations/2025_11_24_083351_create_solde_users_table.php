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
        Schema::create('solde_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')->constrained()->onDelete('cascade');
            $table->decimal('solde', 15, 2)->default(0);
            $table->timestamps();
            $table->unique('utilisateur_id'); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('solde_users');
    }
};
