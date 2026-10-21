<?php
// database/migrations/[timestamp]_create_audit_systeme_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_systeme', function (Blueprint $table) {
            $table->id();
            $table->string('table_affectee', 100);
            // ✅ Remplacer enum par string pour permettre l'index
            $table->string('type_action', 50); // Plus flexible et indexable
            $table->unsignedBigInteger('id_enregistrement')->nullable();
            $table->json('ancienne_valeur')->nullable();
            $table->json('nouvelle_valeur')->nullable();
            $table->unsignedBigInteger('id_utilisateur')->nullable();
            $table->string('adresse_ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('description')->nullable();
            $table->string('niveau_importance', 20)->default('INFO');
            $table->timestamps();
            
            $table->foreign('id_utilisateur')->references('id')->on('utilisateurs')->onDelete('set null');
            $table->index('table_affectee');
            $table->index('type_action'); // ✅ Maintenant ça fonctionne
            $table->index('id_enregistrement');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_systeme');
    }
};