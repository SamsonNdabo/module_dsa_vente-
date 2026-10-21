<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('succursales', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('nom', 150);
            $table->string('type', 150)->nullable();
            $table->string('telephone', 50)->nullable();
            $table->string('email', 150)->nullable();
            $table->unsignedBigInteger('responsable_id')->nullable();
            $table->text('adresse')->nullable();
            $table->string('ville', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('pays', 100)->default('RDC');
            $table->string('code_postal', 20)->nullable();
            $table->json('coordonnees_gps')->nullable();
            $table->time('heure_ouverture')->nullable();
            $table->time('heure_fermeture')->nullable();
            $table->boolean('est_principale')->default(false);
            $table->json('configuration')->nullable();
            $table->json('jours_ouverture')->nullable();
            $table->integer('capacite_maximale')->nullable();
            $table->integer('nombre_employes')->default(0);
            $table->string('zone_geo', 100)->nullable();
            $table->enum('statut', ['ACTIVE', 'INACTIVE', 'MAINTENANCE'])->default('ACTIVE');
            $table->boolean('est_active')->default(true);
            $table->timestamp('date_activation')->nullable();
            $table->timestamp('date_desactivation')->nullable();
            $table->text('motif_desactivation')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('responsable_id')->references('id')->on('utilisateurs')->onDelete('set null');
            $table->index('code');
            $table->index('ville');
            $table->index('statut');
            $table->index('zone_geo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('succursales');
    }
};