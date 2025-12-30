<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stockhes', function (Blueprint $table) {
            $table->id();
            $table->decimal('stock_total', 10, 2)->default(0); 
            $table->decimal('stock_disponible', 10, 2)->default(0);
            $table->foreignId('utilisateur_id')
                ->nullable()
                ->constrained('utilisateurs')
                ->onDelete('cascade');
            $table->enum('niveau_stock', ['global', 'utilisateur'])->default('utilisateur');
            $table->timestamps();
            
            // Index
            $table->unique(['utilisateur_id', 'niveau_stock']);
            $table->index('utilisateur_id');
            $table->index('niveau_stock');
        });
        
        // Insérer le stock global (utilisateur_id = NULL)
        DB::table('stockhes')->insert([
            [
                'stock_total' => 0, 
                'stock_disponible' => 0,
                'utilisateur_id' => null,
                'niveau_stock' => 'global',
                'created_at' => now(),
                'updated_at' => now()
            ]
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('stockhes');
    }
};