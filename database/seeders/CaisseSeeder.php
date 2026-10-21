<?php
// database/seeders/CaisseSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Caisse;
use App\Models\Succursale;
use App\Models\Devise;
use App\Models\Utilisateur;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

class CaisseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🚀 Création des caisses...');

        $succursales = Succursale::all();
        $devises = Devise::all();
        
        if ($succursales->isEmpty()) {
            $this->command->error('❌ Veuillez d\'abord créer des succursales');
            return;
        }

        if ($devises->isEmpty()) {
            $this->command->error('❌ Veuillez d\'abord créer des devises');
            return;
        }

        // Récupérer les IDs des rôles
        $roleGuichetier = Role::where('code', 'GUICHETIER')->first();
        $roleChefAgence = Role::where('code', 'CHEF_AGENCE')->first();

        $deviseCDF = $devises->firstWhere('code', 'CDF') ?? $devises->first();
        $deviseUSD = $devises->firstWhere('code', 'USD') ?? $devises->first();

        foreach ($succursales as $succursale) {
            $this->command->info("📋 Traitement de la succursale: {$succursale->nom}");

            // Récupérer les guichetiers de la succursale
            $guichetiers = Utilisateur::where('succursale_id', $succursale->id)
                ->where('role_id', $roleGuichetier?->id)
                ->get();

            // Récupérer le chef d'agence
            $chefAgence = Utilisateur::where('succursale_id', $succursale->id)
                ->where('role_id', $roleChefAgence?->id)
                ->first();

            // Caisse principale CDF (souvent gérée par le chef d'agence)
            Caisse::firstOrCreate(
                ['code' => 'CAI' . $succursale->code . 'CDF'],
                [
                    'nom_caisse' => 'Caisse Principale ' . $succursale->nom,
                    'type_caisse' => 'PRINCIPALE',
                    'solde_initial' => 1000000,
                    'solde_minimal' => 100000,
                    'solde_maximal' => 5000000,
                    'succursale_id' => $succursale->id,
                    'devise_id' => $deviseCDF->id,
                    'caissier_attribue' => $chefAgence?->id ?? $guichetiers->first()?->id,
                    'statut' => 'FERMEE',
                    'inactif' => false
                ]
            );
            $this->command->info("   ✅ Caisse CDF créée pour {$succursale->nom}");

            // Caisse USD (si la succursale a assez de guichetiers)
            if ($guichetiers->count() >= 1) {
                Caisse::firstOrCreate(
                    ['code' => 'CAI' . $succursale->code . 'USD'],
                    [
                        'nom_caisse' => 'Caisse USD ' . $succursale->nom,
                        'type_caisse' => 'SECONDAIRE',
                        'solde_initial' => 5000,
                        'solde_minimal' => 500,
                        'solde_maximal' => 25000,
                        'succursale_id' => $succursale->id,
                        'devise_id' => $deviseUSD->id,
                        'caissier_attribue' => $guichetiers->first()?->id,
                        'statut' => 'FERMEE',
                        'inactif' => false
                    ]
                );
                $this->command->info("   ✅ Caisse USD créée pour {$succursale->nom}");
            }

            // Caisse Mobile Money (si la succursale a au moins 2 guichetiers)
            if ($guichetiers->count() >= 2) {
                Caisse::firstOrCreate(
                    ['code' => 'CAI' . $succursale->code . 'MOMO'],
                    [
                        'nom_caisse' => 'Caisse Mobile Money ' . $succursale->nom,
                        'type_caisse' => 'MOBILE_MONEY',
                        'solde_initial' => 500000,
                        'solde_minimal' => 50000,
                        'solde_maximal' => 2000000,
                        'succursale_id' => $succursale->id,
                        'devise_id' => $deviseCDF->id,
                        'caissier_attribue' => $guichetiers->skip(1)->first()?->id,
                        'statut' => 'FERMEE',
                        'inactif' => false
                    ]
                );
                $this->command->info("   ✅ Caisse Mobile Money créée pour {$succursale->nom}");
            }
        }

        // Vérification finale
        $totalCaisses = Caisse::count();
        $this->command->info('📊 VÉRIFICATION FINALE:');
        $this->command->info("   - Total caisses créées: {$totalCaisses}");
        $this->command->info('🎉 Caisses créées avec succès !');
    }
}