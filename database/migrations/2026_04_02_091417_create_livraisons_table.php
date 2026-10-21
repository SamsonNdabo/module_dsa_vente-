<?php
// database/migrations/2024_01_01_000007_create_livraisons_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLivraisonsTable extends Migration
{
    public function up()
    {
        Schema::create('livraisons', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('commande_id')->constrained('commandes')->onDelete('cascade');
            $table->foreignId('client_id')->constrained('clients')->onDelete('restrict');
            $table->enum('statut', [
                'EN_ATTENTE', 'PRISE_EN_CHARGE', 'EN_COURS', 'LIVREE', 'ECHOUEE', 'RETOURNEE'
            ])->default('EN_ATTENTE');
            $table->string('transporteur')->nullable();
            $table->string('numero_suivi')->nullable();
            $table->json('adresse_livraison');
            $table->string('nom_destinataire')->nullable();
            $table->string('telephone_destinataire');
            $table->text('instructions_livraison')->nullable();
            $table->timestamp('date_pris_en_charge')->nullable();
            $table->timestamp('date_livraison_estimee')->nullable();
            $table->timestamp('date_livraison_reelle')->nullable();
            $table->json('historique_statuts')->nullable();
            $table->json('preuve_livraison')->nullable(); // photo signature
            $table->text('motif_echec')->nullable();
            $table->foreignId('livreur_id')->nullable()->constrained('utilisateurs')->onDelete('set null');
            $table->timestamps();
            
            $table->index('reference');
            $table->index('commande_id');
            $table->index('statut');
            $table->index('numero_suivi');
        });
    }

    public function down()
    {
        Schema::dropIfExists('livraisons');
    }
}