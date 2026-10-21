<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approvisionnements', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 50)->unique();
            
            $table->enum('type_demande', ['APPROVISIONNEMENT', 'TRANSFERT'])
                  ->default('APPROVISIONNEMENT');
            
            // Sources
            $table->foreignId('depot_source_id')->nullable()->constrained('stock_succursale');
            $table->foreignId('stock_source_id')->nullable()->constrained('stock_succursale');
            
            // Destination
            $table->foreignId('point_vente_destination_id')->constrained('stock_succursale');
            
            // Point intermédiaire
            $table->foreignId('depot_intermediaire_id')->nullable()->constrained('stock_succursale');
            $table->foreignId('succursale_intermediaire_id')->nullable()->constrained('succursales');
            
            // Pour la prise en charge des demandes à l'aveugle
            $table->foreignId('depot_choisi_id')->nullable()->constrained('stock_succursale');
            
            // Quantités
            $table->integer('quantite_demandee')->default(0);
            $table->integer('quantite_envoyee')->default(0);
            $table->integer('quantite_recue')->default(0);
            $table->integer('quantite_restante')->default(0);
            
            // Écarts et pertes
            $table->integer('quantite_casse')->default(0);
            $table->integer('quantite_perdue')->default(0);
            $table->integer('quantite_non_conforme')->default(0);
            $table->integer('quantite_ecart')->default(0);
            $table->text('motif_ecart')->nullable();
            $table->json('details_ecarts')->nullable();
            
            // Point intermédiaire - quantités
            $table->integer('quantite_arrivee_intermediaire')->nullable();
            $table->integer('quantite_depart_intermediaire')->nullable();
            $table->integer('perte_intermediaire')->nullable();
            $table->text('notes_intermediaire')->nullable();
            
            // Statut enrichi
            $table->enum('statut', [
                'BROUILLON',
                'EN_ATTENTE',
                'EN_ATTENTE_PRISE_EN_CHARGE',
                'PRISE_EN_CHARGE',
                // 'VALIDE',
                'EN_COURS',
                'EXPEDIE',
                'EN_TRANSIT',
                'ARRIVE_INTERMEDIAIRE',
                'DEPART_INTERMEDIAIRE',
                'EN_ATTENTE_RECEPTION',
                'RECU_PARTIEL',
                'RECU',
                'ANNULE',
                'REFUSE'
            ])->default('BROUILLON');
            
            // Types de parcours
            $table->enum('type_appro', ['DIRECT', 'AVEC_INTERMEDIAIRE'])->nullable();
            $table->enum('type_transfert', ['DIRECT', 'AVEC_INTERMEDIAIRE'])->nullable();
            
            // Transport
            $table->string('transporteur', 100)->nullable();
            $table->string('numero_vehicule', 50)->nullable();
            $table->string('nom_chauffeur', 100)->nullable();
            $table->string('telephone_chauffeur', 20)->nullable();
            $table->string('numero_colis', 100)->nullable();
            $table->string('code_suivi', 100)->nullable();
            
            // Documents
            $table->string('num_bon_appro', 50)->nullable();
            $table->string('num_bon_livraison', 50)->nullable();
            $table->json('documents')->nullable();
            
            // Responsables
            $table->foreignId('demandeur_id')->constrained('utilisateurs');
            // $table->foreignId('valide_par_id')->nullable()->constrained('utilisateurs');
            $table->foreignId('preparateur_id')->nullable()->constrained('utilisateurs');
            $table->foreignId('expediteur_id')->nullable()->constrained('utilisateurs');
            $table->foreignId('recepteur_id')->nullable()->constrained('utilisateurs');
            $table->foreignId('responsable_intermediaire_id')->nullable()->constrained('utilisateurs');
            
            // Dates détaillées
            $table->timestamp('date_demande')->useCurrent();
            // $table->timestamp('date_validation')->nullable();
            $table->timestamp('date_preparation')->nullable();
            $table->timestamp('date_expedition')->nullable();
            $table->timestamp('date_arrivee_intermediaire')->nullable();
            $table->timestamp('date_depart_intermediaire')->nullable();
            $table->timestamp('date_prise_en_charge')->nullable();
            $table->timestamp('date_reception')->nullable();
            
            // Métadonnées
            $table->text('notes')->nullable();
            $table->text('motif_annulation')->nullable();
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            $table->softDeletes();

            // Index
            $table->index('reference');
            $table->index('statut');
            $table->index('date_demande');
            $table->index('depot_choisi_id');
            $table->index('date_prise_en_charge');
            $table->index('type_demande');
            $table->index('stock_source_id');
            $table->index('type_transfert');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approvisionnements');
    }
};