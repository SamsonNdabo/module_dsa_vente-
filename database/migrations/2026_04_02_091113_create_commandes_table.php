<?php
// database/migrations/2024_01_01_000005_create_commandes_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCommandesTable extends Migration
{
    public function up()
    {
        Schema::create('commandes', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('client_id')->constrained('clients')->onDelete('restrict');
            $table->foreignId('succursale_id')->nullable()->constrained('succursales')->onDelete('set null');
            $table->enum('statut', [
                'PANIER', 'EN_ATTENTE_PAIEMENT', 'PAYEE', 'EN_PREPARATION', 
                'EXPEDIEE', 'EN_LIVRAISON', 'LIVREE', 'ANNULEE', 'REMBOURSEE'
            ])->default('PANIER');
            $table->decimal('sous_total', 15, 2)->default(0);
            $table->decimal('remise', 15, 2)->default(0);
            $table->decimal('frais_livraison', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->json('adresse_livraison')->nullable();
            $table->json('adresse_facturation')->nullable();
            $table->enum('mode_livraison', ['DOMICILE', 'POINT_RELAIS', 'RETRAIT_MAGASIN'])->default('DOMICILE');
            $table->string('point_relais_id')->nullable();
            $table->timestamp('date_commande')->default(now());
            $table->timestamp('date_paiement')->nullable();
            $table->timestamp('date_expedition')->nullable();
            $table->timestamp('date_livraison')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('reference');
            $table->index('client_id');
            $table->index('statut');
            $table->index('date_commande');
        });
    }

    public function down()
    {
        Schema::dropIfExists('commandes');
    }
}