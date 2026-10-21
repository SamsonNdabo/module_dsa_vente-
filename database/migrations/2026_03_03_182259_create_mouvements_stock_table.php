<?php
// database/migrations/2024_01_01_000001_create_mouvements_stock_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mouvements_stock', function (Blueprint $table) {
            $table->id('mouvement_id');
            $table->string('reference_mouvement', 50)->unique();
            
            // Relations de base
            $table->foreignId('succursale_id')->nullable()->constrained('succursales')->nullOnDelete();
            $table->foreignId('produit_id')->nullable()->constrained('produit')->nullOnDelete();
            
            // Stocks source et destination
            $table->foreignId('stock_source_id')->nullable()->constrained('stock_succursale')->nullOnDelete();
            $table->foreignId('stock_destination_id')->nullable()->constrained('stock_succursale')->nullOnDelete();
            
            // ✅ LIEN VERS APPROVISIONNEMENT/TRANSFERT (nouveau système unifié)
            $table->foreignId('demande_parent_id')->nullable()->constrained('approvisionnements')->nullOnDelete();
            
            // ✅ LIEN PARENT pour chaînage des mouvements
            $table->unsignedBigInteger('mouvement_parent_id')->nullable();
            $table->foreign('mouvement_parent_id')
                  ->references('mouvement_id')
                  ->on('mouvements_stock')
                  ->onDelete('set null');
            
            // Lien origine pour FIFO
            $table->unsignedBigInteger('mouvement_origine_id')->nullable();
            $table->foreign('mouvement_origine_id')
                  ->references('mouvement_id')
                  ->on('mouvements_stock')
                  ->onDelete('set null');
            
            // Type de mouvement (simplifié)
            $table->enum('type_mouvement', [
                'ENTREE', 'SORTIE', 'TRANSFERT', 'RETOUR', 
                'AJUSTEMENT', 'INVENTAIRE'
            ]);
            
            // Statut (simplifié)
            $table->enum('statut', [
                'BROUILLON', 'VALIDE', 'ANNULE', 'REFUSE'
            ])->default('BROUILLON');
            
            // Quantités
            $table->integer('quantite')->default(0);
            $table->integer('quantite_avant')->default(0);
            $table->integer('quantite_apres')->default(0);
            
            // Écarts (pour les réceptions)
            $table->integer('quantite_casse')->default(0);
            $table->integer('quantite_perdue')->default(0);
            $table->integer('quantite_non_conforme')->default(0);
            $table->text('motif_ecart')->nullable();
            
            // FIFO
            $table->json('lots_entree')->nullable();
            $table->json('lots_sortie')->nullable();
            $table->timestamp('date_lot')->nullable();
            $table->date('date_expiration_lot')->nullable();
            $table->decimal('prix_achat_lot', 15, 2)->nullable();
            $table->integer('quantite_restante_lot')->nullable();
            $table->boolean('est_lot_fifo')->default(false);
            
            // Prix
            $table->decimal('cout_unitaire', 15, 2)->nullable();
            $table->decimal('cout_total', 15, 2)->nullable();
            
            // Transport
            $table->string('transporteur', 100)->nullable();
            $table->string('numero_vehicule', 50)->nullable();
            $table->string('nom_chauffeur', 100)->nullable();
            $table->string('telephone_chauffeur', 20)->nullable();
            $table->string('numero_colis', 100)->nullable();
            
            // Documents
            $table->string('reference_document', 50)->nullable();
            
            // Texte
            $table->text('raison')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            
            // Responsables
            $table->foreignId('responsable_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->foreignId('valide_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            
            // Dates
            $table->timestamp('date_validation')->nullable();
            $table->timestamp('date_mouvement')->useCurrent();
            
            $table->timestamps();
            $table->softDeletes();

            // Index
            $table->index(['succursale_id', 'produit_id']);
            $table->index('date_mouvement');
            $table->index('type_mouvement');
            $table->index('statut');
            $table->index('reference_mouvement');
            $table->index('date_lot');
            $table->index('demande_parent_id');
            $table->index('mouvement_parent_id');
            $table->index(['produit_id', 'date_expiration_lot', 'quantite_restante_lot'], 'fifo_expiration_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvements_stock');
    }
};