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
            
            $table->timestamps();
        });
        
      
        DB::table('stockhes')->insert([
            ['stock_total' => 0, 'stock_disponible' => 0]
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('stockhes');
    }
};