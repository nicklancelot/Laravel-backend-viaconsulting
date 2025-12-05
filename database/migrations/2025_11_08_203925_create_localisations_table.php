<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('localisations', function (Blueprint $table) {
            $table->id();
            $table->string("Nom", '50');
            $table->timestamps();
        });

        // Insertion des données initiales
        $localisations = [
            ['Nom' => 'Manakara'],
            ['Nom' => 'Vangaindrano'],
            ['Nom' => 'Manambondro'],
            ['Nom' => 'Vohipeno'],
            ['Nom' => 'Ampasimandreva']
         
        ];

        DB::table('localisations')->insert($localisations);
    }

    public function down(): void
    {
        Schema::dropIfExists('localisations');
    }
};

