<?php

namespace App\DTO;

class ApprovisionnementDTO
{
    public int $caisseId;
    public string $motif;
    public ?string $reference;
    public array $operations; // tableau de soldes (pour coffre) ou secondaires (pour principale)

    public function __construct(int $caisseId, string $motif, ?string $reference, array $operations)
    {
        $this->caisseId = $caisseId;
        $this->motif = $motif;
        $this->reference = $reference;
        $this->operations = $operations;
    }

    public function isCoffre(): bool
    {
        // On détermine le type en fonction de la structure (les opérations peuvent être "soldes" ou "secondaires")
        return isset($this->operations[0]['soldes']) === false; // si pas de clé "soldes", c'est un coffre
    }
}