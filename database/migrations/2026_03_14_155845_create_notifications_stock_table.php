<?php
// database/migrations/2024_01_01_000004_create_notifications_stock_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications_stock', function (Blueprint $table) {
            $table->id();
            
            // ✅ Destinataires (amélioré)
            $table->foreignId('utilisateur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->json('utilisateur_ids')->nullable(); // Pour plusieurs utilisateurs
            $table->string('destinataire_type', 50)->default('utilisateur'); // utilisateur, multiple, succursale, role, public
            $table->string('role_cible', 50)->nullable(); // Pour les notifications par rôle
            
            // ✅ Contexte
            $table->foreignId('succursale_id')->nullable()->constrained('succursales')->nullOnDelete();
            $table->foreignId('produit_id')->nullable()->constrained('produit')->nullOnDelete();
            $table->foreignId('mouvement_id')->nullable()->constrained('mouvements_stock', 'mouvement_id')->nullOnDelete();
            $table->foreignId('transfert_id')->nullable()->constrained('transferts_stock')->nullOnDelete();
            $table->foreignId('approvisionnement_id')->nullable()->constrained('approvisionnements')->nullOnDelete();
            
            // ✅ Type de notification (enrichi avec tous les types nécessaires)
            $table->enum('type', [
                // Alertes stock
                'ALERTE_STOCK', 
                'RUPTURE', 
                'RUPTURE_STOCK',
                'SEUIL_CRITIQUE', 
                'RETOUR_NORMAL',
                'LOT_PROCHAIN',
                'LOT_CRITIQUE',
                'LOT_EXPIRE',
                'LOTS_EXPIRES_TROUVES',
                'LOT_ENTREE', 
                'LOT_SORTIE', 
                'LOT_EPUISE', 
                'LOT_EXPIRATION_PROCHAINE',
                
                // Approvisionnements
                'DEMANDE_APPROVISIONNEMENT',
                'APPROVISIONNEMENT_CREE', 
                'APPROVISIONNEMENT_VALIDE',
                'APPROVISIONNEMENT_PREPARE',
                'APPROVISIONNEMENT_EXPEDIE', 
                'APPROVISIONNEMENT_RECU',
                'APPROVISIONNEMENT_ANNULE',
                
                // Mouvements
                'MOUVEMENT_CREE', 
                'MOUVEMENT_VALIDE', 
                'MOUVEMENT_ANNULE',
                
                // Achats fournisseurs
                'ACHAT_CREE', 
                'ACHAT_VALIDE', 
                'ACHAT_RECU', 
                'ACHAT_ANNULE',
                
                // Paiements
                'PAIEMENT_EFFECTUE', 
                'PAIEMENT_VALIDE', 
                'PAIEMENT_ECHOUE',
                
                // Inventaire
                'INVENTAIRE_REALISE', 
                'ECART_INVENTAIRE',
                
                // Ventes
                'VENTE_EFFECTUEE', 
                'VENTE_ANNULEE', 
                'FACTURE_EMISE', 
                'FACTURE_PAYEE',
                
                // Transferts
                'DEMANDE_TRANSFERT', 
                'TRANSFERT_PREPARE', 
                'TRANSFERT_EXPEDIE',
                'TRANSFERT_EN_TRANSIT', 
                'TRANSFERT_INTERMEDIAIRE', 
                'TRANSFERT_EN_ROUTE',
                'TRANSFERT_RECU', 
                'TRANSFERT_ANNULE', 
                'ECART_TRANSFERT', 
                'RAPPEL_TRANSFERT',
            ]);
            
            // ✅ Contenu
            $table->string('titre');
            $table->text('message');
            $table->json('donnees')->nullable();
            
            // ✅ Lecture
            $table->boolean('est_lue')->default(false);
            $table->timestamp('date_lecture')->nullable();
            
            // ✅ Email
            $table->boolean('est_envoyee_email')->default(false);
            $table->timestamp('date_envoi_email')->nullable();
            $table->string('email_destinataire')->nullable(); // Email spécifique si différent
            
            // ✅ Priorité
            $table->enum('priorite', ['BASSE', 'NORMALE', 'HAUTE', 'URGENTE'])->default('NORMALE');
            
            // ✅ Actions possibles
            $table->json('actions')->nullable(); // Boutons d'action
            
            // ✅ Expiration
            $table->timestamp('date_expiration')->nullable();
            
            $table->timestamps();

            // ✅ Index pour optimisation
            $table->index(['utilisateur_id', 'est_lue']);
            $table->index(['destinataire_type', 'role_cible']);
            $table->index('type');
            $table->index('created_at');
            $table->index('priorite');
            $table->index('date_expiration');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications_stock');
    }
};