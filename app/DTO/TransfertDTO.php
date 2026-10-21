<?php

namespace App\DTO;

class TransfertDTO
{
    public int $sourceId;
    public int $deviseId;
    public float $montant;
    public string $motif;
    public ?int $destinationId;
    public ?array $destinations;

    public function __construct(
        int $sourceId,
        int $deviseId,
        float $montant,
        string $motif,
        ?int $destinationId = null,
        ?array $destinations = null
    ) {
        $this->sourceId = $sourceId;
        $this->deviseId = $deviseId;
        $this->montant = $montant;
        $this->motif = $motif;
        $this->destinationId = $destinationId;
        $this->destinations = $destinations;
    }

    public function isMultiple(): bool
    {
        return $this->destinations !== null && count($this->destinations) > 0;
    }
}