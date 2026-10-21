<?php
// database/migrations/YYYY_MM_DD_HHMMSS_create_lignes_receptions_fournisseurs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLignesReceptionsFournisseursTable extends Migration
{
    public function up(): void
    {
        Schema::create('lignes_receptions_fournisseurs', function (Blueprint $table) {
            $table->id();
            
            // Relations
            $table->foreignId('reception_id')->constrained('receptions_fournisseurs')->onDelete('cascade');
            $table->foreignId('commande_ligne_id')->nullable()->constrained('lignes_commandes_fournisseurs')->nullOnDelete();
            $table->foreignId('produit_id')->constrained('produit')->onDelete('restrict');
            
            // Quantités
            $table->integer('quantite_attendue')->default(0);
            $table->integer('quantite_recue')->default(0);
            $table->integer('quantite_casse')->default(0);
            $table->integer('quantite_perdue')->default(0);
            $table->integer('quantite_non_conforme')->default(0);
            $table->decimal('prix_unitaire_ht', 15, 2)->nullable();
            $table->decimal('remise', 5, 2)->default(0);
            $table->decimal('taux_tva', 5, 2)->default(16);
            
            // FIFO
            $table->foreignId('lot_id')->nullable()->constrained('mouvements_stock', 'mouvement_id')->nullOnDelete();
            $table->date('date_expiration_lot')->nullable();
            
            // ✅ NOUVEAUX CHAMPS
            $table->string('numero_lot', 100)->nullable();  // Numéro de lot du fournisseur
            $table->json('metadata')->nullable();           // Métadonnées (bon de livraison, facture, etc.)
            
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Index
            $table->index('reception_id');
            $table->index('produit_id');
            $table->index('lot_id');
            $table->index('numero_lot');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_receptions_fournisseurs');
    }
}