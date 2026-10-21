<?php
// database/migrations/2024_01_01_000005_create_categorie_produit_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorie_produit', function (Blueprint $table) {
            $table->id();
            $table->string('code', 150)->unique();
            $table->string('nom', 150)->unique();
            $table->text('description')->nullable();
            $table->boolean('statut')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('code');
            $table->index('nom');
            $table->index('statut');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorie_produit');
    }
};