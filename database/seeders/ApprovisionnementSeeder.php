<?php
// database/seeders/ApprovisionnementSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class ApprovisionnementSeeder extends Seeder
{
    public function run(): void
    {
        // Désactiver les contraintes
        Schema::disableForeignKeyConstraints();
        
        // Vider les tables (ordre important)
        DB::table('lignes_approvisionnements')->truncate();
        DB::table('approvisionnements')->truncate();
        
        Schema::enableForeignKeyConstraints();

        // Récupérer les données nécessaires
        $depots = DB::table('stock_succursale')->where('is_depot', true)->get();
        $pointsVente = DB::table('stock_succursale')->where('is_depot', false)->get();
        $produits = DB::table('produit')->get();
        $users = DB::table('utilisateurs')->where('statut', 'ACTIF')->get();
        
        if ($depots->isEmpty() || $pointsVente->isEmpty()) {
            $this->command->error('❌ Aucun dépôt ou point de vente trouvé !');
            return;
        }
        
        if ($produits->isEmpty()) {
            $this->command->error('❌ Aucun produit trouvé !');
            return;
        }

        // Définition des statuts avec leurs caractéristiques
        $statuts = [
            'EN_ATTENTE' => [
                'has_envoyee' => false,
                'has_recue' => false,
                'has_dates' => ['demande']
            ],
            'VALIDE' => [
                'has_envoyee' => false,
                'has_recue' => false,
                'has_dates' => ['demande', 'validation']
            ],
            'EN_COURS' => [
                'has_envoyee' => true,
                'has_recue' => false,
                'has_dates' => ['demande', 'validation', 'preparation']
            ],
            'EXPEDIE' => [
                'has_envoyee' => true,
                'has_recue' => false,
                'has_dates' => ['demande', 'validation', 'preparation', 'expedition']
            ],
            'RECU_PARTIEL' => [
                'has_envoyee' => true,
                'has_recue' => true,
                'has_dates' => ['demande', 'validation', 'preparation', 'expedition', 'reception']
            ],
            'RECU' => [
                'has_envoyee' => true,
                'has_recue' => true,
                'has_dates' => ['demande', 'validation', 'preparation', 'expedition', 'reception']
            ],
            'ANNULE' => [
                'has_envoyee' => false,
                'has_recue' => false,
                'has_dates' => ['demande']
            ],
        ];
        
        $statutsList = array_keys($statuts);
        
        for ($i = 0; $i < 10; $i++) {
            $depot = $depots->random();
            $pointVente = $pointsVente->random();
            $statutKey = $statutsList[array_rand($statutsList)];
            $statutConfig = $statuts[$statutKey];
            
            // ✅ Générer 1 à 3 produits par approvisionnement
            $nombreProduits = rand(1, 3);
            $produitsSelectionnes = $produits->random(min($nombreProduits, $produits->count()));
            if (!$produitsSelectionnes instanceof \Illuminate\Support\Collection) {
                $produitsSelectionnes = collect([$produitsSelectionnes]);
            }
            
            $totalQuantiteDemandee = 0;
            $totalQuantiteEnvoyee = 0;
            $totalQuantiteRecue = 0;
            $totalQuantiteCasse = 0;
            $totalQuantitePerdue = 0;
            $totalQuantiteNonConforme = 0;
            $totalQuantiteEcart = 0;
            
            // ✅ Tableau pour stocker les lignes
            $lignesData = [];
            
            foreach ($produitsSelectionnes as $produit) {
                $quantite = rand(10, 50);
                $totalQuantiteDemandee += $quantite;
                
                $quantiteEnvoyee = 0;
                $quantiteRecue = 0;
                $quantiteCasse = 0;
                $quantitePerdue = 0;
                $quantiteNonConforme = 0;
                $quantiteEcart = 0;
                
                // ✅ Définir quantite_envoyee si nécessaire
                if ($statutConfig['has_envoyee']) {
                    $quantiteEnvoyee = $quantite;
                    $totalQuantiteEnvoyee += $quantite;
                }
                
                // ✅ Définir quantite_recue si nécessaire
                if ($statutConfig['has_recue']) {
                    $quantiteRecue = rand(ceil($quantite * 0.5), $quantite);
                    $totalQuantiteRecue += $quantiteRecue;
                    
                    // Ajouter des écarts pour RECU_PARTIEL
                    if ($statutKey === 'RECU_PARTIEL' && rand(0, 1) && $quantiteRecue > 5) {
                        $quantiteCasse = rand(0, min(5, $quantiteRecue));
                        $quantitePerdue = rand(0, min(3, $quantiteRecue - $quantiteCasse));
                        $quantiteNonConforme = rand(0, min(2, $quantiteRecue - $quantiteCasse - $quantitePerdue));
                        $quantiteEcart = $quantiteCasse + $quantitePerdue + $quantiteNonConforme;
                        $totalQuantiteEcart += $quantiteEcart;
                        
                        $totalQuantiteCasse += $quantiteCasse;
                        $totalQuantitePerdue += $quantitePerdue;
                        $totalQuantiteNonConforme += $quantiteNonConforme;
                    }
                }
                
                $lignesData[] = [
                    'produit_id' => $produit->id,
                    'quantite_demandee' => $quantite,
                    'quantite_envoyee' => $quantiteEnvoyee,
                    'quantite_recue' => $quantiteRecue,
                    'quantite_casse' => $quantiteCasse,
                    'quantite_perdue' => $quantitePerdue,
                    'quantite_non_conforme' => $quantiteNonConforme,
                    'quantite_restante' => $quantiteEnvoyee - $quantiteRecue,
                    'prix_achat' => $produit->prix_achat ?? rand(100, 1000),
                    'notes' => null
                ];
            }
            
            // ✅ Quantité totale restante
            $totalQuantiteRestante = $totalQuantiteEnvoyee - $totalQuantiteRecue;
            
            // ✅ Motif écart global
            $motifEcart = null;
            if ($totalQuantiteEcart > 0) {
                $motifEcart = "Défauts: casse={$totalQuantiteCasse}, perte={$totalQuantitePerdue}, non conforme={$totalQuantiteNonConforme}";
            }
            
            // ✅ Dates
            $dateDemande = Carbon::now()->subDays(rand(1, 15));
            $dateValidation = null;
            $datePreparation = null;
            $dateExpedition = null;
            $dateReception = null;
            
            if (in_array('validation', $statutConfig['has_dates'])) {
                $dateValidation = (clone $dateDemande)->addDays(rand(1, 3));
            }
            if (in_array('preparation', $statutConfig['has_dates'])) {
                $datePreparation = ($dateValidation ?? $dateDemande)->addDays(rand(1, 2));
            }
            if (in_array('expedition', $statutConfig['has_dates'])) {
                $dateExpedition = ($datePreparation ?? $dateDemande)->addDays(rand(1, 2));
            }
            if (in_array('reception', $statutConfig['has_dates'])) {
                $dateReception = ($dateExpedition ?? $dateDemande)->addDays(rand(1, 5));
            }
            
            // ✅ Type d'approvisionnement
            $typeAppro = rand(0, 1) ? 'DIRECT' : 'AVEC_INTERMEDIAIRE';
            $depotIntermediaireId = null;
            $quantiteArriveeIntermediaire = null;
            $quantiteDepartIntermediaire = null;
            $perteIntermediaire = null;
            $notesIntermediaire = null;
            
            if ($typeAppro === 'AVEC_INTERMEDIAIRE' && $depots->count() > 1) {
                $depotIntermediaireId = $depots->where('id', '!=', $depot->id)->random()->id;
                
                if ($statutConfig['has_envoyee']) {
                    $quantiteArriveeIntermediaire = $totalQuantiteEnvoyee;
                    $perteIntermediaire = rand(0, min(5, $totalQuantiteEnvoyee));
                    
                    if ($perteIntermediaire > 0) {
                        $notesIntermediaire = "Perte de {$perteIntermediaire} unités pendant le transit";
                    }
                    
                    if ($statutConfig['has_recue']) {
                        $quantiteDepartIntermediaire = $quantiteArriveeIntermediaire - $perteIntermediaire;
                    }
                }
            }
            
            // ✅ Transport (pour les statuts avancés)
            $transporteur = null;
            $numeroVehicule = null;
            $nomChauffeur = null;
            $telephoneChauffeur = null;
            $numeroColis = null;
            $codeSuivi = null;
            $numBonAppro = null;
            $numBonLivraison = null;
            
            if ($statutConfig['has_envoyee']) {
                $transporteurs = ['Express Logistics', 'DHL', 'FedEx', 'UPS', 'CMA CGM'];
                $transporteur = $transporteurs[array_rand($transporteurs)];
                $numeroVehicule = 'VEH-' . rand(100, 999);
                $nomChauffeur = 'Chauffeur ' . chr(rand(65, 90)) . rand(1, 99);
                $telephoneChauffeur = '+243' . rand(800000000, 899999999);
                $numeroColis = 'COL-' . rand(1000, 9999);
                $codeSuivi = 'TRK-' . strtoupper(substr(uniqid(), -6));
                $numBonAppro = 'BON-APP-' . rand(1000, 9999);
                
                if ($statutConfig['has_recue']) {
                    $numBonLivraison = 'BL-' . rand(1000, 9999);
                }
            }
            
            // ✅ Documents (JSON)
            $documents = null;
            if ($numBonAppro || $numBonLivraison) {
                $documents = json_encode([
                    'bon_approvisionnement' => $numBonAppro,
                    'bon_livraison' => $numBonLivraison,
                    'date_creation' => now()->toDateTimeString()
                ]);
            }
            
            // ✅ Générer la référence unique
            $reference = 'APP-A' . date('Ymd') . '-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT);
            
            // ✅ Insertion de l'approvisionnement
            $approId = DB::table('approvisionnements')->insertGetId([
                'reference' => $reference,
                'depot_source_id' => $depot->id,
                'point_vente_destination_id' => $pointVente->id,
                'depot_intermediaire_id' => $depotIntermediaireId,
                'quantite_demandee' => $totalQuantiteDemandee,
                'quantite_envoyee' => $totalQuantiteEnvoyee,
                'quantite_recue' => $totalQuantiteRecue,
                'quantite_restante' => $totalQuantiteRestante,
                'quantite_casse' => $totalQuantiteCasse,
                'quantite_perdue' => $totalQuantitePerdue,
                'quantite_non_conforme' => $totalQuantiteNonConforme,
                'quantite_ecart' => $totalQuantiteEcart,
                'motif_ecart' => $motifEcart,
                'statut' => $statutKey,
                'type_appro' => $typeAppro,
                'demandeur_id' => $users->random()->id ?? null,
                'valide_par_id' => $dateValidation ? ($users->random()->id ?? null) : null,
                'preparateur_id' => $datePreparation ? ($users->random()->id ?? null) : null,
                'expediteur_id' => $dateExpedition ? ($users->random()->id ?? null) : null,
                'recepteur_id' => $dateReception ? ($users->random()->id ?? null) : null,
                'responsable_intermediaire_id' => $depotIntermediaireId && $quantiteArriveeIntermediaire ? ($users->random()->id ?? null) : null,
                'date_demande' => $dateDemande,
                'date_validation' => $dateValidation,
                'date_preparation' => $datePreparation,
                'date_expedition' => $dateExpedition,
                'date_reception' => $dateReception,
                'transporteur' => $transporteur,
                'numero_vehicule' => $numeroVehicule,
                'nom_chauffeur' => $nomChauffeur,
                'telephone_chauffeur' => $telephoneChauffeur,
                'numero_colis' => $numeroColis,
                'code_suivi' => $codeSuivi,
                'num_bon_appro' => $numBonAppro,
                'num_bon_livraison' => $numBonLivraison,
                'documents' => $documents,
                'quantite_arrivee_intermediaire' => $quantiteArriveeIntermediaire,
                'quantite_depart_intermediaire' => $quantiteDepartIntermediaire,
                'perte_intermediaire' => $perteIntermediaire,
                'notes_intermediaire' => $notesIntermediaire,
                'notes' => 'Approvisionnement multi-produits généré automatiquement - ' . $statutKey,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            // ✅ Insertion des lignes d'approvisionnement
            foreach ($lignesData as $ligne) {
                DB::table('lignes_approvisionnements')->insert([
                    'approvisionnement_id' => $approId,
                    'produit_id' => $ligne['produit_id'],
                    'quantite_demandee' => $ligne['quantite_demandee'],
                    'quantite_envoyee' => $ligne['quantite_envoyee'],
                    'quantite_recue' => $ligne['quantite_recue'],
                    'quantite_casse' => $ligne['quantite_casse'],
                    'quantite_perdue' => $ligne['quantite_perdue'],
                    'quantite_non_conforme' => $ligne['quantite_non_conforme'],
                    'quantite_restante' => $ligne['quantite_restante'],
                    'prix_achat' => $ligne['prix_achat'],
                    'notes' => $ligne['notes'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            
            $this->command->info("   ✅ {$reference} - Statut: {$statutKey} - Produits: " . count($lignesData) . " - Qte: {$totalQuantiteDemandee}");
        }
        
        $this->command->newLine();
        $this->command->info("✅ 10 approvisionnements multi-produits créés avec succès !");
        
        // Afficher le résumé
        $this->command->newLine();
        $this->command->info('📊 RÉSUMÉ DES APPROVISIONNEMENTS:');
        
        $stats = DB::table('approvisionnements')
            ->select('statut', DB::raw('count(*) as total'))
            ->groupBy('statut')
            ->orderBy('statut')
            ->get();
        
        foreach ($stats as $stat) {
            $this->command->line("   - {$stat->statut}: {$stat->total}");
        }
        
        // Afficher les statistiques des lignes
        $this->command->newLine();
        $this->command->info('📊 STATISTIQUES DES LIGNES:');
        
        $totalLignes = DB::table('lignes_approvisionnements')->count();
        $this->command->line("   - Total lignes: {$totalLignes}");
        
        // Afficher les statistiques des quantités
        $this->command->newLine();
        $this->command->info('📊 STATISTIQUES DES QUANTITÉS:');
        
        $totalDemande = DB::table('lignes_approvisionnements')->sum('quantite_demandee');
        $totalEnvoye = DB::table('lignes_approvisionnements')->sum('quantite_envoyee');
        $totalRecu = DB::table('lignes_approvisionnements')->sum('quantite_recue');
        $totalEcarts = DB::table('lignes_approvisionnements')->sum(DB::raw('quantite_casse + quantite_perdue + quantite_non_conforme'));
        
        $this->command->line("   - Total demandé: {$totalDemande}");
        $this->command->line("   - Total envoyé: {$totalEnvoye}");
        $this->command->line("   - Total reçu: {$totalRecu}");
        $this->command->line("   - Total écarts: {$totalEcarts}");
        
        // Afficher les statistiques par type
        $this->command->newLine();
        $this->command->info('📊 STATISTIQUES PAR TYPE:');
        
        $statsType = DB::table('approvisionnements')
            ->select('type_appro', DB::raw('count(*) as total'))
            ->groupBy('type_appro')
            ->get();
        
        foreach ($statsType as $stat) {
            $this->command->line("   - {$stat->type_appro}: {$stat->total}");
        }
        
        // Afficher la moyenne de produits par approvisionnement
        $this->command->newLine();
        $this->command->info('📊 MOYENNE DE PRODUITS PAR APPROVISIONNEMENT:');
        
        $moyenne = DB::table('approvisionnements')
            ->leftJoin('lignes_approvisionnements', 'approvisionnements.id', '=', 'lignes_approvisionnements.approvisionnement_id')
            ->select('approvisionnements.id', DB::raw('count(lignes_approvisionnements.id) as nb_produits'))
            ->groupBy('approvisionnements.id')
            ->get()
            ->avg('nb_produits');
        
        $this->command->line("   - Moyenne: " . round($moyenne, 2) . " produits par approvisionnement");
    }
}