<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parametres_systeme', function (Blueprint $table) {
            $table->id();
            $table->string('categorie', 50);
            $table->string('cle', 100);
            $table->text('valeur');
            $table->enum('type_valeur', ['STRING', 'INTEGER', 'DECIMAL', 'BOOLEAN', 'JSON', 'DATE'])->default('STRING');
            $table->text('description')->nullable();
            $table->boolean('modifiable')->default(true);
            $table->boolean('visible')->default(true);
            $table->timestamps();
            
            $table->unique(['categorie', 'cle']);
            $table->index('categorie');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parametres_systeme');
    }
};