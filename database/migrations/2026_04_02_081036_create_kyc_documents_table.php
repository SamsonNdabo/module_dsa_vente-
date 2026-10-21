<?php
// database/migrations/2024_01_01_000002_create_kyc_documents_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateKycDocumentsTable extends Migration
{
    public function up()
    {
        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->string('type_document'); // CIN, PASSEPORT, ATTESTATION
            $table->string('numero_document')->nullable();
            $table->string('file_path');
            $table->enum('statut', ['EN_ATTENTE', 'VERIFIE', 'REJETE'])->default('EN_ATTENTE');
            $table->text('motif_rejet')->nullable();
            $table->foreignId('verifie_par_id')->nullable()->constrained('utilisateurs')->onDelete('set null');
            $table->timestamps();
            
            $table->index('client_id');
            $table->index('statut');
        });
    }

    public function down()
    {
        Schema::dropIfExists('kyc_documents');
    }
}