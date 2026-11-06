<?php

namespace App\Services\Campagne;

use App\Models\AgentDsa;
use App\Models\Produit;
use App\Models\Utilisateur;
use App\Models\Partenaire\Partenaire;
use App\Models\Campagne\Campagne;
use App\Models\Campagne\CampagneZone;
use App\Models\Campagne\CampagneProduit;
use App\Models\Campagne\CampagneAgent;
use App\Models\Campagne\CampagneAgentStock;
use App\Models\Campagne\CampagneRegle;
use App\Models\Campagne\CampagneOperation;
use App\Models\Campagne\CampagneRealisation;
use App\Models\Campagne\CommissionGeneree;
use App\Models\Campagne\CommissionHistorique;
use App\Models\ProduitEpargne;
use App\Models\Visite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Exception;

class CampagneService
{
    // ============================================================
    // HELPERS PRIVÉS : NORMALISATION DES OBJECTIFS (JSON)
    // ============================================================

    /**
     * Transforme le payload plat (ex: objectif_visite) en JSON structuré.
     */
    private function normaliserObjectifsProduit(array $p): array
    {
        $obj = $p['objectifs'] ?? [];

        if (isset($p['objectif_quantite']))   $obj['quantite']   = (int) $p['objectif_quantite'];
        if (isset($p['objectif_montant']))    $obj['montant']    = (float) $p['objectif_montant'];
        if (isset($p['objectif_client']))     $obj['client']     = (int) $p['objectif_client'];
        if (isset($p['objectif_visite']))     $obj['visite']     = (int) $p['objectif_visite'];
        if (isset($p['objectif_plainte']))    $obj['plainte']    = (int) $p['objectif_plainte'];
        if (isset($p['objectif_suggestion'])) $obj['suggestion'] = (int) $p['objectif_suggestion'];

        return $obj;
    }

    /**
     * Transforme le payload plat d'un stock agent en JSON structuré.
     */
    private function normaliserObjectifsStock(array $s): array
    {
        $obj = $s['objectifs'] ?? [];

        if (isset($s['quantite_remise']))     $obj['quantite']   = (int) $s['quantite_remise'];
        if (isset($s['objectif_quantite']))   $obj['quantite']   = (int) $s['objectif_quantite'];
        if (isset($s['objectif_client']))     $obj['client']     = (int) $s['objectif_client'];
        if (isset($s['objectif_visite']))     $obj['visite']     = (int) $s['objectif_visite'];
        if (isset($s['objectif_plainte']))    $obj['plainte']    = (int) $s['objectif_plainte'];
        if (isset($s['objectif_suggestion'])) $obj['suggestion'] = (int) $s['objectif_suggestion'];

        return $obj;
    }

    // ============================================================
    // 1. CRUD CAMPAGNES
    // ============================================================

