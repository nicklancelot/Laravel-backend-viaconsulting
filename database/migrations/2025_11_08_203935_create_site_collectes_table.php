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
        Schema::create('site_collectes', function (Blueprint $table) {
            $table->id();
            $table->string("Nom", '50');
            $table->timestamps();
        });
    
        $sitecollecte = [
            ['Nom' => 'PK 12'],
            ['Nom' => 'Lokomby']
           
        ];

        DB::table('site_collectes')->insert($sitecollecte);
    }


    public function down(): void
    {
        Schema::dropIfExists('site_collectes');
    }
};
