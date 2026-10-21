<?php
// database/migrations/2014_10_12_000000_create_users_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utilisateurs', function (Blueprint $table) {
            $table->id();
            $table->string('matricule', 50)->unique(); // Réduire à 50
            $table->string('nom_complet', 150);
            $table->string('email',200)->unique();
            $table->string('telephone', 20)->nullable();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->unsignedBigInteger('succursale_id')->nullable();
            $table->string('nom_utilisateur', 50)->unique(); // Réduire à 50
            $table->string('password_hash',100);
            $table->string('salt', 100)->nullable();
            $table->string('two_factor_secret')->nullable();
            $table->boolean('two_factor_authentification')->default(false);
            $table->timestamp('date_derniere_connexion')->nullable();
            $table->date('date_expiration_mdp')->nullable();
            $table->boolean('compte_verrouille')->default(false);
            $table->integer('tentatives_echec')->default(0);
            $table->string('statut',50)->default('ACTIF');
            $table->string('photo_profil',150)->nullable();
            $table->json('preferences')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('role_id')->references('id')->on('roles');
            $table->foreign('succursale_id')->references('id')->on('succursales');
            
            // Index avec longueur spécifiée pour les champs longs
            $table->index(['nom_complet'], 'utilisateurs_nom_complet_index'); // Pas besoin de longueur pour l'index simple
            $table->index('email'); // Email est déjà unique
            $table->index('nom_utilisateur'); // Déjà unique
            $table->index('statut');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utilisateurs');
    }
};