<?php

namespace App\Services\Client_Kyc;

use App\Models\Partenaire\Partenaire;
use App\Models\Partenaire\StatutConfigurable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Catalogue des statuts par partenaire (table statuts_configurables du MCD).
 * Le graphe de transitions reste fermé dans config/dsa_workflow.php ; ici : libellés, ordre, statut terminal.
 * À enregistrer en singleton (AppServiceProvider) : le résultat est mémorisé pour la durée de la requête.
 */
class StatutCatalogue
{
    public const TYPES = ['KYC', 'OUVERTURE_COMPTE'];

    private array $memo = [];

    /** @return Collection<string, array{libelle: string, ordre: int, terminal: bool}> indexé par code */
    public function pour(string $type, ?int $partenaireId): Collection
    {
        $cle = $type.'|'.($partenaireId ?? 0);

        return $this->memo[$cle] ??= $this->charger($type, $partenaireId);
    }

    public function parDefaut(string $type): Collection
    {
        $def = config("dsa_workflow.{$type}");

        $codes = collect([$def['initial']])
            ->merge(array_keys($def['transitions']))
            ->merge($def['terminaux'])
            ->merge(collect($def['transitions'])->flatMap(fn ($cibles) => array_keys($cibles)))
            ->unique()
            ->values();

        return $codes->mapWithKeys(fn ($code, $i) => [$code => [
            'libelle' => config("dsa_workflow.libelles.{$code}", $code),
            'ordre' => $i + 1,
            'terminal' => in_array($code, $def['terminaux'], true),
        ]]);
    }

    public function initialiserPourPartenaire(Partenaire $p): void
    {
        foreach (self::TYPES as $type) {
            foreach ($this->parDefaut($type) as $code => $m) {
                StatutConfigurable::firstOrCreate(
                    ['partenaire_id' => $p->id, 'entite' => $type, 'code' => $code],
                    ['libelle' => $m['libelle'], 'ordre' => $m['ordre'], 'terminal' => $m['terminal']]
                );
            }
        }

        $this->memo = [];
    }

    public function estTerminal(string $type, ?int $partenaireId, string $code): bool
    {
        return $this->pour($type, $partenaireId)->get($code)['terminal'] ?? false;
    }

    public function libelle(string $type, ?int $partenaireId, string $code): string
    {
        return $this->pour($type, $partenaireId)->get($code)['libelle'] ?? $code;
    }

    /** Refuse un statut absent du catalogue du partenaire (catalogue incomplet = configuration à corriger). */
    public function exigerConfigure(string $type, ?int $partenaireId, string $code): void
    {
        if (! $this->pour($type, $partenaireId)->has($code)) {
            throw ValidationException::withMessages([
                'statut' => "Le statut {$code} n'est pas configuré pour ce partenaire (statuts_configurables).",
            ]);
        }
    }

    private function charger(string $type, ?int $partenaireId): Collection
    {
        if ($partenaireId) {
            $lignes = StatutConfigurable::where('partenaire_id', $partenaireId)
                ->where('entite', $type)->orderBy('ordre')->get();

            if ($lignes->isNotEmpty()) {
                return $lignes->mapWithKeys(fn ($l) => [$l->code => [
                    'libelle' => $l->libelle, 'ordre' => $l->ordre, 'terminal' => $l->terminal,
                ]]);
            }
        }

        return $this->parDefaut($type);
    }
}
