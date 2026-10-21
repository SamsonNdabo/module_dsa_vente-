<?php

namespace App\DTO;

class FermetureDTO
{
    public int $caisseId;
    public int $utilisateurId;
    public ?string $notes;
    public ?array $soldesFermeture; // pour les principales

    public function __construct(int $caisseId, int $utilisateurId, ?string $notes = null, ?array $soldesFermeture = null)
    {
        $this->caisseId = $caisseId;
        $this->utilisateurId = $utilisateurId;
        $this->notes = $notes;
        $this->soldesFermeture = $soldesFermeture;
    }
}