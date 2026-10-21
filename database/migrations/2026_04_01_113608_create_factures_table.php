<?php
// database/migrations/2024_01_01_000001_create_factures_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFacturesTable extends Migration
{
    public function up()
    {
        Schema::create('factures', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('client_id')->constrained('clients')->onDelete('restrict');
            $table->foreignId('utilisateur_id')->constrained('utilisateurs')->onDelete('restrict');
            $table->foreignId('succursale_id')->constrained('succursales')->onDelete('restrict');
            $table->foreignId('caisse_id')->nullable()->constrained('caisses')->onDelete('set null');
            $table->dateTime('date_facture');
            $table->enum('statut', ['BROUILLON', 'EN_COURS', 'VALIDEE', 'ANNULEE', 'REMBOURSEE', 'PARTIELLE'])->default('BROUILLON');
            $table->enum('type_facture', ['VENTE', 'AVOIR', 'DEVIS'])->default('VENTE');
            $table->decimal('sous_total', 15, 2)->default(0);
            $table->decimal('remise_globale', 15, 2)->default(0);
            $table->enum('type_remise', ['FIXE', 'POURCENTAGE'])->nullable();
            $table->decimal('total_remise', 15, 2)->default(0);
            $table->decimal('total_ht', 15, 2)->default(0);
            $table->decimal('taux_tva', 5, 2)->default(16);
            $table->decimal('total_tva', 15, 2)->default(0);
            $table->decimal('total_ttc', 15, 2)->default(0);
            $table->decimal('avance', 15, 2)->default(0);
            $table->decimal('reste_a_payer', 15, 2)->default(0);
            $table->decimal('montant_regle', 15, 2)->default(0);
            $table->decimal('montant_du', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->text('conditions_paiement')->nullable();
            $table->date('date_echeance')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Index pour optimisation
            $table->index('reference');
            $table->index('date_facture');
            $table->index('statut');
            $table->index('client_id');
            $table->index('succursale_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('factures');
    }
}