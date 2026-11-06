<?php

namespace App\Workflow;

use App\Models\Kyc\Demande;
use App\Models\Utilisateur;

interface DemandeHandler
{
    public function avant(Demande $demande, string $cible, ?Utilisateur $acteur, array $donnees): void;

    public function apres(Demande $demande, string $ancien, ?Utilisateur $acteur, array $donnees): void;
}