    public function creerCampagne(array $data, Utilisateur $createur): Campagne
    {
        DB::beginTransaction();
        try {
            if (!empty($data['id_partenaire'])) {
                Partenaire::findOrFail($data['id_partenaire']);
            }

            $campagne = Campagne::create([
                'code'          => $data['code'],
                'nom'           => $data['nom'],
                'description'   => $data['description'] ?? null,
                'id_partenaire' => $data['id_partenaire'] ?? null,
                'type_campagne' => $data['type_campagne'] ?? 'MIXTE',
                'date_debut'    => $data['date_debut'],
                'date_fin'      => $data['date_fin'],
                'statut'        => 'BROUILLON',
                'id_createur'   => $createur->id,
            ]);

            if (!empty($data['produits'])) {
                foreach ($data['produits'] as $p) {
                    $objectifs = $this->normaliserObjectifsProduit($p);
                    
                    CampagneProduit::create([
                        'id_campagne'       => $campagne->id,
                        'id_produit'        => $p['id_produit'],
                        'objectif_quantite' => $objectifs['quantite'] ?? 0,
                        'objectif_montant'  => $objectifs['montant'] ?? 0.00,
                        'objectifs'         => $objectifs,
                        'prix_special'      => $p['prix_special'] ?? null,
                        'offre_speciale'    => $p['offre_speciale'] ?? 'AUCUNE',
                        'valeur_offre'      => $p['valeur_offre'] ?? null,
                    ]);
                }
            }

            if (!empty($data['zones'])) {
                foreach ($data['zones'] as $z) {
                    CampagneZone::create([
                        'id_campagne'    => $campagne->id,
                        'id_zone'        => $z['id_zone'],
                        'id_superviseur' => $z['id_superviseur'] ?? null,
                    ]);
                }
            }

            if (!empty($data['agents'])) {
                foreach ($data['agents'] as $a) {
                    $campagneAgent = CampagneAgent::create([
                        'id_campagne'            => $campagne->id,
                        'id_agent'               => $a['id_agent'],
                        'id_zone'                => $a['id_zone'],
                        'id_superviseur'         => $a['id_superviseur'] ?? null,
                        'date_debut_affectation' => now(),
                        'statut_affectation'     => 'ACTIVE',
                    ]);

                    if (!empty($a['stocks'])) {
                        foreach ($a['stocks'] as $s) {
                            $objectifsStock = $this->normaliserObjectifsStock($s);
                            
                            CampagneAgentStock::create([
                                'id_campagne_agent' => $campagneAgent->id,
                                'id_produit'        => $s['id_produit'],
                                'quantite_remise'   => $objectifsStock['quantite'] ?? 0,
                                'objectifs'         => $objectifsStock,
                            ]);
                        }
                    }
                }
            }

            if (!empty($data['regles'])) {
                foreach ($data['regles'] as $r) {
                    CampagneRegle::create([
                        'id_campagne'         => $campagne->id,
                        'id_produit'          => $r['id_produit'] ?? null,
                        'id_agent'            => $r['id_agent'] ?? null,
                        'id_zone'             => $r['id_zone'] ?? null,
                        'type_regle'          => $r['type_regle'],
                        'type_indicateur'     => $r['type_indicateur'],
                        'valeur_cible'        => $r['valeur_cible'] ?? null,
                        'mode_calcul'         => $r['mode_calcul'] ?? null,
                        'valeur_commission'   => $r['valeur_commission'] ?? null,
                        'statut_eligible'     => $r['statut_eligible'] ?? 'CONFIRMEE',
                        'condition_bonus'     => $r['condition_bonus'] ?? null,
                        'montant_bonus'       => $r['montant_bonus'] ?? null,
                        'date_debut_validite' => $r['date_debut_validite'] ?? $campagne->date_debut,
                        'date_fin_validite'   => $r['date_fin_validite'] ?? $campagne->date_fin,
                        'version'             => 1,
                    ]);
                }
            }

            DB::commit();
            return $campagne;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function mettreAJourCampagne(Campagne $campagne, array $data): Campagne
    {
        if (!in_array($campagne->statut, ['BROUILLON', 'PLANIFIEE'])) {
            throw new Exception("Impossible de modifier une campagne au statut '{$campagne->statut}'.");
        }

        DB::beginTransaction();
        try {
            $campagne->update(array_filter([
                'nom'           => $data['nom'] ?? null,
                'description'   => $data['description'] ?? null,
                'date_debut'    => $data['date_debut'] ?? null,
                'date_fin'      => $data['date_fin'] ?? null,
                'type_campagne' => $data['type_campagne'] ?? null,
            ], fn($v) => $v !== null));

            if (isset($data['produits'])) {
                $idsProduits = collect($data['produits'])->pluck('id_produit')->toArray();
                CampagneProduit::where('id_campagne', $campagne->id)
                    ->whereNotIn('id_produit', $idsProduits)->delete();

                foreach ($data['produits'] as $p) {
                    $objectifs = $this->normaliserObjectifsProduit($p);
                    
                    CampagneProduit::updateOrCreate(
                        ['id_campagne' => $campagne->id, 'id_produit' => $p['id_produit']],
                        [
                            'objectif_quantite' => $objectifs['quantite'] ?? 0,
                            'objectif_montant'  => $objectifs['montant'] ?? 0.00,
                            'objectifs'         => $objectifs,
                            'prix_special'      => $p['prix_special'] ?? null,
                            'offre_speciale'    => $p['offre_speciale'] ?? 'AUCUNE',
                            'valeur_offre'      => $p['valeur_offre'] ?? null,
                        ]
                    );
                }
            }

            if (isset($data['zones'])) {
                $idsZones = collect($data['zones'])->pluck('id_zone')->toArray();
                CampagneZone::where('id_campagne', $campagne->id)
                    ->whereNotIn('id_zone', $idsZones)->delete();

                foreach ($data['zones'] as $z) {
                    CampagneZone::updateOrCreate(
                        ['id_campagne' => $campagne->id, 'id_zone' => $z['id_zone']],
                        ['id_superviseur' => $z['id_superviseur'] ?? null]
                    );
                }
            }

            if (isset($data['agents'])) {
                $idsAgents = collect($data['agents'])->pluck('id_agent')->toArray();
                $agentsToDelete = CampagneAgent::where('id_campagne', $campagne->id)
                    ->whereNotIn('id_agent', $idsAgents)->pluck('id');

                if ($agentsToDelete->isNotEmpty()) {
                    CampagneAgentStock::whereIn('id_campagne_agent', $agentsToDelete)->delete();
                    CampagneAgent::whereIn('id', $agentsToDelete)->delete();
                }

                foreach ($data['agents'] as $a) {
                    $campagneAgent = CampagneAgent::updateOrCreate(
                        ['id_campagne' => $campagne->id, 'id_agent' => $a['id_agent']],
                        [
                            'id_zone'                => $a['id_zone'],
                            'id_superviseur'         => $a['id_superviseur'] ?? null,
                            'date_debut_affectation' => now(),
                            'statut_affectation'     => 'ACTIVE',
                        ]
                    );

                    if (!empty($a['stocks'])) {
                        $idsProduitsAgent = collect($a['stocks'])->pluck('id_produit')->toArray();
                        CampagneAgentStock::where('id_campagne_agent', $campagneAgent->id)
                            ->whereNotIn('id_produit', $idsProduitsAgent)->delete();

                        foreach ($a['stocks'] as $s) {
                            $objectifsStock = $this->normaliserObjectifsStock($s);
                            
                            CampagneAgentStock::updateOrCreate(
                                ['id_campagne_agent' => $campagneAgent->id, 'id_produit' => $s['id_produit']],
                                [
                                    'quantite_remise' => $objectifsStock['quantite'] ?? 0,
                                    'objectifs'       => $objectifsStock,
                                ]
                            );
                        }
                    } else {
                        CampagneAgentStock::where('id_campagne_agent', $campagneAgent->id)->delete();
                    }
                }
            }

            if (isset($data['regles'])) {
                CampagneRegle::where('id_campagne', $campagne->id)->delete();
                foreach ($data['regles'] as $r) {
                    CampagneRegle::create([
                        'id_campagne'         => $campagne->id,
                        'id_produit'          => $r['id_produit'] ?? null,
                        'id_agent'            => $r['id_agent'] ?? null,
                        'id_zone'             => $r['id_zone'] ?? null,
                        'type_regle'          => $r['type_regle'],
                        'type_indicateur'     => $r['type_indicateur'],
                        'valeur_cible'        => $r['valeur_cible'] ?? null,
                        'mode_calcul'         => $r['mode_calcul'] ?? null,
                        'valeur_commission'   => $r['valeur_commission'] ?? null,
                        'statut_eligible'     => $r['statut_eligible'] ?? 'CONFIRMEE',
                        'condition_bonus'     => $r['condition_bonus'] ?? null,
                        'montant_bonus'       => $r['montant_bonus'] ?? null,
                        'date_debut_validite' => $r['date_debut_validite'] ?? $campagne->date_debut,
                        'date_fin_validite'   => $r['date_fin_validite'] ?? $campagne->date_fin,
                        'version'             => 1,
                    ]);
                }
            }

            DB::commit();
            return $campagne->fresh();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function supprimerCampagne(Campagne $campagne): bool
    {
        if (in_array($campagne->statut, ['ACTIVE', 'CLOTUREE'])) {
            throw new Exception("Une campagne ACTIVE ou CLOTUREE ne peut pas être supprimée. Archivez-la.");
        }
        return $campagne->delete();
    }

    // ============================================================
    // 2. CYCLE DE VIE
    // ============================================================

    public function validerCampagne(Campagne $campagne, Utilisateur $validateur): Campagne
    {
        if ($campagne->statut !== 'BROUILLON') {
            throw new Exception("Seule une campagne BROUILLON peut être validée.");
        }
        if ($campagne->produits()->count() === 0) {
            throw new Exception("Aucun produit éligible.");
        }
        $campagne->update(['statut' => 'PLANIFIEE', 'id_validateur' => $validateur->id]);
        return $campagne;
    }

    public function activerCampagne(Campagne $campagne): Campagne
    {
        if ($campagne->statut !== 'PLANIFIEE') {
            throw new Exception("Seule une campagne PLANIFIEE peut être activée.");
        }
        $campagne->update(['statut' => 'ACTIVE']);
        return $campagne;
    }

    public function mettreEnPause(Campagne $campagne): Campagne
    {
        if ($campagne->statut !== 'ACTIVE') {
            throw new Exception("Seule une campagne ACTIVE peut être mise en pause.");
        }
        $campagne->update(['statut' => 'EN_PAUSE']);
        return $campagne;
    }

    public function reprendreCampagne(Campagne $campagne): Campagne
    {
        if ($campagne->statut !== 'EN_PAUSE') {
            throw new Exception("Seule une campagne EN_PAUSE peut être reprise.");
        }
        $campagne->update(['statut' => 'ACTIVE']);
        return $campagne;
    }

    public function cloturerCampagne(Campagne $campagne): Campagne
    {
        if (!in_array($campagne->statut, ['ACTIVE', 'EN_PAUSE'])) {
            throw new Exception("Seule une campagne ACTIVE ou EN_PAUSE peut être clôturée.");
        }

        DB::beginTransaction();
        try {
            $campagne->agents()->whereNull('date_fin_affectation')->update([
                'date_fin_affectation' => now(),
                'statut_affectation'   => 'RETIREE',
            ]);

            $this->calculerBonusCampagne($campagne);
            $campagne->update(['statut' => 'CLOTUREE']);

            DB::commit();
            return $campagne;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // ============================================================
    // 3. AGENTS & AFFECTATIONS
    // ============================================================

    public function affecterAgent(Campagne $campagne, int $idAgent, int $idZone, ?int $idSuperviseur = null, array $stocks = []): CampagneAgent
    {
        if ($campagne->statut !== 'ACTIVE') {
            throw new Exception("Les affectations ne sont possibles que sur une campagne ACTIVE.");
        }

        $existante = CampagneAgent::where('id_campagne', $campagne->id)
            ->where('id_agent', $idAgent)
            ->whereNull('date_fin_affectation')
            ->first();

        if ($existante) {
            throw new Exception("Cet agent est déjà affecté à cette campagne.");
        }

        DB::beginTransaction();
        try {
            $campagneAgent = CampagneAgent::create([
                'id_campagne'            => $campagne->id,
                'id_agent'               => $idAgent,
                'id_zone'                => $idZone,
                'id_superviseur'         => $idSuperviseur,
                'date_debut_affectation' => now(),
                'statut_affectation'     => 'ACTIVE',
            ]);

            foreach ($stocks as $s) {
                $objectifsStock = $this->normaliserObjectifsStock($s);
                CampagneAgentStock::create([
                    'id_campagne_agent' => $campagneAgent->id,
                    'id_produit'        => $s['id_produit'],
                    'quantite_remise'   => $objectifsStock['quantite'] ?? 0,
                    'objectifs'         => $objectifsStock,
                ]);
            }

            DB::commit();
            return $campagneAgent;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function retirerAgent(Campagne $campagne, int $idAgent): CampagneAgent
    {
        $affectation = CampagneAgent::where('id_campagne', $campagne->id)
            ->where('id_agent', $idAgent)
            ->whereNull('date_fin_affectation')
            ->firstOrFail();

        $affectation->update([
            'date_fin_affectation' => now(),
            'statut_affectation'   => 'RETIREE',
        ]);

        return $affectation;
    }

    // ============================================================
    // 4. REGLES & OBJECTIFS
    // ============================================================

    public function ajouterRegle(Campagne $campagne, array $data): CampagneRegle
    {
        return CampagneRegle::create([
            'id_campagne'         => $campagne->id,
            'id_produit'          => $data['id_produit'] ?? null,
            'id_agent'            => $data['id_agent'] ?? null,
            'id_zone'             => $data['id_zone'] ?? null,
            'type_regle'          => $data['type_regle'],
            'type_indicateur'     => $data['type_indicateur'],
            'valeur_cible'        => $data['valeur_cible'] ?? null,
            'mode_calcul'         => $data['mode_calcul'] ?? null,
            'valeur_commission'   => $data['valeur_commission'] ?? null,
            'statut_eligible'     => $data['statut_eligible'] ?? 'CONFIRMEE',
            'condition_bonus'     => $data['condition_bonus'] ?? null,
            'montant_bonus'       => $data['montant_bonus'] ?? null,
            'date_debut_validite' => $data['date_debut_validite'] ?? $campagne->date_debut,
            'date_fin_validite'   => $data['date_fin_validite'] ?? $campagne->date_fin,
            'version'             => 1,
        ]);
    }

    // ============================================================
    // 5. OPERATIONS TERRAIN (UNIVERSELLE)
    // ============================================================

    public function enregistrerOperation(array $data): ?CampagneOperation
    {
        $campagne = Campagne::where('id', $data['id_campagne'])
            ->where('statut', 'ACTIVE')
            ->first();

        if (!$campagne) {
            Log::warning("Campagne inactive ou introuvable", ['id_campagne' => $data['id_campagne']]);
            return null;
        }

        $agentAffecte = CampagneAgent::where('id_campagne', $campagne->id)
            ->where('id_agent', $data['id_agent'])
            ->where('statut_affectation', 'ACTIVE')
            ->whereNull('date_fin_affectation')
            ->exists();

        if (!$agentAffecte) {
            Log::warning("Agent non affecté à cette campagne", [
                'id_campagne' => $campagne->id, 
                'id_agent' => $data['id_agent']
            ]);
            return null;
        }

        $existante = CampagneOperation::where('id_campagne', $campagne->id)
            ->where('type_operation', $data['type_operation'])
            ->where('id_operation_source', $data['id_operation_source'])
            ->first();

        if ($existante) {
            return $existante;
        }

        DB::beginTransaction();
        try {
            $operation = CampagneOperation::create([
                'id_campagne'          => $campagne->id,
                'type_operation'       => $data['type_operation'], 
                'id_operation_source'  => $data['id_operation_source'], 
                'id_agent'             => $data['id_agent'],
                'id_produit'           => $data['id_produit'] ?? null,
                'montant_operation'    => $data['montant_operation'] ?? 0.00,
                'quantite_operation'   => $data['quantite_operation'] ?? 1,
                'date_operation'       => $data['date_operation'] ?? now(),
                'compte_dans_objectif' => true,
            ]);

            $this->mettreAJourRealisation($campagne, $operation);
            $this->calculerCommission($campagne, $operation);

            DB::commit();
            return $operation;
            
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Erreur enregistrement opération campagne", [
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    protected function mettreAJourRealisation(Campagne $campagne, CampagneOperation $operation): void
    {
        $regles = CampagneRegle::where('id_campagne', $campagne->id)
            ->where('type_regle', 'OBJECTIF')
            ->where(fn($q) => $q->whereNull('id_produit')->orWhere('id_produit', $operation->id_produit))
            ->where(fn($q) => $q->whereNull('id_agent')->orWhere('id_agent', $operation->id_agent))
            ->actives()
            ->get();

        foreach ($regles as $regle) {
            $valeur = 0;
            
            switch ($regle->type_indicateur) {
                case 'NB_VENTES':
                    $valeur = ($operation->type_operation === 'VENTE') ? ($operation->quantite_operation ?? 1) : 0;
                    break;
                case 'MONTANT_VENTES':
                    $valeur = ($operation->type_operation === 'VENTE') ? $operation->montant_operation : 0;
                    break;
                case 'NB_KYC':
                case 'NB_CLIENTS_CREES':
                    $valeur = ($operation->type_operation === 'CREATION_CLIENT') ? 1 : 0;
                    break;
                case 'NB_VISITES':
                    $valeur = ($operation->type_operation === 'VISITE') ? 1 : 0;
                    break;
                case 'NB_EPARGNE':
                    $valeur = ($operation->type_operation === 'COLLECTE_EPARGNE') ? 1 : 0;
                    break;
                case 'MONTANT_EPARGNE':
                    $valeur = ($operation->type_operation === 'COLLECTE_EPARGNE') ? $operation->montant_operation : 0;
                    break;
                case 'NB_PLAINTES':
                    $valeur = ($operation->type_operation === 'PLAINTE') ? 1 : 0;
                    break;
                default:
                    $valeur = $operation->quantite_operation ?? 1;
                    break;
            }

            if ($valeur == 0) continue;

            CampagneRealisation::updateOrCreate(
                ['id_regle' => $regle->id, 'id_agent' => $operation->id_agent],
                ['valeur_realisee' => DB::raw("valeur_realisee + {$valeur}")]
            );
        }
    }

    protected function calculerCommission(Campagne $campagne, CampagneOperation $operation): ?CommissionGeneree
    {
        $regle = CampagneRegle::where('id_campagne', $campagne->id)
            ->where('type_regle', 'COMMISSION')
            ->where(fn($q) => $q->whereNull('id_produit')->orWhere('id_produit', $operation->id_produit))
            ->where(fn($q) => $q->whereNull('id_agent')->orWhere('id_agent', $operation->id_agent))
            ->actives()
            ->orderByRaw('id_agent IS NULL ASC, id_produit IS NULL ASC')
            ->orderByDesc('version')
            ->first();

        if (!$regle) return null;

        $montant = $regle->calculerCommission($operation->montant_operation);
        if ($montant <= 0) return null;

        $commission = CommissionGeneree::firstOrCreate(
            [
                'id_campagne'         => $campagne->id,
                'id_operation_source' => $operation->id_operation_source,
                'id_regle_commission' => $regle->id,
            ],
            [
                'id_agent'        => $operation->id_agent,
                'id_partenaire'   => $campagne->id_partenaire,
                'montant_calcule' => $montant,
                'statut'          => 'CALCULEE',
            ]
        );

        if ($commission->wasRecentlyCreated) {
            CommissionHistorique::create([
                'id_commission'  => $commission->id,
                'ancien_statut'  => null,
                'nouveau_statut' => 'CALCULEE',
                'id_utilisateur' => 1,
                'commentaire'    => 'Calcul automatique',
            ]);
        }

        return $commission;
    }

    // ============================================================
    // 6. VALIDATION DES COMMISSIONS
    // ============================================================

    public function validerCommission(CommissionGeneree $commission, Utilisateur $validateur): CommissionGeneree
    {
        if ($commission->statut !== 'CALCULEE') {
            throw new Exception("Seule une commission CALCULEE peut être validée.");
        }

        DB::beginTransaction();
        try {
            $ancien = $commission->statut;
            $commission->update(['statut' => 'VALIDEE']);
            CommissionHistorique::create([
                'id_commission'  => $commission->id,
                'ancien_statut'  => $ancien,
                'nouveau_statut' => 'VALIDEE',
                'id_utilisateur' => $validateur->id,
                'commentaire'    => 'Validation manuelle',
            ]);
            DB::commit();
            return $commission;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function payerCommission(CommissionGeneree $commission, Utilisateur $payeur, ?string $commentaire = null): CommissionGeneree
    {
        if ($commission->statut !== 'VALIDEE') {
            throw new Exception("Seule une commission VALIDEE peut être payée.");
        }

        DB::beginTransaction();
        try {
            $ancien = $commission->statut;
            $commission->update(['statut' => 'PAYEE']);
            CommissionHistorique::create([
                'id_commission'  => $commission->id,
                'ancien_statut'  => $ancien,
                'nouveau_statut' => 'PAYEE',
                'id_utilisateur' => $payeur->id,
                'commentaire'    => $commentaire ?? 'Paiement effectué',
            ]);
            DB::commit();
            return $commission;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function annulerCommission(CommissionGeneree $commission, Utilisateur $auteur, string $motif): CommissionGeneree
    {
        if ($commission->statut === 'PAYEE') {
            throw new Exception("Impossible d'annuler une commission payée.");
        }

        DB::beginTransaction();
        try {
            $ancien = $commission->statut;
            $commission->update(['statut' => 'ANNULEE']);
            CommissionHistorique::create([
                'id_commission'  => $commission->id,
                'ancien_statut'  => $ancien,
                'nouveau_statut' => 'ANNULEE',
                'id_utilisateur' => $auteur->id,
                'commentaire'    => $motif,
            ]);
            DB::commit();
            return $commission;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // ============================================================
    // 7. BONUS A LA CLOTURE
    // ============================================================

    protected function calculerBonusCampagne(Campagne $campagne): void
    {
        $reglesBonus = CampagneRegle::where('id_campagne', $campagne->id)
            ->where('type_regle', 'BONUS')
            ->actives()
            ->get();

        if ($reglesBonus->isEmpty()) return;

        $classement = CampagneOperation::where('id_campagne', $campagne->id)
            ->where('compte_dans_objectif', true)
            ->where('type_operation', 'VENTE')                       
            ->select('id_agent', DB::raw(
                "COUNT(DISTINCT SUBSTRING_INDEX(id_operation_source, ':', 1)) as score"
            ))
            ->groupBy('id_agent')
            ->orderByDesc('score')
            ->get();

        foreach ($reglesBonus as $regle) {
            $rangCible = $regle->condition_bonus['rang'] ?? null;
            if (!$rangCible) continue;

            $gagnant = $classement->skip($rangCible - 1)->first();
            if (!$gagnant) continue;

            $commission = CommissionGeneree::create([
                'id_agent'            => $gagnant->id_agent,
                'id_campagne'         => $campagne->id,
                'id_partenaire'       => $campagne->id_partenaire,
                'id_operation_source' => "BONUS-{$campagne->code}-RANG{$rangCible}",
                'id_regle_commission' => $regle->id,
                'montant_calcule'     => $regle->montant_bonus,
                'statut'              => 'VALIDEE',
            ]);

            CommissionHistorique::create([
                'id_commission'  => $commission->id,
                'ancien_statut'  => null,
                'nouveau_statut' => 'VALIDEE',
                'id_utilisateur' => $campagne->id_validateur ?? $campagne->id_createur,
                'commentaire'    => "Bonus rang {$rangCible}",
            ]);
        }
    }

    // ============================================================
    // 8. FILTRES DYNAMIQUES
    // ============================================================

    public function filtrerCampagnes(array $filtres = [])
    {
        $query = Campagne::with(['partenaire', 'createur']);

        if (!empty($filtres['statut'])) {
            $query->whereIn('statut', (array) $filtres['statut']);
        }
        if (!empty($filtres['type_campagne'])) {
            $query->where('type_campagne', $filtres['type_campagne']);
        }
        if (!empty($filtres['id_partenaire'])) {
            $query->where('id_partenaire', $filtres['id_partenaire']);
        }
        if (!empty($filtres['date_debut'])) {
            $query->where('date_debut', '>=', $filtres['date_debut']);
        }
        if (!empty($filtres['date_fin'])) {
            $query->where('date_fin', '<=', $filtres['date_fin']);
        }
        if (!empty($filtres['search'])) {
            $query->where(function ($q) use ($filtres) {
                $q->where('code', 'like', "%{$filtres['search']}%")
                  ->orWhere('nom', 'like', "%{$filtres['search']}%");
            });
        }
        if (!empty($filtres['id_agent'])) {
            $query->whereHas('agents', fn($q) => $q->where('id_agent', $filtres['id_agent']));
        }
        if (!empty($filtres['id_zone'])) {
            $query->whereHas('zones', fn($q) => $q->where('id_zone', $filtres['id_zone']));
        }

        return $query->orderByDesc('created_at');
    }

    public function filtrerOperations(int $idCampagne, array $filtres = [])
    {
        $query = CampagneOperation::where('id_campagne', $idCampagne)
            ->with(['agent', 'produit']);

        if (!empty($filtres['type_operation'])) {
            $query->where('type_operation', $filtres['type_operation']);
        }
        if (!empty($filtres['id_agent'])) {
            $query->where('id_agent', $filtres['id_agent']);
        }
        if (!empty($filtres['id_produit'])) {
            $query->where('id_produit', $filtres['id_produit']);
        }
        if (!empty($filtres['date_debut'])) {
            $query->where('date_operation', '>=', $filtres['date_debut']);
        }
        if (!empty($filtres['date_fin'])) {
            $query->where('date_operation', '<=', $filtres['date_fin']);
        }
        if (!empty($filtres['statut_commission'])) {
            $query->whereHas('commissions', fn($q) => $q->where('statut', $filtres['statut_commission']));
        }

        return $query->orderByDesc('date_operation');
    }

    // ============================================================
    // 9. STATISTIQUES GLOBALES
    // ============================================================

    public function statistiquesCampagne(int $idCampagne): array
    {
        $campagne = Campagne::with('partenaire')->findOrFail($idCampagne);

        $totalAgents = CampagneAgent::where('id_campagne', $idCampagne)
            ->distinct('id_agent')->count('id_agent');

        $agentsActifs = CampagneAgent::where('id_campagne', $idCampagne)
            ->where('statut_affectation', 'ACTIVE')
            ->whereNull('date_fin_affectation')
            ->count();

        $totalOperations = CampagneOperation::where('id_campagne', $idCampagne)
            ->where('compte_dans_objectif', true)->count();

        $caGenere = CampagneOperation::where('id_campagne', $idCampagne)
            ->where('compte_dans_objectif', true)->sum('montant_operation');

        $commissionsTotales = CommissionGeneree::where('id_campagne', $idCampagne)->sum('montant_calcule');
        $commissionsPayees = CommissionGeneree::where('id_campagne', $idCampagne)
            ->where('statut', 'PAYEE')->sum('montant_calcule');
        $commissionsEnAttente = CommissionGeneree::where('id_campagne', $idCampagne)
            ->whereIn('statut', ['CALCULEE', 'VALIDEE'])->sum('montant_calcule');

        // ✅ OBJECTIFS DYNAMIQUES VIA JSON
        $produitsCampagne = CampagneProduit::where('id_campagne', $idCampagne)->get();
        $objectifsParType = [];

        foreach ($produitsCampagne as $cp) {
            foreach (($cp->objectifs ?? []) as $type => $valeur) {
                $objectifsParType[$type] = ($objectifsParType[$type] ?? 0) + (float) $valeur;
            }
            // Compatibilité legacy
            if (empty($cp->objectifs)) {
                $objectifsParType['quantite'] = ($objectifsParType['quantite'] ?? 0) + (int) $cp->objectif_quantite;
                $objectifsParType['montant']  = ($objectifsParType['montant']  ?? 0) + (float) $cp->objectif_montant;
            }
        }

        // ✅ RÉALISATIONS DYNAMIQUES (à partir des opérations)
        $rows = CampagneOperation::where('id_campagne', $idCampagne)
            ->where('compte_dans_objectif', true)
            ->selectRaw("
                type_operation,
                COUNT(*) as nb,
                COALESCE(SUM(quantite_operation),0) as qte,
                COALESCE(SUM(montant_operation),0)  as montant
            ")
            ->groupBy('type_operation')
            ->get();

        $realisationsParType = [];
        foreach ($rows as $r) {
            switch ($r->type_operation) {
                case 'VENTE':
                    $realisationsParType['quantite'] = ($realisationsParType['quantite'] ?? 0) + (int) $r->qte;
                    $realisationsParType['montant']  = ($realisationsParType['montant']  ?? 0) + (float) $r->montant;
                    break;
                case 'CREATION_CLIENT':
                    $realisationsParType['client']   = ($realisationsParType['client']   ?? 0) + (int) $r->nb;
                    break;
                case 'VISITE':
                    $realisationsParType['visite']   = ($realisationsParType['visite']   ?? 0) + (int) $r->nb;
                    break;
                case 'PLAINTE':
                    $realisationsParType['plainte']  = ($realisationsParType['plainte']  ?? 0) + (int) $r->nb;
                    break;
                case 'SUGGESTION':
                    $realisationsParType['suggestion']= ($realisationsParType['suggestion']??0) + (int) $r->nb;
                    break;
                case 'COLLECTE_EPARGNE':
                    $realisationsParType['epargne']  = ($realisationsParType['epargne']  ?? 0) + (int) $r->nb;
                    break;
            }
        }

        // Fusion des clés
        $toutesLesCles = array_unique(array_merge(
            array_keys($objectifsParType),
            array_keys($realisationsParType)
        ));

        $parType = [];
        foreach ($toutesLesCles as $type) {
            $objectif  = (float) ($objectifsParType[$type]  ?? 0);
            $realise   = (float) ($realisationsParType[$type] ?? 0);
            $parType[$type] = [
                'objectif' => $objectif,
                'realise'  => $realise,
                'taux'     => $objectif > 0 ? round(($realise / $objectif) * 100, 2) : 0,
            ];
        }

        $objectifGlobal     = (int)   ($objectifsParType['quantite']   ?? 0);
        $realisationGlobale = (int)   ($realisationsParType['quantite'] ?? 0);
        $tauxAtteinte       = $objectifGlobal > 0
            ? round(($realisationGlobale / $objectifGlobal) * 100, 2)
            : 0;

        $joursRestants = $campagne->date_fin->isFuture()
            ? now()->diffInDays($campagne->date_fin)
            : 0;

        return [
            'campagne' => [
                'id'         => $campagne->id,
                'code'       => $campagne->code,
                'nom'        => $campagne->nom,
                'statut'     => $campagne->statut,
                'partenaire' => $campagne->partenaire?->nom,
                'date_debut' => $campagne->date_debut->format('d/m/Y'),
                'date_fin'   => $campagne->date_fin->format('d/m/Y'),
                'jours_restants' => $joursRestants,
            ],
            'agents' => [
                'total'      => $totalAgents,
                'actifs'     => $agentsActifs,
                'retires'    => $totalAgents - $agentsActifs,
            ],
            'operations' => [
                'total'     => $totalOperations,
                'ca_genere' => round($caGenere, 2),
                'par_type'  => CampagneOperation::where('id_campagne', $idCampagne)
                    ->select('type_operation', DB::raw('COUNT(*) as total'))
                    ->groupBy('type_operation')->pluck('total', 'type_operation'),
            ],
            'commissions' => [
                'total'       => round($commissionsTotales, 2),
                'payees'      => round($commissionsPayees, 2),
                'en_attente'  => round($commissionsEnAttente, 2),
            ],
            'objectifs' => [
                'objectif_global'    => $objectifGlobal,
                'realisation_globale'=> round($realisationGlobale, 2),
                'taux_atteinte'      => $tauxAtteinte,
                'par_type'           => $parType, // ✅ Ajout dynamique
            ],
        ];
    }

    // ============================================================
    // 10. COMPARAISONS
    // ============================================================

    public function comparaisonParAgent(int $idCampagne): Collection
    {
        $affectations = CampagneAgent::where('id_campagne', $idCampagne)->get();

        if ($affectations->isEmpty()) {
            return collect();
        }

        $idsAgents = $affectations->pluck('id_agent')->unique()->values();

        $agents = AgentDsa::with(['utilisateur', 'zone'])
            ->whereIn('id', $idsAgents)
            ->get()
            ->keyBy('id');

        $stocksParAffectation = CampagneAgentStock::whereIn(
            'id_campagne_agent',
            $affectations->pluck('id')
        )->get()->groupBy('id_campagne_agent');

        $produits = Produit::whereIn(
            'id',
            $stocksParAffectation->flatten()->pluck('id_produit')->unique()
        )->get()->keyBy('id');

        $operationsParAgent = CampagneOperation::where('id_campagne', $idCampagne)
            ->where('compte_dans_objectif', true)
            ->whereIn('id_agent', $idsAgents)
            ->with('produit')
            ->get()
            ->groupBy('id_agent');

        $commissionsParAgent = CommissionGeneree::where('id_campagne', $idCampagne)
            ->whereIn('id_agent', $idsAgents)
            ->select('id_agent', DB::raw('SUM(montant_calcule) as total'))
            ->groupBy('id_agent')
            ->pluck('total', 'id_agent');

        return $affectations->map(function ($affectation) use (
            $agents,
            $stocksParAffectation,
            $produits,
            $operationsParAgent,
            $commissionsParAgent
        ) {
            $agent = $agents[$affectation->id_agent] ?? null;
            $ops = $operationsParAgent[$affectation->id_agent] ?? collect();
            $stocks = $stocksParAffectation[$affectation->id] ?? collect();

            // Objectifs globaux de l'agent
            $objectifsAgent = [];
            foreach ($stocks as $stock) {
                foreach (($stock->objectifs ?? []) as $type => $val) {
                    $objectifsAgent[$type] = ($objectifsAgent[$type] ?? 0) + (float) $val;
                }
                if (empty($stock->objectifs)) {
                    $objectifsAgent['quantite'] = ($objectifsAgent['quantite'] ?? 0) + (int) $stock->quantite_remise;
                }
            }

            $objectif = (int) ($objectifsAgent['quantite'] ?? 0);
            $realisation = (int) $ops->where('type_operation', 'VENTE')->sum('quantite_operation');
            $nbOperations = $ops->count();
            $ca = (float) $ops->sum('montant_operation');
            $commissions = (float) ($commissionsParAgent[$affectation->id_agent] ?? 0);

            $taux = $objectif > 0 ? round(($realisation / $objectif) * 100, 2) : 0;

            // Réalisations par type
            $realisationsAgent = [
                'quantite'   => $realisation,
                'montant'    => $ca,
                'client'     => (int) $ops->where('type_operation', 'CREATION_CLIENT')->count(),
                'visite'     => (int) $ops->where('type_operation', 'VISITE')->count(),
                'plainte'    => (int) $ops->where('type_operation', 'PLAINTE')->count(),
                'suggestion' => (int) $ops->where('type_operation', 'SUGGESTION')->count(),
            ];

            $produitsDetail = $stocks->map(function ($stock) use ($ops, $produits) {
                $produit = $produits[$stock->id_produit] ?? null;
                $remise = (int) $stock->quantite_remise;
                $vendue = (int) $ops->where('id_produit', $stock->id_produit)->where('type_operation', 'VENTE')->sum('quantite_operation');
                $pct = $remise > 0 ? min(100, round(($vendue / $remise) * 100, 2)) : 0;

                return [
                    'id_produit' => $stock->id_produit,
                    'nom'        => $produit?->nom,
                    'image'      => $produit?->image_principale ?? $produit?->image_url,
                    'remise'     => $remise,
                    'vendue'     => $vendue,
                    'pct'        => $pct,
                    'objectifs'  => $stock->objectifs ?? [],
                ];
            })->values();

            $nomAgent = $agent?->utilisateur?->nom_complet ?? $agent?->nomComplet() ?? 'Inconnu';

            $formattedOps = $ops->map(function($o) use ($nomAgent) {
                return [
                    'id' => $o->id,
                    'date' => $o->date_operation ? $o->date_operation->format('d/m/Y') : now()->format('d/m/Y'),
                    'dayOfWeek' => $o->date_operation ? $o->date_operation->translatedFormat('l') : now()->translatedFormat('l'),
                    'time' => $o->date_operation ? $o->date_operation->format('H:i') : '00:00',
                    'type' => $o->type_operation,
                    'agentName' => $nomAgent,
                    'productName' => $o->produit?->nom ?? 'Inconnu',
                    'productImage' => $o->produit?->image_principale ?? $o->produit?->image_url,
                    'quantity' => $o->quantite_operation,
                    'amount' => $o->montant_operation,
                ];
            })->values();

            return [
                'id_agent'               => $affectation->id_agent,
                'agent'                  => $nomAgent,
                'telephone'              => $agent?->telephone ?? $agent?->utilisateur?->telephone,
                'zone'                   => $agent?->zone?->nom,
                'statut'                 => $affectation->statut_affectation,
                'objectif'               => $objectif,
                'realisation'            => $realisation,
                'taux_atteinte'          => $taux,
                'nb_operations'          => $nbOperations,
                'ca_genere'              => round($ca, 2),
                'commissions'            => round($commissions, 2),
                'en_retard'              => $taux < 50,
                'a_atteint'              => $taux >= 100,
                'objectifs_par_type'     => $objectifsAgent,      // ✅ Ajout
                'realisations_par_type'  => $realisationsAgent,   // ✅ Ajout
                'produits'               => $produitsDetail,
                'operations'             => $formattedOps,
                'chartData'              => [],
            ];
        })->sortByDesc('taux_atteinte')->values();
    }

    public function comparaisonParProduit(int $idCampagne): Collection
    {
        $campagneProduits = CampagneProduit::where('id_campagne', $idCampagne)->get();

        if ($campagneProduits->isEmpty()) {
            return collect();
        }

        $idsProduits = $campagneProduits->pluck('id_produit')->unique();
        $produits = Produit::whereIn('id', $idsProduits)->get()->keyBy('id');

        $opsParProduit = CampagneOperation::where('id_campagne', $idCampagne)
            ->where('compte_dans_objectif', true)
            ->whereIn('id_produit', $idsProduits)
            ->with('produit')
            ->get()
            ->groupBy('id_produit');

        $stocks = CampagneAgentStock::whereIn('id_produit', $idsProduits)
            ->whereIn('id_campagne_agent', function ($q) use ($idCampagne) {
                $q->select('id')->from('campagne_agents')->where('id_campagne', $idCampagne);
            })
            ->get()
            ->groupBy('id_produit');

        $affectations = CampagneAgent::where('id_campagne', $idCampagne)->get()->keyBy('id');

        $agents = AgentDsa::with('utilisateur')
            ->whereIn('id', $affectations->pluck('id_agent')->unique())
            ->get()
            ->keyBy('id');

        return $campagneProduits->map(function ($cp) use (
            $produits, $opsParProduit, $stocks, $affectations, $agents
        ) {
            $produit = $produits[$cp->id_produit] ?? null;
            $ops = $opsParProduit[$cp->id_produit] ?? collect();

            $vendu = (int) $ops->where('type_operation', 'VENTE')->sum('quantite_operation');
            $ca = (float) $ops->sum('montant_operation');
            
            $objectif = (int) ($cp->objectifs['quantite'] ?? $cp->objectif_quantite);
            $taux = $objectif > 0 ? round(($vendu / $objectif) * 100, 2) : 0;

            $realisationsProduit = [
                'quantite'   => $vendu,
                'montant'    => $ca,
                'client'     => (int) $ops->where('type_operation', 'CREATION_CLIENT')->count(),
                'visite'     => (int) $ops->where('type_operation', 'VISITE')->count(),
                'plainte'    => (int) $ops->where('type_operation', 'PLAINTE')->count(),
                'suggestion' => (int) $ops->where('type_operation', 'SUGGESTION')->count(),
            ];

            $agentsProduit = ($stocks[$cp->id_produit] ?? collect())
                ->map(function ($stock) use ($opsParProduit, $affectations, $agents) {
                    $affectation = $affectations[$stock->id_campagne_agent] ?? null;
                    if (!$affectation) return null;

                    $agent = $agents[$affectation->id_agent] ?? null;
                    $opsAgentProduit = ($opsParProduit[$affectation->id_agent] ?? collect())
                        ->where('id_produit', $stock->id_produit)
                        ->where('type_operation', 'VENTE')
                        ->sum('quantite_operation');

                    $remise = (int) $stock->quantite_remise;
                    $vendue = (int) $opsAgentProduit;
                    $pct = $remise > 0 ? min(100, round(($vendue / $remise) * 100, 2)) : 0;

                    return [
                        'id_agent' => $affectation->id_agent,
                        'nom'      => $agent?->utilisateur?->nom_complet ?? 'Inconnu',
                        'remise'   => $remise,
                        'vendue'   => $vendue,
                        'pct'      => $pct,
                    ];
                })->filter()->values();

            $formattedOps = $ops->map(function($o) use ($produit) {
                return [
                    'id' => $o->id,
                    'date' => $o->date_operation ? $o->date_operation->format('d/m/Y') : now()->format('d/m/Y'),
                    'dayOfWeek' => $o->date_operation ? $o->date_operation->translatedFormat('l') : now()->translatedFormat('l'),
                    'time' => $o->date_operation ? $o->date_operation->format('H:i') : '00:00',
                    'type' => $o->type_operation,
                    'agentName' => 'Agent', 
                    'productName' => $o->produit?->nom ?? $produit?->nom ?? 'Inconnu',
                    'productImage' => $o->produit?->image_principale ?? $o->produit?->image_url ?? $produit?->image_principale,
                    'quantity' => $o->quantite_operation,
                    'amount' => $o->montant_operation,
                ];
            })->values();

            return [
                'id_produit'             => $cp->id_produit,
                'produit'                => $produit?->nom,
                'image'                  => $produit?->image_principale ?? $produit?->image_url,
                'objectif'               => $objectif,
                'vendu'                  => $vendu,
                'taux_atteinte'          => $taux,
                'ca_genere'              => round($ca, 2),
                'reste_a_vendre'         => max(0, $objectif - $vendu),
                'a_atteint'              => $taux >= 100,
                'objectifs_par_type'     => $cp->objectifs ?? [],           // ✅ Ajout
                'realisations_par_type'  => $realisationsProduit,           // ✅ Ajout
                'agents'                 => $agentsProduit,
                'operations'             => $formattedOps,
                'chartData'              => [],
            ];
        })->sortByDesc('taux_atteinte')->values();
    }

    public function comparaisonParZone(int $idCampagne): Collection
    {
        $zonesPivot = CampagneZone::where('id_campagne', $idCampagne)->with('zone')->get();

        if ($zonesPivot->isEmpty()) {
            return collect();
        }

        $affectations = CampagneAgent::where('id_campagne', $idCampagne)->get();

        $agents = AgentDsa::with('utilisateur')
            ->whereIn('id', $affectations->pluck('id_agent')->unique())
            ->get()
            ->keyBy('id');

        $opsParAgent = CampagneOperation::where('id_campagne', $idCampagne)
            ->where('compte_dans_objectif', true)
            ->with('produit')
            ->get()
            ->groupBy('id_agent');

        $stocksParAffectation = CampagneAgentStock::whereIn(
            'id_campagne_agent',
            $affectations->pluck('id')
        )->get()->groupBy('id_campagne_agent');

        return $zonesPivot->map(function ($zonePivot) use ($affectations, $agents, $opsParAgent, $stocksParAffectation) {
            $affectationsZone = $affectations->where('id_zone', $zonePivot->id_zone);
            $idsAgentsZone = $affectationsZone->pluck('id_agent')->unique();

            $opsZone = collect();
            foreach ($idsAgentsZone as $idAgent) {
                $opsZone = $opsZone->merge($opsParAgent[$idAgent] ?? collect());
            }

            $realisation = (int) $opsZone->where('type_operation', 'VENTE')->sum('quantite_operation');
            $ca = (float) $opsZone->sum('montant_operation');

            $objectif = 0;
            foreach ($affectationsZone as $aff) {
                $stocksAff = $stocksParAffectation[$aff->id] ?? collect();
                foreach ($stocksAff as $stock) {
                    $objectif += (int) ($stock->objectifs['quantite'] ?? $stock->quantite_remise);
                }
            }

            $taux = $objectif > 0 ? round(($realisation / $objectif) * 100, 2) : 0;

            $realisationsZone = [
                'quantite'   => $realisation,
                'montant'    => $ca,
                'client'     => (int) $opsZone->where('type_operation', 'CREATION_CLIENT')->count(),
                'visite'     => (int) $opsZone->where('type_operation', 'VISITE')->count(),
                'plainte'    => (int) $opsZone->where('type_operation', 'PLAINTE')->count(),
                'suggestion' => (int) $opsZone->where('type_operation', 'SUGGESTION')->count(),
            ];

            $agentsDetail = $affectationsZone->map(function ($aff) use ($agents, $opsParAgent, $stocksParAffectation) {
                $agent = $agents[$aff->id_agent] ?? null;
                $ops = $opsParAgent[$aff->id_agent] ?? collect();
                $stocksAff = $stocksParAffectation[$aff->id] ?? collect();

                return [
                    'id_agent'       => $aff->id_agent,
                    'nom'            => $agent?->utilisateur?->nom_complet ?? 'Inconnu',
                    'nb_operations'  => $ops->count(),
                    'ca_genere'      => round((float) $ops->sum('montant_operation'), 2),
                    'unites_remises' => (int) $stocksAff->sum('quantite_remise'),
                ];
            })->sortByDesc('nb_operations')->values();

            $maxOps = $agentsDetail->max('nb_operations') ?: 0;
            $agentsDetail = $agentsDetail->map(function ($a) use ($maxOps) {
                $a['pct'] = $maxOps > 0 ? round(($a['nb_operations'] / $maxOps) * 100, 2) : 0;
                return $a;
            });

            $formattedOps = $opsZone->map(function($o) {
                return [
                    'id' => $o->id,
                    'date' => $o->date_operation ? $o->date_operation->format('d/m/Y') : now()->format('d/m/Y'),
                    'dayOfWeek' => $o->date_operation ? $o->date_operation->translatedFormat('l') : now()->translatedFormat('l'),
                    'time' => $o->date_operation ? $o->date_operation->format('H:i') : '00:00',
                    'type' => $o->type_operation,
                    'agentName' => 'Agent',
                    'productName' => $o->produit?->nom ?? 'Inconnu',
                    'productImage' => $o->produit?->image_principale ?? $o->produit?->image_url,
                    'quantity' => $o->quantite_operation,
                    'amount' => $o->montant_operation,
                ];
            })->values();

            return [
                'id_zone'                => $zonePivot->id_zone,
                'nom'                    => $zonePivot->zone?->nom ?? 'Zone inconnue',
                'nb_agents'              => $idsAgentsZone->count(),
                'objectif'               => $objectif,
                'realisation'            => $realisation,
                'ca_genere'              => round($ca, 2),
                'taux_atteinte'          => $taux,
                'realisations_par_type'  => $realisationsZone,    // ✅ Ajout
                'agents'                 => $agentsDetail,
                'operations'             => $formattedOps,
                'chartData'              => [],
            ];
        })->sortByDesc('taux_atteinte')->values();
    }

    // ============================================================
    // 10bis. ÉVOLUTIONS
    // ============================================================

    public function evolutionParZone(int $idCampagne): array
    {
        $zonesPivot = CampagneZone::where('id_campagne', $idCampagne)->with('zone')->get();
        if ($zonesPivot->isEmpty()) return [];

        $operations = CampagneOperation::where('id_campagne', $idCampagne)->where('compte_dans_objectif', true)->get();
        $affectations = CampagneAgent::where('id_campagne', $idCampagne)->get();

        $result = [];
        foreach ($zonesPivot as $zonePivot) {
            $idsAgentsZone = $affectations->where('id_zone', $zonePivot->id_zone)->pluck('id_agent')->unique();
            $opsZone = $operations->whereIn('id_agent', $idsAgentsZone);
            $nom = $zonePivot->zone?->nom ?? 'Zone inconnue';

            $result[$nom] = [
                'ca'       => round((float) $opsZone->sum('montant_operation'), 2),
                'quantite' => (int) $opsZone->where('type_operation', 'VENTE')->sum('quantite_operation'),
            ];
        }
        return $result;
    }

    public function evolutionParProduit(int $idCampagne): array
    {
        $campagneProduits = CampagneProduit::where('id_campagne', $idCampagne)->with('produit')->get();
        if ($campagneProduits->isEmpty()) return [];

        $operations = CampagneOperation::where('id_campagne', $idCampagne)->where('compte_dans_objectif', true)->get();

        $result = [];
        foreach ($campagneProduits as $cp) {
            $opsProduit = $operations->where('id_produit', $cp->id_produit);
            $nom = $cp->produit?->nom ?? 'Produit inconnu';

            $result[$nom] = [
                'ca'       => round((float) $opsProduit->sum('montant_operation'), 2),
                'quantite' => (int) $opsProduit->where('type_operation', 'VENTE')->sum('quantite_operation'),
            ];
        }
        return $result;
    }

    public function evolutionParAgent(int $idCampagne): array
    {
        $affectations = CampagneAgent::where('id_campagne', $idCampagne)->get();
        if ($affectations->isEmpty()) return [];

        $agents = AgentDsa::with('utilisateur')->whereIn('id', $affectations->pluck('id_agent')->unique())->get()->keyBy('id');
        $operations = CampagneOperation::where('id_campagne', $idCampagne)->where('compte_dans_objectif', true)->get();

        $result = [];
        foreach ($affectations as $aff) {
            $opsAgent = $operations->where('id_agent', $aff->id_agent);
            $nom = $agents[$aff->id_agent]?->utilisateur?->nom_complet ?? 'Inconnu';

            $result[$nom] = [
                'ca'       => round((float) $opsAgent->sum('montant_operation'), 2),
                'quantite' => (int) $opsAgent->where('type_operation', 'VENTE')->sum('quantite_operation'),
            ];
        }
        return $result;
    }

    // ============================================================
    // 11. CLASSEMENT
    // ============================================================

    public function classementAgents(int $idCampagne, int $limit = 10): Collection
    {
        return $this->comparaisonParAgent($idCampagne)
            ->take($limit)
            ->map(fn($agent, $index) => array_merge($agent, ['rang' => $index + 1]));
    }

    // ============================================================
    // 12. DASHBOARD MOBILE AGENT
    // ============================================================

    public function dashboardAgent(int $idAgent): Collection
    {
        $campagnes = CampagneAgent::where('id_agent', $idAgent)
            ->where('statut_affectation', 'ACTIVE')
            ->whereNull('date_fin_affectation')
            ->with(['campagne.partenaire', 'stocks.produit'])
            ->get();

        return $campagnes->map(function ($affectation) use ($idAgent) {
            $campagne = $affectation->campagne;
            if (!$campagne || $campagne->statut !== 'ACTIVE') return null;

            // Calcul des objectifs et réalisations via le JSON
            $objectifsAgent = [];
            foreach ($affectation->stocks as $stock) {
                foreach (($stock->objectifs ?? []) as $type => $val) {
                    $objectifsAgent[$type] = ($objectifsAgent[$type] ?? 0) + (float) $val;
                }
                if (empty($stock->objectifs)) {
                    $objectifsAgent['quantite'] = ($objectifsAgent['quantite'] ?? 0) + (int) $stock->quantite_remise;
                }
            }

            $objectif = (int) ($objectifsAgent['quantite'] ?? 0);
            $realisation = (int) CampagneOperation::where('id_campagne', $campagne->id)
                ->where('id_agent', $idAgent)
                ->where('type_operation', 'VENTE')
                ->sum('quantite_operation');

            $nbOperations = CampagneOperation::where('id_campagne', $campagne->id)
                ->where('id_agent', $idAgent)
                ->where('compte_dans_objectif', true)
                ->count();

            $commissionsTotal = CommissionGeneree::where('id_campagne', $campagne->id)
                ->where('id_agent', $idAgent)
                ->sum('montant_calcule');

            $commissionsPayees = CommissionGeneree::where('id_campagne', $campagne->id)
                ->where('id_agent', $idAgent)
                ->where('statut', 'PAYEE')->sum('montant_calcule');

            $taux = $objectif > 0 ? round(($realisation / $objectif) * 100, 2) : 0;

            $classement = $this->comparaisonParAgent($campagne->id);
            $rang = $classement->search(fn($a) => $a['id_agent'] === $idAgent) + 1;

            return [
                'campagne_id'       => $campagne->id,
                'campagne_nom'      => $campagne->nom,
                'partenaire'        => $campagne->partenaire?->nom,
                'date_fin'          => $campagne->date_fin->format('d/m/Y'),
                'jours_restants'    => now()->diffInDays($campagne->date_fin, false),
                'objectif'          => $objectif,
                'realisation'       => round($realisation, 2),
                'taux_atteinte'     => $taux,
                'nb_operations'     => $nbOperations,
                'commissions_total' => round($commissionsTotal, 2),
                'commissions_payees'=> round($commissionsPayees, 2),
                'rang'              => $rang,
                'total_agents'      => $classement->count(),
                'objectifs_par_type'=> $objectifsAgent, // ✅ Ajout
                'stocks'            => $affectation->stocks->map(fn($s) => [
                    'produit' => $s->produit?->nom,
                    'quantite_remise' => $s->quantite_remise,
                    'quantite_vendue' => $s->quantite_vendue,
                    'stock_restant'   => $s->stock_restant,
                    'objectifs'       => $s->objectifs, // ✅ Ajout
                ]),
            ];
        })->filter()->values();
    }

    // ============================================================
    // 13. DASHBOARD PARTENAIRE
    // ============================================================

    public function dashboardPartenaire(int $idPartenaire): array
    {
        $campagnes = Campagne::where('id_partenaire', $idPartenaire)->get();

        return [
            'nb_campagnes'      => $campagnes->count(),
            'campagnes_actives' => $campagnes->where('statut', 'ACTIVE')->count(),
            'ca_total'          => round(CampagneOperation::whereIn('id_campagne', $campagnes->pluck('id'))
                                    ->where('compte_dans_objectif', true)
                                    ->sum('montant_operation'), 2),
            'commissions_total' => round(CommissionGeneree::where('id_partenaire', $idPartenaire)
                                    ->sum('montant_calcule'), 2),
            'commissions_payees'=> round(CommissionGeneree::where('id_partenaire', $idPartenaire)
                                    ->where('statut', 'PAYEE')->sum('montant_calcule'), 2),
            'nb_agents'         => CampagneAgent::whereIn('id_campagne', $campagnes->pluck('id'))
                                    ->distinct('id_agent')->count('id_agent'),
            'nb_operations'     => CampagneOperation::whereIn('id_campagne', $campagnes->pluck('id'))
                                    ->where('compte_dans_objectif', true)->count(),
        ];
    }

    // ============================================================
    // 14. MÉTHODES D'ANALYSE DYNAMIQUES
    // ============================================================

    public function evolutionGlobale(int $idCampagne): array
    {
        $campagne = Campagne::findOrFail($idCampagne);
        $debut = $campagne->date_debut->copy()->startOfDay();
        $fin   = $campagne->date_fin->copy()->endOfDay();

        $operations = CampagneOperation::where('id_campagne', $idCampagne)
            ->where('compte_dans_objectif', true)
            ->whereBetween('date_operation', [$debut, $fin])
            ->get()
            ->groupBy(fn($o) => $o->date_operation->format('Y-m-d'));
            
        $result = [];
        $cumul = 0;
        for ($d = $debut->copy(); $d <= $fin; $d->addDay()) {
            $key  = $d->format('Y-m-d');
            $jour = $operations->get($key, collect());
            $cumul += (int) $jour->where('type_operation', 'VENTE')->sum('quantite_operation');
            $result[] = [
                'date'       => $d->format('d/m/Y'),
                'dayOfWeek'  => $d->translatedFormat('l'),
                'cumul'      => $cumul,
                'ventesJour' => (int) $jour->where('type_operation', 'VENTE')->sum('quantite_operation'),
            ];
        }
        return $result;
    }

    public function topAgentsParType(int $idCampagne, int $limit = 5): array
{
    $map = [
        'client'     => 'CREATION_CLIENT',
        'visite'     => 'VISITE',
        'plainte'    => 'PLAINTE',
        'suggestion' => 'SUGGESTION',
        'quantite'   => 'VENTE',
    ];

    $result = [];
    foreach ($map as $key => $opType) {

        $query = CampagneOperation::where('id_campagne', $idCampagne)
            ->where('compte_dans_objectif', true)
            ->where('type_operation', $opType);

        // Pour VENTE, compter les factures distinctes ; sinon COUNT(*)
        if ($opType === 'VENTE') {
            // id_operation_source = "{facture_id}:{produit_id}" → extraire facture_id
            $query->select('id_agent', DB::raw(
                "COUNT(DISTINCT SUBSTRING_INDEX(id_operation_source, ':', 1)) as total"
            ));
        } else {
            $query->select('id_agent', DB::raw('COUNT(*) as total'));
        }

        $result[$key] = $query
            ->groupBy('id_agent')
            ->orderByDesc('total')
            ->limit($limit)
            ->with('agent.utilisateur')
            ->get()
            ->map(fn($row) => [
                'id_agent' => $row->id_agent,
                'nom'      => $row->agent?->utilisateur?->nom_complet ?? 'Inconnu',
                'total'    => (int) $row->total,
            ]);
    }
    return $result;
}

    // ============================================================
    // 15. RÉCUPÉRATION DES DÉTAILS D'UNE OPÉRATION (UNIVERSEL)
    // ============================================================

    public function getDetailsOperationSource(CampagneOperation $operation): ?array
    {
        switch ($operation->type_operation) {
            case 'CREATION_CLIENT':
                $client = \App\Models\Client::find($operation->id_operation_source);
                return $client ? [
                    'type'          => 'Création de client',
                    'nom'           => $client->nom,
                    'telephone'     => $client->telephone,
                    'email'         => $client->email,
                    'adresse'       => $client->adresse,
                    'date_creation' => $client->created_at->format('d/m/Y H:i'),
                ] : null;
 
            case 'VENTE':
                $src = (string) $operation->id_operation_source;

                // Nouveau format : "{facture_id}:{produit_id}"
                if (str_contains($src, ':')) {
                    [$factureId, $produitId] = explode(':', $src, 2);

                    $facture = \App\Models\Facture::with(['lignes.produit', 'client'])->find($factureId);
                    if (!$facture) {
                        return null;
                    }

                    $produit = $operation->produit
                        ?? \App\Models\Produit::find($produitId);

                    return [
                        'type'       => 'Vente',
                        'reference'  => $facture->reference,
                        'produit'    => $produit?->nom,
                        'quantite'   => (int) $operation->quantite_operation,
                        'montant'    => (float) $operation->montant_operation,
                        'date_vente' => $facture->date_facture?->format('d/m/Y H:i'),
                        'client'     => $facture->client?->nom_complet,
                    ];
                }

                // Fallback : ancien format (id_operation_source = factures.id)
                $vente = \App\Models\Facture::with('lignes.produit')->find($src);
                return $vente ? [
                    'type'       => 'Vente (ancien format)',
                    'reference'  => $vente->reference,
                    'produit'    => null,
                    'quantite'   => (int) $vente->lignes->sum('quantite'),
                    'montant'    => (float) $vente->total_ttc,
                    'date_vente' => $vente->created_at?->format('d/m/Y H:i'),
                ] : null;
 
            case 'VISITE':
                $visite = Visite::with('client')->find($operation->id_operation_source);
                return $visite ? [
                    'type'          => 'Visite',
                    'client_nom'    => $visite->client?->nom ?? $visite->prospect_nom,
                    'client_tel'    => $visite->client?->telephone ?? $visite->prospect_telephone,
                    'date_visite'   => $visite->date_visite?->format('d/m/Y H:i') ?? $visite->created_at->format('d/m/Y H:i'),
                    'compte_rendu'  => $visite->compte_rendu,
                ] : null;

            case 'COLLECTE_EPARGNE':
                $epargne = ProduitEpargne::find($operation->id_operation_source);
                return $epargne ? [
                    'type'          => 'Collecte d\'épargne',
                    'montant'       => $epargne->montant,
                    'date_collecte' => $epargne->created_at->format('d/m/Y H:i'),
                    'client_nom'    => $epargne->client?->nom,
                ] : null;

            case 'PLAINTE':
            case 'SUGGESTION':
                return [
                    'type' => $operation->type_operation === 'PLAINTE' ? 'Plainte' : 'Suggestion',
                    'id'   => $operation->id_operation_source,
                ];

            default:
                return null;
        }
    }
}