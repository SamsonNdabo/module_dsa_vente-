<?php
// database/migrations/2026_02_25_104254_create_caisses_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caisses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('nom_caisse', 100);
            $table->string('type_caisse', 100);
            $table->decimal('solde_initial', 15, 2)->default(0);
            $table->decimal('solde_courant', 15, 2)->default(0);
            $table->decimal('solde_minimal', 15, 2)->default(0);
            $table->decimal('solde_maximal', 15, 2)->nullable();
            $table->decimal('solde', 15, 2)->default(0);
            $table->foreignId('succursale_id')->constrained('succursales')->onDelete('restrict');
            $table->foreignId('devise_id')->constrained('devises')->onDelete('restrict');
            $table->foreignId('caissier_attribue')->nullable()->constrained('utilisateurs')->onDelete('set null');
            $table->enum('statut', ['OUVERTE', 'FERMEE', 'BLOQUEE'])->default('FERMEE');
            $table->timestamp('date_derniere_operation')->nullable();
            $table->boolean('inactif')->default(false);
            $table->softDeletes();
            $table->timestamps();
            
            // Index
            $table->index('code');
            $table->index('type_caisse');
            $table->index('statut');
            $table->index('succursale_id');
            $table->index('devise_id');
            $table->index('caissier_attribue');
            
            // Supprimer les contraintes CHECK qui causent l'erreur
            // Les validations seront faites dans le modèle
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caisses');
    }
};