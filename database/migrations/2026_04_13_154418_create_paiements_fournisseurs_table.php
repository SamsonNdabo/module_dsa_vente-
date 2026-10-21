<?php
// database/migrations/2026_01_15_000003_create_paiements_fournisseurs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePaiementsFournisseursTable extends Migration
{
    public function up()
    {
        Schema::create('paiements_fournisseurs', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('commande_id')->constrained('commandes_fournisseurs');
            $table->decimal('montant', 15, 2);
            $table->string('mode_paiement');
            $table->string('reference_transaction')->nullable();
            $table->date('date_paiement');
            $table->string('statut')->default('EN_ATTENTE');
            $table->text('notes')->nullable();
            $table->foreignId('cree_par_id')->constrained('utilisateurs');
            $table->foreignId('valide_par_id')->nullable()->constrained('utilisateurs');
            $table->datetime('date_validation')->nullable();
            $table->decimal('acompte', 15, 2)->default(0);
            $table->decimal('solde', 15, 2)->default(0);
            $table->date('date_echeance')->nullable();
            $table->decimal('penalites', 15, 2)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index('commande_id');
            $table->index('statut');
            $table->index('date_paiement');
        });
    }

    public function down()
    {
        Schema::dropIfExists('paiements_fournisseurs');
    }
}