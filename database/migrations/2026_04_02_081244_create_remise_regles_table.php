<?php
// database/migrations/2024_01_01_000004_create_remise_regles_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRemiseReglesTable extends Migration
{
    public function up()
    {
        Schema::create('remise_regles', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->text('description')->nullable();
            $table->enum('type', ['POURCENTAGE', 'FIXE']);
            $table->decimal('valeur', 15, 2);
            $table->enum('application', ['GLOBALE', 'PRODUIT', 'CATEGORIE']);
            $table->string('cible')->nullable(); // categorie_client, client_id, produit_id, categorie_produit
            $table->string('cible_valeur')->nullable();
            $table->decimal('montant_minimum', 15, 2)->default(0);
            $table->integer('nombre_achats_requis')->default(0);
            $table->decimal('montant_achats_requis', 15, 2)->default(0);
            $table->boolean('kyc_requis')->default(true);
            $table->date('date_debut')->nullable();
            $table->date('date_fin')->nullable();
            $table->boolean('actif')->default(true);
            $table->integer('priorite')->default(0);
            $table->json('conditions')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('actif');
            $table->index('type');
            $table->index('priorite');
        });
    }

    public function down()
    {
        Schema::dropIfExists('remise_regles');
    }
}