<?php

namespace App\Services\Partenaire;

use App\Models\Partenaire\Partenaire;
use App\Models\Partenaire\PartenaireCommissionRecue;
use App\Models\Campagne\Campagne;
use App\Models\Campagne\CampagneOperation;
use App\Models\Campagne\CampagneAgent;
use App\Models\Campagne\CommissionGeneree;
use App\models\Produit;
use App\Models\ProduitEpargne;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Exception;

class PartenaireService
{
    // ═════════════════════════════════════════════════════════════
    // 1. CRUD PARTENAIRE
    // ═════════════════════════════════════════════════════════════

    public function creerPartenaire(array $data, Utilisateur $createur): Partenaire
    {
        DB::beginTransaction();
        try {
            if (Partenaire::where('code', $data['code'])->exists()) {
                throw new Exception("Un partenaire avec le code '{$data['code']}' existe déjà.");
            }

            $partenaire = Partenaire::create([
                'code'            => $data['code'],
                'nom'             => $data['nom'],
                'type_partenaire' => $data['type_partenaire'],
                'email'           => $data['email'],
                'telephone'       => $data['telephone'] ?? null,
                'statut'          => 'ACTIF',
            ]);

            Log::info('Partenaire créé', [
                'id'      => $partenaire->id,
                'code'    => $partenaire->code,
                'auteur'  => $createur->id,
            ]);

            DB::commit();
            return $partenaire;

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Erreur création partenaire', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function mettreAJourPartenaire(Partenaire $partenaire, array $data): Partenaire
    {
        DB::beginTransaction();
        try {
            $partenaire->update([
                'nom'             => $data['nom'] ?? $partenaire->nom,
                'type_partenaire' => $data['type_partenaire'] ?? $partenaire->type_partenaire,
                'email'           => $data['email'] ?? $partenaire->email,
                'telephone'       => $data['telephone'] ?? $partenaire->telephone,
            ]);

            DB::commit();
            return $partenaire->fresh();

        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function changerStatut(Partenaire $partenaire, string $statut): Partenaire
    {
        if (!in_array($statut, ['ACTIF', 'INACTIF', 'SUSPENDU'])) {
            throw new Exception("Statut invalide.");
        }

        if ($statut !== 'ACTIF') {
            Campagne::where('id_partenaire', $partenaire->id)
                ->where('statut', 'ACTIVE')
                ->update(['statut' => 'EN_PAUSE']);
        }

        $partenaire->update(['statut' => $statut]);
        return $partenaire;
    }

    // ═════════════════════════════════════════════════════════════
    // 2. FILTRES (Index Web)
    // ═════════════════════════════════════════════════════════════

    public function filtrerPartenaires(array $filtres = [])
    {
        $query = Partenaire::query();

        if (!empty($filtres['statut'])) {
            $query->whereIn('statut', (array) $filtres['statut']);
        }
        if (!empty($filtres['type_partenaire'])) {
            $query->where('type_partenaire', $filtres['type_partenaire']);
        }
        if (!empty($filtres['search'])) {
            $query->where(function ($q) use ($filtres) {
                $q->where('nom', 'like', "%{$filtres['search']}%")
                  ->orWhere('code', 'like', "%{$filtres['search']}%")
                  ->orWhere('email', 'like', "%{$filtres['search']}%");
            });
        }

        return $query->orderBy('nom');
    }

    // ═════════════════════════════════════════════════════════════
    // 3. STATISTIQUES GLOBALES (Dashboard Partenaire)
    // ═════════════════════════════════════════════════════════════

    public function dashboard(int $idPartenaire): array
    {
        $campagnes = Campagne::where('id_partenaire', $idPartenaire)->get();
        $idsCampagnes = $campagnes->pluck('id');

        $nbCampagnes       = $campagnes->count();
        $nbActives         = $campagnes->where('statut', 'ACTIVE')->count();
        $nbCloturees       = $campagnes->where('statut', 'CLOTUREE')->count();

        $nbAgents = CampagneAgent::whereIn('id_campagne', $idsCampagnes)
            ->distinct('id_agent')->count('id_agent');

        $nbOperations = CampagneOperation::whereIn('id_campagne', $idsCampagnes)
            ->where('compte_dans_objectif', true)->count();

        $caTotal = CampagneOperation::whereIn('id_campagne', $idsCampagnes)
            ->where('compte_dans_objectif', true)->sum('montant_operation');

        $commissionsTotal     = CommissionGeneree::where('id_partenaire', $idPartenaire)->sum('montant_calcule');
        $commissionsPayees    = CommissionGeneree::where('id_partenaire', $idPartenaire)
            ->where('statut', 'PAYEE')->sum('montant_calcule');
        $commissionsEnAttente = CommissionGeneree::where('id_partenaire', $idPartenaire)
            ->whereIn('statut', ['CALCULEE', 'VALIDEE'])->sum('montant_calcule');

        $montantRecu = PartenaireCommissionRecue::where('id_partenaire', $idPartenaire)
            ->where('statut', 'CONFIRME')->sum('montant_confirme');
        $ecart = PartenaireCommissionRecue::where('id_partenaire', $idPartenaire)
            ->where('statut', 'ECART_DETECTE')->sum('ecart');

        return [
            'partenaire' => [
                'id'   => $idPartenaire,
                'nom'  => Partenaire::find($idPartenaire)?->nom,
                'type' => Partenaire::find($idPartenaire)?->type_partenaire,
            ],
            'campagnes' => [
                'total'     => $nbCampagnes,
                'actives'   => $nbActives,
                'cloturees' => $nbCloturees,
            ],
            'agents' => ['total' => $nbAgents],
            'operations' => [
                'total'     => $nbOperations,
                'ca_genere' => round($caTotal, 2),
            ],
            'commissions' => [
                'total'      => round($commissionsTotal, 2),
                'payees'     => round($commissionsPayees, 2),
                'en_attente' => round($commissionsEnAttente, 2),
                'recues'     => round($montantRecu, 2),
                'ecart'      => round($ecart, 2),
            ],
        ];
    }

    // ═════════════════════════════════════════════════════════════
    // 4. STATISTIQUES PAR CAMPAGNE
    // ═════════════════════════════════════════════════════════════

    public function statistiquesCampagne(int $idPartenaire, int $idCampagne): array
    {
        $campagne = Campagne::where('id', $idCampagne)
            ->where('id_partenaire', $idPartenaire)
            ->firstOrFail();

        $operations = CampagneOperation::where('id_campagne', $idCampagne)
            ->where('compte_dans_objectif', true);

        $ca = (clone $operations)->sum('montant_operation');
        $nb = (clone $operations)->count();

        $commissions = CommissionGeneree::where('id_campagne', $idCampagne);

        return [
            'campagne' => [
                'id'         => $campagne->id,
                'code'       => $campagne->code,
                'nom'        => $campagne->nom,
                'statut'     => $campagne->statut,
                'date_debut' => $campagne->date_debut->format('d/m/Y'),
                'date_fin'   => $campagne->date_fin->format('d/m/Y'),
            ],
            'operations' => [
                'total'     => $nb,
                'ca_genere' => round($ca, 2),
                'par_type'  => (clone $operations)
                    ->select('type_operation', DB::raw('COUNT(*) as total'))
                    ->groupBy('type_operation')
                    ->pluck('total', 'type_operation'),
            ],
            'commissions' => [
                'total'      => round((clone $commissions)->sum('montant_calcule'), 2),
                'payees'     => round((clone $commissions)->where('statut', 'PAYEE')->sum('montant_calcule'), 2),
                'en_attente' => round((clone $commissions)->whereIn('statut', ['CALCULEE', 'VALIDEE'])->sum('montant_calcule'), 2),
            ],
            'agents' => [
                'total' => CampagneAgent::where('id_campagne', $idCampagne)->distinct('id_agent')->count('id_agent'),
            ],
        ];
    }

    // ═════════════════════════════════════════════════════════════
    // 5. SUIVI DES PRODUITS (Ventes par produit)
    // ═════════════════════════════════════════════════════════════

    public function suiviProduits(int $idPartenaire): Collection
    {
        $campagnes = Campagne::where('id_partenaire', $idPartenaire)->pluck('id');

        return CampagneOperation::whereIn('id_campagne', $campagnes)
            ->where('compte_dans_objectif', true)
            ->whereNotNull('id_produit')
            ->select(
                'id_produit',
                DB::raw('COUNT(*) as quantite_vendue'),
                DB::raw('SUM(montant_operation) as ca_total')
            )
            ->groupBy('id_produit')
            ->with('produit:id,nom,code_barre')
            ->get()
            ->map(fn($row) => [
                'id_produit'      => $row->id_produit,
                'produit'         => $row->produit?->nom,
                'code_barre'      => $row->produit?->code_barre,
                'quantite_vendue' => (int) $row->quantite_vendue,
                'ca_total'        => round($row->ca_total, 2),
            ]);
    }

    // ═════════════════════════════════════════════════════════════
    // 6. SUIVI DES AGENTS
    // ═════════════════════════════════════════════════════════════

    public function suiviAgents(int $idPartenaire): Collection
    {
        $campagnes = Campagne::where('id_partenaire', $idPartenaire)->pluck('id');

        $agentsIds = CampagneAgent::whereIn('id_campagne', $campagnes)
            ->distinct('id_agent')->pluck('id_agent');

        return $agentsIds->map(function ($idAgent) use ($campagnes) {
            $operations = CampagneOperation::whereIn('id_campagne', $campagnes)
                ->where('id_agent', $idAgent)
                ->where('compte_dans_objectif', true);

            $agent = \App\Models\Agent::find($idAgent);

            return [
                'id_agent'     => $idAgent,
                'agent'        => $agent ? ($agent->prenom . ' ' . $agent->nom) : 'Inconnu',
                'telephone'    => $agent?->telephone,
                'nb_operations'=> (clone $operations)->count(),
                'ca_genere'    => round((clone $operations)->sum('montant_operation'), 2),
                'commissions'  => round(
                    CommissionGeneree::whereIn('id_campagne', $campagnes)
                        ->where('id_agent', $idAgent)
                        ->sum('montant_calcule'),
                    2
                ),
            ];
        })->sortByDesc('ca_genere')->values();
    }

    // ═════════════════════════════════════════════════════════════
    // 7. RAPPORTS & EXPORTS
    // ═════════════════════════════════════════════════════════════

    public function genererRapport(int $idPartenaire, ?string $dateDebut = null, ?string $dateFin = null): array
    {
        $campagnes = Campagne::where('id_partenaire', $idPartenaire);
        if ($dateDebut) $campagnes->where('date_debut', '>=', $dateDebut);
        if ($dateFin)   $campagnes->where('date_fin',   '<=', $dateFin);

        $idsCampagnes = $campagnes->pluck('id');

        $lignes = CampagneOperation::whereIn('id_campagne', $idsCampagnes)
            ->with(['agent:id,nom,prenom', 'produit:id,nom', 'campagne:id,code,nom'])
            ->orderByDesc('date_operation')
            ->get()
            ->map(fn($op) => [
                'date'            => $op->date_operation->format('d/m/Y H:i'),
                'campagne'        => $op->campagne?->code,
                'type_operation'  => $op->type_operation,
                'agent'           => $op->agent ? ($op->agent->prenom . ' ' . $op->agent->nom) : '',
                'produit'         => $op->produit?->nom,
                'montant'         => (float) $op->montant_operation,
                'reference'       => $op->id_operation_source,
                'confirme'        => (bool) $op->compte_dans_objectif,
            ]);

        return [
            'partenaire' => [
                'nom'  => Partenaire::find($idPartenaire)?->nom,
                'code' => Partenaire::find($idPartenaire)?->code,
            ],
            'periode' => [
                'debut' => $dateDebut ?? 'Depuis le début',
                'fin'   => $dateFin ?? 'Aujourd\'hui',
            ],
            'resume' => [
                'nb_operations' => $lignes->count(),
                'ca_total'      => $lignes->sum('montant'),
                'commissions'   => round(CommissionGeneree::whereIn('id_campagne', $idsCampagnes)->sum('montant_calcule'), 2),
            ],
            'lignes' => $lignes->toArray(),
        ];
    }

    // ═════════════════════════════════════════════════════════════
    // 8. RAPPROCHEMENT FINANCIER
    // ═════════════════════════════════════════════════════════════

    public function calculerMontantTheorique(int $idPartenaire, string $debut, string $fin): float
    {
        return CommissionGeneree::where('id_partenaire', $idPartenaire)
            ->whereBetween('created_at', [$debut, $fin])
            ->whereIn('statut', ['CALCULEE', 'VALIDEE', 'PAYEE'])
            ->sum('montant_calcule');
    }

    public function creerRapprochement(
        int $idPartenaire,
        string $debut,
        string $fin,
        float $montantConfirme,
        ?string $commentaire = null
    ): PartenaireCommissionRecue {
        DB::beginTransaction();
        try {
            $montantTheorique = $this->calculerMontantTheorique($idPartenaire, $debut, $fin);

            $statut = abs($montantTheorique - $montantConfirme) < 0.01
                ? 'CONFIRME'
                : 'ECART_DETECTE';

            $rapprochement = PartenaireCommissionRecue::create([
                'id_partenaire'      => $idPartenaire,
                'periode_debut'      => $debut,
                'periode_fin'        => $fin,
                'montant_theorique'  => $montantTheorique,
                'montant_confirme'   => $montantConfirme,
                'statut'             => $statut,
                'date_rapprochement' => now(),
                'commentaire'        => $commentaire,
            ]);

            Log::info('Rapprochement créé', [
                'id'       => $rapprochement->id,
                'ecart'    => $rapprochement->ecart,
                'statut'   => $statut,
            ]);

            DB::commit();
            return $rapprochement;

        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function marquerRapprochementPaye(PartenaireCommissionRecue $rapprochement, Utilisateur $auteur): PartenaireCommissionRecue
    {
        if ($rapprochement->statut !== 'CONFIRME') {
            throw new Exception("Seul un rapprochement CONFIRME peut être marqué comme payé.");
        }

        $rapprochement->update([
            'statut' => 'PAYE',
            'commentaire' => ($rapprochement->commentaire ? $rapprochement->commentaire . ' | ' : '')
                . "Payé le " . now()->format('d/m/Y') . " par {$auteur->nom_complet}",
        ]);

        return $rapprochement;
    }

    public function rapprochementsEnEcart(int $idPartenaire): Collection
    {
        return PartenaireCommissionRecue::where('id_partenaire', $idPartenaire)
            ->where('statut', 'ECART_DETECTE')
            ->orderByDesc('created_at')
            ->get();
    }

    // ═════════════════════════════════════════════════════════════
    // 9. NOUVEAU : RÉCUPÉRATION DES PRODUITS
    // ═════════════════════════════════════════════════════════════

    /**
     * Récupère les produits (Stock + Épargne).
     * Si un partenaire est fourni, renvoie uniquement SES produits.
     * Sinon, renvoie TOUS les produits paginés (10 par page).
     */
    public function getProduits(?int $idPartenaire = null, int $perPage = 10)
    {
        // CAS 1 : Aucun partenaire sélectionné -> On renvoie TOUT avec pagination
        if (!$idPartenaire) {
            $produitsStock = Produit::where('actif', true)->paginate($perPage, ['*'], 'page_stock');
            $produitsEpargne = ProduitEpargne::where('actif', true)->paginate($perPage, ['*'], 'page_epargne');

            return [
                'produits_stock'    => $produitsStock,
                'produits_epargne'  => $produitsEpargne,
            ];
        }

        // CAS 2 : Un partenaire est sélectionné -> On renvoie uniquement SES produits
        $partenaire = Partenaire::with(['produitsStock', 'produitsEpargne'])->findOrFail($idPartenaire);

        return [
            'produits_stock'    => $partenaire->produitsStock,
            'produits_epargne'  => $partenaire->produitsEpargne,
        ];
    }

    // ═════════════════════════════════════════════════════════════
    // 10. SÉCURITÉ : Vérification d'accès
    // ═════════════════════════════════════════════════════════════

    public function peutAcceder(Utilisateur $utilisateur, int $idPartenaire): bool
    {
        if ($utilisateur->hasPermission('view-all-partenaires')) {
            return true;
        }

        return $utilisateur->partenaire_id === $idPartenaire;
    }
}