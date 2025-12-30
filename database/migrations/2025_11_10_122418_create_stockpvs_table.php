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
            $table->enum('type_matiere', ['FG', 'CG', 'GG']);
            $table->decimal('stock_total', 10, 2)->default(0); 
            $table->decimal('stock_disponible', 10, 2)->default(0); 
            $table->foreignId('utilisateur_id')
                ->nullable()
                ->constrained('utilisateurs')
                ->onDelete('cascade');
            $table->enum('niveau_stock', ['global', 'utilisateur'])->default('utilisateur');
            $table->timestamps(); 
            
            // Index combiné pour éviter les doublons
            // Un utilisateur ne peut avoir qu'un seul stock par type
            $table->unique(['type_matiere', 'utilisateur_id']);
            $table->index('type_matiere');
            $table->index('utilisateur_id');
            $table->index('niveau_stock');
        });
        
        // Insérer les stocks globaux (utilisateur_id = NULL)
        DB::table('stockpvs')->insert([
            [
                'type_matiere' => 'FG', 
                'stock_total' => 0, 
                'stock_disponible' => 0,
                'utilisateur_id' => null,
                'niveau_stock' => 'global',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'type_matiere' => 'CG', 
                'stock_total' => 0, 
                'stock_disponible' => 0,
                'utilisateur_id' => null,
                'niveau_stock' => 'global',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'type_matiere' => 'GG', 
                'stock_total' => 0, 
                'stock_disponible' => 0,
                'utilisateur_id' => null,
                'niveau_stock' => 'global',
                'created_at' => now(),
                'updated_at' => now()
            ],
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