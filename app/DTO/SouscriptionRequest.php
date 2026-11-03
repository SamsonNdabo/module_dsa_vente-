<?php

namespace App\DTO;

class SouscriptionRequest
{
    public function __construct(
        public int $clientId,
        public string $typeProduit,
        public array $items = [],
        public string $modeExecution = 'AUTO',
        public ?float $montantInitial = null,
        public int $idProduit = 0,
        public ?int $idPartenaire = null,
        public ?string $modeLivraison = null,
        public ?string $adresseLivraison = null,
        public ?string $telephoneLivraison = null,
        public ?string $dateLivraisonSouhaitee = null,   // 🆕
        public bool $estRecurrente = false,               // 🆕
        public string $recurrenceType = 'AUCUNE',         // 🆕
        public ?int $recurrenceIntervalle = null,         // 🆕
        public ?string $recurrenceDateFin = null,         // 🆕
        public ?int $recurrenceNbOccurrences = null,      // 🆕
        public ?int $caisseId = null,
        public ?string $notes = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            clientId:               (int) $data['client_id'],
            typeProduit:            (string) $data['type_produit'],
            items:                  $data['items'] ?? [],
            modeExecution:          $data['mode_execution'] ?? 'AUTO',
            montantInitial:         isset($data['montant_initial']) ? (float) $data['montant_initial'] : null,
            idProduit:              isset($data['id_produit']) ? (int) $data['id_produit'] : 0,
            idPartenaire:           isset($data['id_partenaire']) ? (int) $data['id_partenaire'] : null,
            modeLivraison:          $data['mode_livraison'] ?? null,
            adresseLivraison:       $data['adresse_livraison'] ?? null,
            telephoneLivraison:     $data['telephone_livraison'] ?? null,
            dateLivraisonSouhaitee: $data['date_livraison_souhaitee'] ?? null,
            estRecurrente:          (bool) ($data['est_recurrente'] ?? false),
            recurrenceType:         $data['recurrence_type'] ?? 'AUCUNE',
            recurrenceIntervalle:   isset($data['recurrence_intervalle']) ? (int) $data['recurrence_intervalle'] : null,
            recurrenceDateFin:      $data['recurrence_date_fin'] ?? null,
            recurrenceNbOccurrences:isset($data['recurrence_nb_occurrences']) ? (int) $data['recurrence_nb_occurrences'] : null,
            caisseId:               isset($data['caisse_id']) ? (int) $data['caisse_id'] : null,
            notes:                  $data['notes'] ?? null,
        );
    }

    public function calculerDateFinRecurrence(): ?string
    {
        // Pas de récurrence → pas de date de fin
        if (! $this->estRecurrente || $this->recurrenceType === 'AUCUNE') {
            return null;
        }

        // Si l'utilisateur a saisi une date_fin explicite ET pas de nb_occurrences → on la garde
        if ($this->recurrenceNbOccurrences === null || $this->recurrenceNbOccurrences < 1) {
            return $this->recurrenceDateFin;
        }

        // Date de départ = date_livraison_souhaitee ou aujourd'hui
        $dateDebut = $this->dateLivraisonSouhaitee
            ? \Carbon\Carbon::parse($this->dateLivraisonSouhaitee)
            : \Carbon\Carbon::today();

        $intervalle = max(1, (int) ($this->recurrenceIntervalle ?? 1));
        $nbOccurrences = (int) $this->recurrenceNbOccurrences;

        // On calcule (nb_occurrences - 1) intervalles
        $nbPeriodes = $nbOccurrences - 1;

        $dateFin = match ($this->recurrenceType) {
            'HEBDOMADAIRE' => $dateDebut->copy()->addWeeks($intervalle * $nbPeriodes),
            'MENSUEL'      => $dateDebut->copy()->addMonths($intervalle * $nbPeriodes),
            'TRIMESTRIEL'  => $dateDebut->copy()->addMonths(3 * $intervalle * $nbPeriodes),
            'ANNUEL'       => $dateDebut->copy()->addYears($intervalle * $nbPeriodes),
            default        => $dateDebut->copy(),
        };

        return $dateFin->toDateString();
    }
}