<?php
// database/migrations/2024_01_01_000001_create_clients_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateClientsTable extends Migration
{
    public function up()
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('code_client')->unique();
            $table->string('nom_complet');
            $table->string('email')->unique();
            $table->string('telephone')->nullable();
            $table->string('password')->nullable();
            $table->text('adresse')->nullable();
            $table->string('categorie_client')->default('STANDARD'); // STANDARD, PREMIUM, VIP
            $table->enum('statut', ['ACTIF', 'INACTIF', 'BLOQUE', 'EN_ATTENTE'])->default('EN_ATTENTE');
            $table->integer('nombre_achats')->default(0);
            $table->decimal('total_achats', 15, 2)->default(0);
            $table->timestamp('date_dernier_achat')->nullable();
            $table->timestamp('dernier_connexion')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('email');
            $table->index('code_client');
            $table->index('statut');
            $table->index('categorie_client');
        });
    }

    public function down()
    {
        Schema::dropIfExists('clients');
    }
}