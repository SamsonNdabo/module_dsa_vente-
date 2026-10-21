<?php
// database/migrations/2024_04_03_000001_create_journal_caisses_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateJournalCaissesTable extends Migration
{
    public function up()
    {
        Schema::create('journal_caisses', function (Blueprint $table) {
            $table->id();
            
            // Relations
            $table->foreignId('caisse_id')->constrained('caisses')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('utilisateurs')->onDelete('restrict');
            
            // Type d'opération
            $table->enum('type_operation', [
                'OUVERTURE', 'FERMETURE', 'AJUSTEMENT', 
                'TRANSFERT', 'ENCAISSEMENT', 'DECAISSEMENT'
            ])->default('OUVERTURE');
            
            // Soldes
            $table->decimal('solde_ouverture', 15, 2)->default(0);
            $table->decimal('solde_fermeture', 15, 2)->nullable();
            $table->decimal('solde_theorique', 15, 2)->nullable();
            $table->decimal('solde_reel', 15, 2)->nullable();
            $table->decimal('ecart', 15, 2)->nullable();
            $table->text('justification_ecart')->nullable();
            
            // Montants
            $table->decimal('montant_initial', 15, 2)->default(0);
            $table->decimal('montant_final', 15, 2)->nullable();
            
            // Documents
            $table->json('document_upload')->nullable();
            
            // Dates
            $table->timestamp('date_debut')->useCurrent();
            $table->timestamp('date_fin')->nullable();
            
            // Statut
            $table->enum('statut', ['EN_COURS', 'TERMINEE', 'ANOMALIE'])->default('EN_COURS');
            
            // Métadonnées
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            // Index
            $table->index('caisse_id');
            $table->index('user_id');
            $table->index('type_operation');
            $table->index('statut');
            $table->index('date_debut');
        });
    }

    public function down()
    {
        Schema::dropIfExists('journal_caisses');
    }
}