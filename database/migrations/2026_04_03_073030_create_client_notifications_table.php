<?php
// database/migrations/2024_04_03_000001_create_client_notifications_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateClientNotificationsTable extends Migration
{
    public function up()
    {
        Schema::create('client_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->string('type'); // WELCOME, KYC_VALIDE, KYC_REJETE, COMMANDE, PAIEMENT, LIVRAISON
            $table->string('titre');
            $table->text('message');
            $table->json('data')->nullable();
            $table->boolean('est_lu')->default(false);
            $table->timestamp('lu_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            
            $table->index('client_id');
            $table->index('type');
            $table->index('est_lu');
            $table->index('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('client_notifications');
    }
}