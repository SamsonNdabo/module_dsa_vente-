<?php
// database/migrations/2026_01_15_000002_create_devis_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDevisTables extends Migration
{
    public function up()
    {
        Schema::create('devis_fournisseurs', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('rfq_id')->nullable()->constrained('rfq_fournisseurs');
            $table->foreignId('fournisseur_id')->constrained('fournisseur');
            $table->date('date_devis');
            $table->date('date_validite')->nullable();
            $table->string('statut')->default('RECU');
            $table->decimal('frais_livraison', 15, 2)->default(0);
            $table->decimal('remise_globale', 5, 2)->default(0);
            $table->string('conditions_paiement')->nullable();
            $table->string('delai_livraison')->nullable();
            $table->text('notes')->nullable();
            $table->text('notes_internes')->nullable();
            $table->foreignId('valide_par_id')->nullable()->constrained('utilisateurs');
            $table->datetime('date_validation')->nullable();
            $table->string('fichier_pdf')->nullable();
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();
            
            $table->index('statut');
            $table->index('fournisseur_id');
        });

        // Table Devis Lignes
        Schema::create('devis_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('devis_id')->constrained('devis_fournisseurs')->onDelete('cascade');
            $table->foreignId('produit_id')->nullable()->constrained('produit');
            $table->foreignId('rfq_ligne_id')->nullable()->constrained('rfq_lignes');
            $table->string('libelle');
            $table->integer('quantite');
            $table->decimal('prix_unitaire_ht', 15, 2);
            $table->decimal('remise_ligne', 5, 2)->default(0);
            $table->decimal('taux_tva', 5, 2)->default(16);
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

    }

    public function down()
    {
        Schema::table('commandes_fournisseurs', function (Blueprint $table) {
            $table->dropForeign(['devis_id']);
            $table->dropColumn('devis_id');
        });
        Schema::dropIfExists('devis_lignes');
        Schema::dropIfExists('devis_fournisseurs');
    }
}