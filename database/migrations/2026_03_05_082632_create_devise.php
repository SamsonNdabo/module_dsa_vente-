<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devise', function (Blueprint $table) {
            $table->id();
            $table->string('code', 15)->unique();
            $table->string('nom', 50)->unique();
            $table->string('symbole', 10)->nullable()->unique();
            $table->decimal('taux', 15, 4)->default(1.0000);
            $table->boolean('devise_defaut')->default(0)->index();
            $table->integer('decimaux')->default(2);
            $table->enum('position_symbole', ['avant', 'apres'])->default('avant');
            $table->enum('statut', ['ACTIF', 'INACTIF'])->default('ACTIF');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devise');
    }
};