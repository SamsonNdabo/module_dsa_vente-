<?php
// database/migrations/2024_01_01_000002_create_lignes_facture_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLignesFacturesTable extends Migration
{
    public function up()
    {
        Schema::create('lignes_factures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facture_id')->constrained('factures')->onDelete('cascade');
            $table->foreignId('produit_id')->constrained('produit')->onDelete('restrict');
            $table->foreignId('stock_succursale_id')->constrained('stock_succursale')->onDelete('restrict');
            $table->integer('quantite');
            $table->decimal('prix_unitaire_ht', 15, 2);
            $table->decimal('prix_unitaire_ttc', 15, 2);
            $table->decimal('remise_ligne', 15, 2)->default(0);
            $table->enum('type_remise_ligne', ['FIXE', 'POURCENTAGE'])->nullable();
            $table->decimal('total_ht', 15, 2);
            $table->decimal('total_ttc', 15, 2);
            $table->json('lots_fifo_utilises')->nullable(); // Pour traçabilité FIFO
            $table->json('imeis_utilises')->nullable(); // Pour les produits avec IMEI
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index('facture_id');
            $table->index('produit_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('lignes_facture');
    }
}