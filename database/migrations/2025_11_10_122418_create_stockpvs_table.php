<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stockpvs', function (Blueprint $table) {
            $table->id();
            $table->enum('type_matiere', ['FG', 'CG', 'GG'])->unique();
            $table->decimal('stock_total', 10, 2)->default(0); 
            $table->decimal('stock_disponible', 10, 2)->default(0); 
            $table->timestamps(); 
            $table->index('type_matiere');
        });
        
        // Insérer les 3 lignes initiales pour chaque type
        DB::table('stockpvs')->insert([
            ['type_matiere' => 'FG', 'stock_total' => 0, 'stock_disponible' => 0],
            ['type_matiere' => 'CG', 'stock_total' => 0, 'stock_disponible' => 0],
            ['type_matiere' => 'GG', 'stock_total' => 0, 'stock_disponible' => 0],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stockpvs');
    }
};