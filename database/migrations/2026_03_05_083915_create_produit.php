<?php
// database/migrations/2026_03_05_083915_create_produit_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('produit', function (Blueprint $table) {
            $table->id();
            $table->string('code_barre', 50)->nullable()->unique();
            $table->string('nom', 200);
            $table->text('description')->nullable();
            $table->string('marque', 100)->nullable();
            $table->string('modele', 100)->nullable();
            
            // ✅ CHAMPS IMAGE
            $table->string('image_principale')->nullable();
            $table->json('images_gallerie')->nullable();
                        
            // Type de produit
            $table->enum('type', ['ELECTRONIQUE', 'ACCESSOIRE', 'ALIMENTAIRE', 'STANDARD'])
                ->default('STANDARD');
            
            // Prix
            $table->decimal('prix_vente', 15, 2)->default(0);
            $table->decimal('prix_vente_TTC', 15, 2)->nullable();
            
            // Gestion de stock
            $table->integer('stock_minimum')->default(0);
            
            // ✅ Indicateur produit périssable
            $table->boolean('est_perissable')->default(false);
            $table->date('date_fabrication')->nullable();
            $table->date('date_expiration')->nullable();
            
            // Relations
            $table->unsignedBigInteger('categorie_id')->nullable();
            $table->unsignedBigInteger('devise_id')->nullable();
            
            // Statut
            $table->boolean('actif')->default(true);
            
            $table->timestamps();
            $table->softDeletes();

            // Index
            $table->index('code_barre');
            $table->index('nom');
            $table->index('actif');
            $table->index('type');
            $table->index('est_perissable');
            
            $table->foreign('categorie_id')
                ->references('id')
                ->on('categorie_produit')
                ->onDelete('set null');
                
            $table->foreign('devise_id')
                ->references('id')
                ->on('devise')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('produit');
    }
};