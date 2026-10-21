<?php
// database/migrations/2024_01_01_000002_create_stock_succursale_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_succursale', function (Blueprint $table) {
            $table->id();
            $table->foreignId('succursale_id')->constrained('succursales')->onDelete('cascade');
            $table->foreignId('produit_id')->constrained('produit')->onDelete('cascade');
            
            // Types de stock
            $table->boolean('is_depot')->default(false);
            $table->foreignId('depot_source_id')->nullable()->constrained('stock_succursale')->nullOnDelete();
            
            // Quantités
            $table->integer('quantite_disponible')->default(0);
            $table->integer('quantite_reserve')->default(0);
            $table->integer('quantite_commande')->default(0);
            $table->integer('quantite_approvisionnement')->default(0);
            $table->integer('quantite_maximale')->nullable();
            $table->integer('quantite_minimale')->default(0);
            $table->integer('quantite_securite')->default(0);
            
            // Emplacement
            $table->string('emplacement', 100)->nullable();
            $table->string('zone_stockage', 100)->nullable();
            $table->string('rayon', 50)->nullable();
            $table->string('etagere', 50)->nullable();
            
            // Dates
            $table->date('date_dernier_entree')->nullable();
            $table->date('date_dernier_sortie')->nullable();
            $table->date('date_dernier_inventaire')->nullable();
            $table->date('date_alerte_stock')->nullable();
            $table->date('date_prochaine_appro')->nullable();
            
            // Statistiques
            $table->integer('rotation_stock')->default(0);
            $table->integer('stock_moyen')->default(0);
            
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['succursale_id', 'produit_id']);
            $table->index('quantite_disponible');
            $table->index('date_alerte_stock');
            $table->index('is_depot');
            $table->index('depot_source_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_succursale');
    }
};