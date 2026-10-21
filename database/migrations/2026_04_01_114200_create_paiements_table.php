<?php
// database/migrations/2024_01_01_000003_create_paiements_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePaiementsTable extends Migration
{
    public function up()
    {
        Schema::create('paiements', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('facture_id')->constrained('factures')->onDelete('cascade');
            $table->foreignId('commande_id')->nullable();
            $table->foreignId('transaction_mobile_id')->nullable();
            $table->foreignId('caisse_id')->constrained('caisses')->onDelete('restrict');
            $table->foreignId('utilisateur_id')->constrained('utilisateurs')->onDelete('restrict');
            $table->foreignId('client_id')->constrained('clients')->onDelete('restrict');
            $table->enum('mode_paiement', ['ESPECES', 'CARTE_BANCAIRE', 'CHEQUE', 'VIREMENT', 'MOBILE_MONEY', 'CREDIT', 'MIXTE']);
            $table->decimal('montant', 15, 2);
            $table->decimal('montant_rendu', 15, 2)->default(0);
            $table->dateTime('date_paiement');
            $table->enum('statut', ['EN_ATTENTE', 'COMPLET', 'ECHOUE', 'REMBOURSE'])->default('EN_ATTENTE');
            $table->string('reference_externe')->nullable(); // Référence du paiement externe (transaction bancaire, etc.)
            $table->json('details_paiement')->nullable(); // Détails selon le mode (numéro chèque, etc.)
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index('reference');
            $table->index('facture_id');
            $table->index('date_paiement');
            $table->index('statut');
        });
    }

    public function down()
    {
        Schema::dropIfExists('paiements');
    }
}