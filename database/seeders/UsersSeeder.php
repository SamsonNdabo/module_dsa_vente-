<?php
// database/seeders/UsersSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Utilisateur;
use App\Models\Role;
use App\Models\Succursale;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;


class UsersSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['matricule' => 'EMP001', 'nom_complet' => 'Admin System', 'email' => 'admin@starehe.com', 'telephone' => '123456789', 'role_code' => 'SUPER_ADMIN', 'succursale_code' => 'SUC001', 'statut' => 'ACTIF'],
            ['matricule' => 'EMP002', 'nom_complet' => 'Jean Dupont', 'email' => 'jean.dupont@starehe.com', 'telephone' => '123456780', 'role_code' => 'CHEF_AGENCE', 'succursale_code' => 'SUC001', 'statut' => 'ACTIF'],
            ['matricule' => 'EMP003', 'nom_complet' => 'Marie Claire', 'email' => 'marie.claire@starehe.com', 'telephone' => '123456781', 'role_code' => 'GESTIONNAIRE_STOCK', 'succursale_code' => 'SUC001', 'statut' => 'ACTIF'],
            ['matricule' => 'EMP004', 'nom_complet' => 'Paul Martin', 'email' => 'paul.martin@starehe.com', 'telephone' => '123456782', 'role_code' => 'GUICHETIER', 'succursale_code' => 'SUC002', 'statut' => 'ACTIF'],
            ['matricule' => 'EMP005', 'nom_complet' => 'Sophie Laurent', 'email' => 'sophie.laurent@starehe.com', 'telephone' => '123456783', 'role_code' => 'GUICHETIER', 'succursale_code' => 'SUC002', 'statut' => 'ACTIF'],
            ['matricule' => 'EMP006', 'nom_complet' => 'Luc Bernard', 'email' => 'luc.bernard@starehe.com', 'telephone' => '123456784', 'role_code' => 'GESTIONNAIRE_STOCK', 'succursale_code' => 'SUC005', 'statut' => 'ACTIF'],
            ['matricule' => 'EMP007', 'nom_complet' => 'Isabelle Moreau', 'email' => 'isabelle.moreau@starehe.com', 'telephone' => '123456785', 'role_code' => 'CHEF_AGENCE', 'succursale_code' => 'SUC005', 'statut' => 'ACTIF'],
            ['matricule' => 'EMP008', 'nom_complet' => 'Thomas Petit', 'email' => 'thomas.petit@starehe.com', 'telephone' => '123456786', 'role_code' => 'GUICHETIER', 'succursale_code' => 'SUC006', 'statut' => 'ACTIF'],
            ['matricule' => 'EMP009', 'nom_complet' => 'Catherine Dubois', 'email' => 'catherine.dubois@starehe.com', 'telephone' => '123456787', 'role_code' => 'GESTIONNAIRE_DEPOT', 'succursale_code' => 'SUC007', 'statut' => 'ACTIF'],
            ['matricule' => 'EMP010', 'nom_complet' => 'Michel Lambert', 'email' => 'michel.lambert@starehe.com', 'telephone' => '123456788', 'role_code' => 'RECEPTEUR', 'succursale_code' => 'SUC008', 'statut' => 'ACTIF'],
        ];

        foreach ($users as $user) {
            $role = DB::table('roles')->where('code', $user['role_code'])->first();
            $succursale = DB::table('succursales')->where('code', $user['succursale_code'])->first();
            
            DB::table('utilisateurs')->insert([
                'matricule' => $user['matricule'],
                'nom_complet' => $user['nom_complet'],
                'email' => $user['email'],
                'telephone' => $user['telephone'],
                'nom_utilisateur' => strtolower(str_replace(' ', '.', $user['nom_complet'])),
                'password_hash' => Hash::make('password123'),
                'role_id' => $role->id,
                'succursale_id' => $succursale->id,
                'statut' => $user['statut'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
