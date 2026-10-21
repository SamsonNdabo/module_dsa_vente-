<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('commandes_fournisseurs', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            
            // ✅ Liaison avec devis (important pour le workflow)
            $table->unsignedBigInteger('devis_id')->nullable();
            
            $table->unsignedBigInteger('fournisseur_id');
            $table->unsignedBigInteger('depot_destination_id');  
            $table->dateTime('date_commande');
            $table->date('date_livraison_prevue')->nullable();
            $table->dateTime('date_livraison_reelle')->nullable();
            $table->string('statut', 50)->default('BROUILLON');
            $table->text('conditions_paiement')->nullable();
            $table->string('mode_livraison', 100)->nullable();
            $table->string('transporteur', 100)->nullable();
            $table->string('numero_suivi', 100)->nullable();
            $table->text('notes')->nullable();
            $table->text('notes_internes')->nullable();
            $table->unsignedBigInteger('devise_id')->default(1);
            $table->json('documents')->nullable();
            $table->json('metadata')->nullable();
            
            // ✅ Champs pour le suivi des montants (pour accès rapide)
            $table->decimal('montant_total_ht', 15, 2)->default(0);
            $table->decimal('montant_total_tva', 15, 2)->default(0);
            $table->decimal('montant_total_ttc', 15, 2)->default(0);
            $table->decimal('montant_paye', 15, 2)->default(0);
            $table->decimal('montant_restant', 15, 2)->default(0);
            $table->enum('statut_paiement', ['NON_PAYE', 'PARTIEL', 'PAYE'])->default('NON_PAYE');
            
            // ✅ Champs d'audit
            $table->unsignedBigInteger('cree_par_id')->nullable();
            $table->unsignedBigInteger('valide_par_id')->nullable();
            $table->dateTime('date_validation')->nullable();
            $table->unsignedBigInteger('annule_par_id')->nullable();
            $table->string('motif_annulation')->nullable();
            $table->unsignedBigInteger('reception_par_id')->nullable();
            $table->dateTime('date_reception')->nullable();
            
            $table->timestamps();
            $table->softDeletes();

            // ✅ Clés étrangères
            $table->foreign('devis_id')->references('id')->on('devis_fournisseurs')->onDelete('set null');
            $table->foreign('fournisseur_id')->references('id')->on('fournisseur');
            $table->foreign('depot_destination_id')->references('id')->on('stock_succursale');
            $table->foreign('devise_id')->references('id')->on('devise');
            
            // ✅ Index pour performances
            $table->index('reference');
            $table->index('statut');
            $table->index('statut_paiement');
            $table->index('depot_destination_id');
            $table->index('fournisseur_id');
            $table->index('devis_id');
            $table->index('date_commande');
        });
    }

    public function down()
    {
        Schema::dropIfExists('commandes_fournisseurs');
    }
};