<?php
// database/seeders/InventaireSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Inventaire;
use App\Models\Succursale;
use App\Models\Utilisateur;
use App\Models\Produit;
use App\Models\StockSuccursale;
use App\Models\MouvementStock;
use Carbon\Carbon;

class InventaireSeeder extends Seeder
{
    public function run(): void
    {
        $succursales = Succursale::all();
        $utilisateurs = Utilisateur::whereHas('role', function($q) {
            $q->whereIn('code', ['CHEF_AGENCE', 'SUPER_ADMIN', 'AUDITEUR']);
        })->get();

        if ($succursales->isEmpty() || $utilisateurs->isEmpty()) {
            $this->command->warn('⚠️ Succursales ou utilisateurs manquants. Veuillez d\'abord exécuter leurs seeders.');
            return;
        }

        $statuts = ['EN_COURS', 'TERMINE', 'VALIDE', 'ANNULE'];
        $inventaires = [];

        foreach ($succursales as $succursale) {
            // Créer 3 à 5 inventaires par succursale
            $nombreInventaires = rand(3, 5);
            
            for ($i = 1; $i <= $nombreInventaires; $i++) {
                $responsable = $utilisateurs->random();
                $statut = $statuts[array_rand($statuts)];
                
                // Dates aléatoires (entre 1 et 60 jours dans le passé)
                $dateDebut = Carbon::now()->subDays(rand(1, 60))->setTime(rand(8, 10), rand(0, 59));
                
                $inventaire = Inventaire::create([
                    'reference' => Inventaire::genererReference(),
                    'succursale_id' => $succursale->id,
                    'utilisateur_id' => $responsable->id,
                    'statut' => $statut,
                    'date_debut' => $dateDebut,
                    'notes' => 'Inventaire ' . strtolower($statut) . ' pour ' . $succursale->nom,
                ]);

                if (in_array($statut, ['TERMINE', 'VALIDE'])) {
                    // Date de fin 2-4 heures après le début
                    $dateFin = $dateDebut->copy()->addHours(rand(2, 4));
                    $inventaire->update(['date_fin' => $dateFin]);
                }

                if ($statut === 'VALIDE') {
                    // Validateur différent du responsable initial
                    $validateur = $utilisateurs->where('id', '!=', $responsable->id)->random();
                    $dateValidation = $inventaire->date_fin->copy()->addHours(rand(1, 24));
                    
                    $inventaire->update([
                        'date_validation' => $dateValidation,
                        'valide_par' => $validateur->id
                    ]);
                }

                if ($statut === 'ANNULE') {
                    $motifs = [
                        'Inventaire reporté pour cause de réception de stock',
                        'Problème technique lors du comptage',
                        'Personnel insuffisant',
                        'Réorganisation du magasin',
                        'Urgence opérationnelle'
                    ];
                    
                    $inventaire->update([
                        'motif_annulation' => $motifs[array_rand($motifs)]
                    ]);
                }

                $inventaires[] = $inventaire;
            }
        }

        $this->command->info('✅ ' . count($inventaires) . ' inventaires créés avec succès !');
    }
}