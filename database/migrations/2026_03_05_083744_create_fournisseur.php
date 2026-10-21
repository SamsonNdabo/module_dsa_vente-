<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fournisseur', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 150);
            $table->string('email', 150)->nullable()->unique();
            $table->string('telephone', 50)->nullable();
            $table->text('adresse')->nullable();
            $table->json('ville')->nullable();
            $table->json('produits')->nullable();
            $table->enum('statut', ['ACTIF', 'INACTIF'])->default('ACTIF');
            $table->string('code_fournisseur', 50)->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fournisseur');
    }
};