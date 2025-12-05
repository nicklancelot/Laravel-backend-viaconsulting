<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    
public function up(): void
{
    Schema::create('caissiers', function (Blueprint $table) {
        $table->id();
        $table->foreignId('utilisateur_id')->constrained('utilisateurs')->onDelete('cascade');
        $table->decimal('solde', 15, 2)->default(0);
        $table->date('date');
        $table->decimal('montant', 15, 2)->default(0);
        $table->enum('type', ['revenu','depense']);
        $table->string('raison', 100)->nullable();
        $table->string('methode', 50);
        $table->string('reference', 50)->nullable();
        $table->timestamps();
       });
         DB::table('caissiers')->insert([
                'utilisateur_id' => 1,
                'solde' => '50000000',
                'date' => now()->toDateString(),
                'montant' => 0,
                'type' => 'revenu',
                'methode' => 'initialisation',
                'reference' => 'SOLDE_INITIAL',
                'created_at' => now(),
                'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('caissiers');
    }
};

//teste MAJ