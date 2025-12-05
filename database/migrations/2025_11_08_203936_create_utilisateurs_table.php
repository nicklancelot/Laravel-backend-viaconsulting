<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utilisateurs', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 100);
            $table->string('prenom', 100);
            $table->string('numero', 15);
            $table->foreignId('localisation_id')->constrained()->onDelete('cascade');
            $table->string('CIN', 20);
            $table->string('password');
            $table->enum('role', ['admin', 'collecteur', 'vendeur', 'distilleur'])->default('collecteur');
            $table->rememberToken();
            $table->timestamps();
        });

        
        DB::table('utilisateurs')->insert([
            'nom' => 'Admin',
            'prenom' => 'Système',
            'numero' => '0331207216',
            'localisation_id' => 1, 
            'CIN' => '51201100394',
            'password' => Hash::make('admin123'),
            'role' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('utilisateurs');
    }
};
