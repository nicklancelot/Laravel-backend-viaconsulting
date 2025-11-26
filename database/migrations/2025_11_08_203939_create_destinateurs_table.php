<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destinateurs', function (Blueprint $table) {
            $table->id();
            $table->string('nom_entreprise');
            $table->string('nom_prenom');
            $table->string('contact');
            $table->text('observation')->nullable();
            $table->foreignId('created_by')->constrained('utilisateurs')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destinateurs');
    }
};