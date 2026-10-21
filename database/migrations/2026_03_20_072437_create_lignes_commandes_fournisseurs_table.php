<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

    
class CreateLignesCommandesFournisseursTable extends Migration
{
    public function up()
    {
        Schema::create('lignes_commandes_fournisseurs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('commande_id');
            $table->unsignedBigInteger('produit_id');
            $table->string('libelle');
            $table->integer('quantite');
            $table->integer('quantite_recue')->default(0);
            $table->date('date_livraison_prevue')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('commande_id')->references('id')->on('commandes_fournisseurs')->onDelete('cascade');
            $table->foreign('produit_id')->references('id')->on('produit');            
            $table->index('commande_id');
            $table->index('produit_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('lignes_commandes_fournisseurs');
    }

};
