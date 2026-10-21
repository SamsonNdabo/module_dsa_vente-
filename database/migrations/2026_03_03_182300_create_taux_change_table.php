<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taux_change', function (Blueprint $table) {
            $table->id();
            $table->foreignId('devise_source')->constrained('devise')->cascadeOnDelete();
            $table->foreignId('devise_cible')->constrained('devise')->cascadeOnDelete();
            $table->decimal('taux', 15, 4);
            $table->decimal('ancien_taux', 15, 4)->nullable();
            $table->date('date_taux');
            $table->foreignId('modifie_par')->nullable()->constrained('utilisateur')->nullOnDelete();
            $table->string('motif', 191)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taux_change');
    }
};