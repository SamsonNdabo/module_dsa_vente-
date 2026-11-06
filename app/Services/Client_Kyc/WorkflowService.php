<?php

namespace App\Services\Client_Kyc;

use App\Models\Kyc\Demande;
use App\Models\Utilisateur;
use App\Workflow\DemandeHandler;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Moteur générique : toute transition passe ici (graphe, permission, catalogue partenaire, historique, handler). */
class WorkflowService
{
    public function __construct(
        private SequenceService $sequences,
        private StatutCatalogue $catalogue
    ) {}

    public function creer(string $type, array $attrs, Utilisateur $auteur): Demande
    {
        $def = $this->definition($type);

        return DB::transaction(function () use ($type, $attrs, $auteur, $def) {
            // Idempotence hors ligne : même local_uuid => même demande, aucun doublon.
            if (! empty($attrs['local_uuid']) && $existante = Demande::where('local_uuid', $attrs['local_uuid'])->first()) {
                return $existante;
            }

            // Sécurité : un Client_id est obligatoire
                        // Sécurité : un client_id est obligatoire
            if (empty($attrs['client_id'])) {
                throw ValidationException::withMessages([
                    'client_id' => 'Le client est obligatoire.',
                ]);
            }

            $demande = Demande::create(array_merge($attrs, [
                'reference'  => $this->sequences->suivant('DEMANDE', 'DEM'),
                'type'       => $type,
                'statut'     => $def['initial'],
                'created_by' => $auteur->id,
            ]));

            $this->historiser($demande, null, $def['initial'], 'Création', $auteur, false);

            return $demande;
        });
    }

    public function transitionner(
        Demande $demande,
        string $cible,
        ?string $motif,
        ?Utilisateur $acteur,
        bool $systeme = false,
        array $donnees = [],
    ): Demande {
        return DB::transaction(function () use ($demande, $cible, $motif, $acteur, $systeme, $donnees) {
            $demande = Demande::lockForUpdate()->findOrFail($demande->id);
            $def     = $this->definition($demande->type);

            $permission = $def['transitions'][$demande->statut][$cible] ?? null;

            if ($permission === null) {
                throw ValidationException::withMessages([
                    'statut' => "Transition {$demande->statut} → {$cible} non autorisée.",
                ]);
            }

            if (! $systeme && ($permission === 'systeme' || ! $acteur?->hasPermission($permission))) {
                throw new AuthorizationException("Permission requise : {$permission}");
            }

            // Le statut cible doit exister dans le catalogue du partenaire.
            $this->catalogue->exigerConfigure($demande->type, $demande->partenaire_id, $cible);

            if (in_array($cible, $def['motif_obligatoire'] ?? [], true) && blank($motif)) {
                throw ValidationException::withMessages(['motif' => 'Le motif est obligatoire pour ce statut.']);
            }

            $handler = $this->handler($def);
            $handler->avant($demande, $cible, $acteur, $donnees);

            $ancien = $demande->statut;
            $demande->statut = $cible;

            if (in_array($cible, $def['etats_soumission'] ?? [], true)) {
                $demande->soumise_at = now();
            }
            if (in_array($cible, $def['etats_decision'] ?? [], true)) {
                $demande->decidee_at       = now();
                $demande->decidee_par      = $acteur?->id;
                $demande->motif_decision   = $motif;
            }
            $demande->save();

            $this->historiser($demande, $ancien, $cible, $motif, $acteur, $systeme);
            $handler->apres($demande, $ancien, $acteur, $donnees);

            $acteur?->logAudit('DSA_DEMANDE_STATUT', ['statut' => $ancien], [
                'demande' => $demande->reference,
                'statut'  => $cible,
                'motif'   => $motif,
            ]);

            return $demande->fresh();
        });
    }

    private function historiser(Demande $d, ?string $ancien, string $nouveau, ?string $motif, ?Utilisateur $acteur, bool $systeme): void
    {
        $d->historiques()->create([
            'ancien_statut'  => $ancien,
            'nouveau_statut' => $nouveau,
            'motif'          => $motif,
            'effectue_par'   => $acteur?->id,
            'systeme'        => $systeme,
        ]);
    }

    private function definition(string $type): array
    {
        return config("dsa_workflow.{$type}")
            ?? throw new InvalidArgumentException("Type de demande inconnu : {$type}");
    }

    private function handler(array $def): DemandeHandler
    {
        return app($def['handler']);
    }
}