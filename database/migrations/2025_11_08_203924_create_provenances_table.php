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
        Schema::create('provenances', function (Blueprint $table) {
            $table->id();
            $table->string("Nom", '50');
            $table->timestamps();
        });

        
        $provenances = [
            ['Nom' => 'Manakara'],
            ['Nom' => 'Vangaindrano'],
            ['Nom' => 'Manambondro'],
            ['Nom' => 'Vohipeno'],
            ['Nom' => 'Ampasimandreva']
         
        ];

        DB::table('provenances')->insert($provenances);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provenances');
    }
};
