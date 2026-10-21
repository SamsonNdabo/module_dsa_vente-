<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

    class CreateReceptionsFournisseursTable extends Migration
{
    public function up()
    {
        Schema::create('receptions_fournisseurs', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->unsignedBigInteger('commande_id');
            $table->unsignedBigInteger('fournisseur_id');
            $table->unsignedBigInteger('succursale_id')->nullable();
            $table->unsignedBigInteger('depot_destination_id')->nullable();
            $table->dateTime('date_reception');
            $table->string('num_bon_livraison', 100)->nullable();
            $table->string('num_facture_fournisseur', 100)->nullable();
            $table->integer('quantite_recue')->default(0);
            $table->date('date_facture')->nullable();
            $table->string('transporteur', 100)->nullable();
            $table->string('nom_livreur', 100)->nullable();
            $table->string('vehicule_immatriculation', 50)->nullable();
            $table->decimal('total_valeur_ht', 15, 2)->default(0);
            $table->decimal('total_valeur_tva', 15, 2)->default(0);
            $table->decimal('total_valeur_ttc', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('statut', 50)->default('EN_ATTENTE');
            $table->json('documents')->nullable();
            $table->json('metadata')->nullable();
            
            // Audit
            $table->unsignedBigInteger('cree_par_id');
            $table->unsignedBigInteger('valide_par_id')->nullable();
            $table->dateTime('date_validation')->nullable();
            
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('commande_id')->references('id')->on('commandes_fournisseurs');
            $table->foreign('fournisseur_id')->references('id')->on('fournisseur');
            $table->foreign('succursale_id')->references('id')->on('succursales');
            $table->foreign('cree_par_id')->references('id')->on('utilisateurs');
            $table->foreign('valide_par_id')->references('id')->on('utilisateurs');
            
            $table->index('reference');
            $table->index('statut');
            $table->index('date_reception');
        });
    }

    public function down()
    {
        Schema::dropIfExists('receptions_fournisseurs');
    }
};
