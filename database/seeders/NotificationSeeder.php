<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        $users = DB::table('utilisateurs')->pluck('id')->toArray();
        
        $types = ['ALERTE_STOCK', 'APPROVISIONNEMENT_CREE', 'TRANSFERT_EXPEDIE', 'MOUVEMENT_VALIDE', 'DEMANDE_APPROVISIONNEMENT'];
        $titres = [
            'ALERTE_STOCK' => '⚠️ Alerte stock',
            'APPROVISIONNEMENT_CREE' => '📦 Nouvelle demande d\'approvisionnement',
            'TRANSFERT_EXPEDIE' => '🚚 Transfert expédié',
            'MOUVEMENT_VALIDE' => '✅ Mouvement validé',
            'DEMANDE_APPROVISIONNEMENT' => '📋 Demande d\'approvisionnement'
        ];
        
        for ($i = 0; $i < 10; $i++) {
            $type = $types[array_rand($types)];
            $userId = $users[array_rand($users)];
            
            DB::table('notifications_stock')->insert([
                'utilisateur_id' => $userId,
                'destinataire_type' => 'utilisateur',
                'type' => $type,
                'titre' => $titres[$type],
                'message' => 'Ceci est une notification de test numéro ' . ($i+1),
                'donnees' => json_encode(['test' => true, 'index' => $i]),
                'est_lue' => rand(0, 1),
                'created_at' => now()->subMinutes(rand(1, 1440)),
                'updated_at' => now(),
            ]);
        }
        
        // Notification publique
        DB::table('notifications_stock')->insert([
            'destinataire_type' => 'public',
            'type' => 'ALERTE_STOCK',
            'titre' => '📢 Notification publique',
            'message' => 'Maintenance prévue ce weekend',
            'donnees' => json_encode(['maintenance' => true]),
            'est_lue' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
