<?php
// database/migrations/2024_04_03_000002_create_lignes_approvisionnements_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLignesApprovisionnementsTable extends Migration
{
    public function up()
    {
        Schema::create('lignes_approvisionnements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approvisionnement_id')->constrained('approvisionnements')->onDelete('cascade');
            $table->foreignId('produit_id')->constrained('produit')->onDelete('restrict');
            
            // Quantités
            $table->integer('quantite_demandee')->default(0);
            $table->integer('quantite_envoyee')->default(0);
            $table->integer('quantite_recue')->default(0);
            $table->integer('quantite_restante')->default(0);
            
            // Écarts
            $table->integer('quantite_casse')->default(0);
            $table->integer('quantite_perdue')->default(0);
            $table->integer('quantite_non_conforme')->default(0);
            $table->integer('quantite_conforme')->default(0);
            $table->text('motif_ecart')->nullable();
            
            // Intermédiaire
            $table->integer('quantite_arrivee_intermediaire')->default(0);
            $table->integer('quantite_depart_intermediaire')->default(0);
            $table->integer('perte_intermediaire')->default(0);
            $table->text('notes_intermediaire')->nullable();
            
            // Prix et lot
            $table->date('date_expiration_lot')->nullable();
            $table->string('numero_lot', 100)->nullable();
            $table->foreignId('lot_id')->nullable();
            
            // Métadonnées
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            // Index
            $table->index('approvisionnement_id');
            $table->index('produit_id');
            $table->index('lot_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('lignes_approvisionnements');
    }
}