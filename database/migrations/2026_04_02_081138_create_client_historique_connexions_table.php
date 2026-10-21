<?php
// database/migrations/2024_01_01_000003_create_client_historique_connexions_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateClientHistoriqueConnexionsTable extends Migration
{
    public function up()
    {
        Schema::create('client_historique_connexions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->string('ip_address')->nullable();
            $table->timestamp('login_at');
            $table->boolean('success')->default(true);
            $table->timestamps();
            
            $table->index('client_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('client_historique_connexions');
    }
}