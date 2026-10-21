<?php
// database/migrations/2024_01_01_000006_create_lignes_commande_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLignesCommandeTable extends Migration
{
    public function up()
    {
        Schema::create('lignes_commande', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commande_id')->constrained('commandes')->onDelete('cascade');
            $table->foreignId('produit_id')->constrained('produit')->onDelete('restrict');
            $table->integer('quantite');
            $table->decimal('prix_unitaire', 15, 2);
            $table->decimal('remise_ligne', 15, 2)->default(0);
            $table->decimal('total', 15, 2);
            $table->json('lots_fifo_utilises')->nullable();
            $table->json('imeis_utilises')->nullable();
            $table->timestamps();
            
            $table->index('commande_id');
            $table->index('produit_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('lignes_commande');
    }
}