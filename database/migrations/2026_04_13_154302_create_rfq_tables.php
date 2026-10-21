<?php
// database/migrations/2026_01_15_000001_create_rfq_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRfqTables extends Migration
{
    public function up()
    {
        // Table RFQ
        Schema::create('rfq_fournisseurs', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('fournisseur_id')->constrained('fournisseur');
            $table->datetime('date_rfq');
            $table->date('date_reponse_souhaitee')->nullable();
            $table->string('statut')->default('BROUILLON');
            $table->text('notes')->nullable();
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs');
            $table->foreignId('envoye_par_id')->nullable()->constrained('utilisateurs');
            $table->datetime('date_envoi')->nullable();
            $table->json('documents')->nullable();
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();
            
            $table->index('statut');
            $table->index('fournisseur_id');
        });

        // Table RFQ Lignes
        Schema::create('rfq_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained('rfq_fournisseurs')->onDelete('cascade');
            $table->foreignId('produit_id')->nullable()->constrained('produit');
            $table->string('libelle');
            $table->integer('quantite');
            $table->string('unite')->nullable();
            $table->date('date_livraison_souhaitee')->nullable();
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('rfq_lignes');
        Schema::dropIfExists('rfq_fournisseurs');
    }
}