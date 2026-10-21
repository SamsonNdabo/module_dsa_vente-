<?php

namespace App\Services;

use App\Models\Bilan;
use App\Models\BilanLigne;
use App\Models\PeriodesComptable;
use App\Models\PlanComptable;
use App\Models\EcrituresComptable;
use App\Models\TvaComptable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ClotureExerciceComptable
{
    const COMPTE_CAPITAL = '101';
    const COMPTE_RESULTAT_BENEFICE = '131';
    const COMPTE_RESULTAT_PERTE = '139';
    const COMPTE_REPORT_CREDITEUR = '121';
    const COMPTE_REPORT_DEBITEUR = '129';

    // =========================================================================
    // VÉRIFICATION AVANT CLÔTURE
    // =========================================================================

    public function verifierCoherenceCloture(int $periodeId, ?int $entiteId = null): array
    {
        $errors = [];
        $warnings = [];
        $periode = PeriodesComptable::find($periodeId);

        if (!$periode) {
            return [
                'peut_cloturer' => false,
                'errors'        => ['La periode comptable selectionnee est introuvable dans le systeme.'],
                'warnings'      => []
            ];
        }

        $entitesIds = $this->getEntitesIds($periodeId, $entiteId);

        if (empty($entitesIds)) {
            return [
                'peut_cloturer' => false,
                'errors'        => ['Aucune entite n\'a de bilan initial pour cette periode. Creez d\'abord un bilan initial.'],
                'warnings'      => []
            ];
        }

        foreach ($entitesIds as $eId) {
            $entite = \App\Models\Entite::find($eId);
            $nomEntite = $entite ? $entite->nom : "Entite {$eId}";

            // Vérifier l'équilibre de la balance
            $totalDebit = (float) EcrituresComptable::where('periode_id', $periodeId)
                ->where('entite_id', $eId)
                ->sum('debit');
            $totalCredit = (float) EcrituresComptable::where('periode_id', $periodeId)
                ->where('entite_id', $eId)
                ->sum('credit');
            $ecart = round(abs($totalDebit - $totalCredit), 2);

            if ($ecart > 0.01) {
                $errors[] = "La balance de l'entite '{$nomEntite}' n'est pas equilibree. " .
                    "Debit total = " . number_format($totalDebit, 2) . " $, " .
                    "Credit total = " . number_format($totalCredit, 2) . " $, " .
                    "Ecart = " . number_format($ecart, 2) . " $. " .
                    "Verifiez vos ecritures comptables avant de cloturer.";
            }

            // Vérifier l'existence du bilan initial
            $bilanInitial = Bilan::where('periode_id', $periodeId)
                ->where('type_bilan', 'initial')
                ->where('entite_id', $eId)
                ->first();

            if (!$bilanInitial) {
                $errors[] = "L'entite '{$nomEntite}' n'a pas de bilan initial pour cette periode. " .
                    "Creez un bilan initial avant de proceder a la cloture.";
            }

            // Vérifier la TVA
            $tvaOk = $this->verifierTvaRegularisee($periodeId, $eId, $periode);
            if (!$tvaOk) {
                $mois = Carbon::parse($periode->date_debut)->format('m/Y');
                $errors[] = "La TVA de la periode {$mois} n'est pas regularisee pour l'entite '{$nomEntite}'. " .
                    "Regularisez la TVA avant de cloturer l'exercice.";
            }
        }

        $resultatExercice = $this->calculerResultat($periodeId, $entiteId);

        return [
            'peut_cloturer'      => empty($errors),
            'errors'             => $errors,
            'warnings'           => $warnings,
            'ecart_balance'      => $ecart ?? 0,
            'resultat_exercice'  => round($resultatExercice, 2),
            'bilan_initial_existe' => !empty($entitesIds)
        ];
    }

    private function verifierTvaRegularisee(int $periodeId, int $entiteId, PeriodesComptable $periode): bool
    {
        $mois = Carbon::parse($periode->date_debut)->format('Y-m');

        // Vérifier si une déclaration TVA existe déjà
        $tva = TvaComptable::where('mois', $mois)
            ->where('entite_id', $entiteId)
            ->whereIn('statut', ['regularise', 'paye'])
            ->first();

        if ($tva) {
            return true;
        }

        // Vérifier les soldes des comptes de TVA
        $solde443 = (float) EcrituresComptable::where('periode_id', $periodeId)
            ->where('entite_id', $entiteId)
            ->where('compte_numero', 'LIKE', '443%')
            ->sum(DB::raw('credit - debit'));

        $solde445 = (float) EcrituresComptable::where('periode_id', $periodeId)
            ->where('entite_id', $entiteId)
            ->where('compte_numero', 'LIKE', '445%')
            ->sum(DB::raw('debit - credit'));

        // Si les deux soldes sont à zéro, pas de TVA à régulariser
        if (abs($solde443) < 0.01 && abs($solde445) < 0.01) {
            return true;
        }

        // Si crédit de TVA (445 > 443), pas de TVA à payer
        if ($solde445 > $solde443 && abs($solde443) < 0.01) {
            return true;
        }

        // Vérifier le crédit de TVA antérieur
        $creditAnterieur = (float) TvaComptable::where('mois', '<', $mois)
            ->where('entite_id', $entiteId)
            ->where('credit_tva_final_4449', '>', 0)
            ->orderBy('mois', 'desc')
            ->value('credit_tva_final_4449') ?? 0;

        $difference = $solde443 - $solde445 - $creditAnterieur;

        if ($difference <= 0) {
            return true;
        }

        // Vérifier s'il y a des écritures de TVA
        $hasEcritures = EcrituresComptable::where('periode_id', $periodeId)
            ->where('entite_id', $entiteId)
            ->where(function ($q) {
                $q->where('compte_numero', 'LIKE', '443%')
                  ->orWhere('compte_numero', 'LIKE', '445%');
            })->exists();

        if (!$hasEcritures) {
            return true;
        }

        return false;
    }

    public function calculerResultat(int $periodeId, ?int $entiteId = null): float
    {
        $totalCharges = (float) EcrituresComptable::where('compte_numero', 'LIKE', '6%')
            ->where('periode_id', $periodeId)
            ->where('reference', 'NOT LIKE', 'CLÔTURE%')
            ->where('reference', 'NOT LIKE', 'CLOTURE%')
            ->when($entiteId, fn($q) => $q->where('entite_id', $entiteId))
            ->sum(DB::raw('debit - credit'));

        $totalProduits = (float) EcrituresComptable::where('compte_numero', 'LIKE', '7%')
            ->where('periode_id', $periodeId)
            ->where('reference', 'NOT LIKE', 'CLÔTURE%')
            ->where('reference', 'NOT LIKE', 'CLOTURE%')
            ->when($entiteId, fn($q) => $q->where('entite_id', $entiteId))
            ->sum(DB::raw('credit - debit'));

        return round($totalProduits - $totalCharges, 2);
    }

    // =========================================================================
    // ACTION 1 : CLÔTURE PROVISOIRE
    // =========================================================================

    public function effectuerClotureProvisoire(int $periodeId, ?int $entiteId, string $dateFermeture): array
    {
        $periode = PeriodesComptable::findOrFail($periodeId);

        if ($periode->statut === 'cloture_definitive') {
            throw new \RuntimeException(
                "Cette periode est deja cloturee definitivement. " .
                "Aucune modification n'est possible."
            );
        }

        return DB::transaction(function () use ($periodeId, $entiteId, $dateFermeture, $periode) {

            // 1. Solder les comptes de charges (6) et produits (7)
            $this->solderChargesEtProduits($periodeId, $entiteId, $dateFermeture);

            // 2. Générer le Bilan de Fermeture PROVISOIRE N
            $this->genererBilansFermetureProvisoires($periodeId, $entiteId, $dateFermeture);

            // 3. Générer le Bilan Initial PROVISOIRE N+1
            $this->genererBilansInitiauxProvisoires($periodeId, $entiteId, $dateFermeture);

            // 4. Mettre à jour le statut de la période
            $periode->update([
                'statut'       => 'cloture_provisoire',
                'date_cloture' => $dateFermeture,
                'cloture_by'   => auth()->id(),
            ]);

            $resultat = $this->calculerResultat($periodeId, $entiteId);

            Log::info('Cloture provisoire terminee', [
                'periode_id' => $periodeId,
                'statut'     => $periode->statut,
                'resultat'   => $resultat,
            ]);

            $typeResultat = $resultat >= 0 ? 'BENEFICE (compte 131)' : 'PERTE (compte 139)';

            return [
                'success'           => true,
                'message'           => "Cloture provisoire effectuee avec succes. " .
                                       "Resultat de l'exercice : " . number_format($resultat, 2) . " $ ({$typeResultat}). " .
                                       "La periode suivante peut maintenant demarrer.",
                'resultat_exercice' => $resultat,
                'type_resultat'     => $typeResultat,
                'statut_periode'    => 'cloture_provisoire',
            ];
        });
    }

    // =========================================================================
    // ACTION 2 : RE-SYNCHRONISATION
    // =========================================================================

    public function reSynchroniserCloture(int $periodeId, ?int $entiteId, string $dateFermeture): array
    {
        $periode = PeriodesComptable::findOrFail($periodeId);

        if ($periode->statut === 'cloture_definitive') {
            throw new \RuntimeException(
                "Impossible de re-synchroniser une periode cloturee definitivement. " .
                "Les ecritures sont verrouillees."
            );
        }

        if ($periode->statut !== 'cloture_provisoire') {
            throw new \RuntimeException(
                "La periode doit etre en cloture provisoire pour pouvoir etre re-synchronisee. " .
                "Statut actuel : " . ($periode->statut ?? 'ouvert') . "."
            );
        }

        // Nettoyer tout ce qui est provisoire
        $this->nettoyerClotureProvisoire($periodeId, $entiteId);

        // Relancer la clôture provisoire
        return $this->effectuerClotureProvisoire($periodeId, $entiteId, $dateFermeture);
    }

    // =========================================================================
    // ACTION 3 : CLÔTURE DÉFINITIVE
    // =========================================================================

    public function effectuerClotureDefinitive(int $periodeId, ?int $entiteId, string $dateFermeture): array
    {
        $periode = PeriodesComptable::findOrFail($periodeId);

        if ($periode->statut === 'cloture_definitive') {
            throw new \RuntimeException(
                "Cette periode est deja cloturee definitivement. " .
                "Aucune action supplementaire n'est possible."
            );
        }

        return DB::transaction(function () use ($periodeId, $entiteId, $dateFermeture, $periode) {

            $entitesIds = $this->getEntitesIds($periodeId, $entiteId);

            // 1. Dernière synchronisation complète
            $resultatProvisoire = $this->effectuerClotureProvisoire($periodeId, $entiteId, $dateFermeture);

            // 2. Passer le Bilan de Fermeture N en DÉFINITIF
            Bilan::where('periode_id', $periodeId)
                ->where('type_bilan', 'fermeture')
                ->where('est_provisoire', true)
                ->when($entiteId, fn($q) => $q->where('entite_id', $entiteId))
                ->update(['est_provisoire' => false]);

            // 3. Passer le Bilan Initial N+1 en DÉFINITIF
            $periodeSuivante = $this->trouverPeriodeSuivante($periodeId);
            if ($periodeSuivante) {
                Bilan::where('periode_id', $periodeSuivante->id)
                    ->where('type_bilan', 'initial')
                    ->where('est_provisoire', true)
                    ->when($entiteId, fn($q) => $q->where('entite_id', $entiteId))
                    ->update(['est_provisoire' => false]);
            }

            // 4. Verrouiller la période N
            $periode->update([
                'statut'       => 'cloture_definitive',
                'is_cloture'   => true,
                'date_cloture' => $dateFermeture,
                'cloture_by'   => auth()->id(),
            ]);

            // 5. Archiver le flux de trésorerie
            $this->archiverFluxTresorerie($periodeId, $entitesIds);

            return [
                'success'           => true,
                'message'           => "Cloture definitive effectuee avec succes. " .
                                       "Le bilan final est verrouille. " .
                                       "Resultat : " . number_format($resultatProvisoire['resultat_exercice'], 2) . " $.",
                'resultat_exercice' => $resultatProvisoire['resultat_exercice'],
                'statut_periode'    => 'cloture_definitive',
            ];
        });
    }

    private function archiverFluxTresorerie(int $periodeId, array $entitesIds): void
    {
        try {
            $fluxController = app(\App\Http\Controllers\FluxTresorerieController::class);

            foreach ($entitesIds as $eId) {
                $request = new \Illuminate\Http\Request([
                    'periode_id' => $periodeId,
                    'entite_id'  => $eId,
                ]);
                $response = $fluxController->calculer($request);
                $fluxData = json_decode($response->getContent(), true)['data'] ?? null;

                if ($fluxData && $fluxData['type'] === 'calcule') {
                    \App\Models\FluxTresorerieArchive::create([
                        'periode_id'          => $periodeId,
                        'entite_id'           => $eId,
                        'succursale_id'       => null,
                        'tresorerie_initiale'  => $fluxData['tresorerie_initiale'] ?? 0,
                        'flux_exploitation'    => $fluxData['flux_exploitation'] ?? 0,
                        'flux_investissement'  => $fluxData['flux_investissement'] ?? 0,
                        'flux_financement'     => $fluxData['flux_financement'] ?? 0,
                        'variation_nette'      => $fluxData['variation_nette'] ?? 0,
                        'tresorerie_finale'    => $fluxData['tresorerie_finale'] ?? 0,
                        'donnees_completes'    => $fluxData,
                        'cloture_date'         => now(),
                        'cloture_par'          => auth()->id(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Erreur archivage flux tresorerie', [
                'periode_id' => $periodeId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // MÉTHODES PRIVÉES
    // =========================================================================

    private function solderChargesEtProduits(int $periodeId, ?int $entiteId, string $dateFermeture): void
    {
        $entitesIds = $this->getEntitesIds($periodeId, $entiteId);

        foreach ($entitesIds as $eId) {
            DB::transaction(function () use ($periodeId, $eId, $dateFermeture) {
                // Supprimer les anciennes écritures de clôture
                EcrituresComptable::where('periode_id', $periodeId)
                    ->where('entite_id', $eId)
                    ->where('reference', 'LIKE', 'CLÔTURE%')
                    ->orWhere('reference', 'LIKE', 'CLOTURE%')
                    ->where('periode_id', $periodeId)
                    ->where('entite_id', $eId)
                    ->delete();

                $charges = $this->getSoldesComptesParClasse('6', $periodeId, $eId);
                $produits = $this->getSoldesComptesParClasse('7', $periodeId, $eId);

                $totalCharges = array_sum(array_column($charges, 'solde'));
                $totalProduits = array_sum(array_column($produits, 'solde'));
                $resultat = round($totalProduits - $totalCharges, 2);
                $estBenefice = $resultat >= 0;
                $compteResultat = $estBenefice ? self::COMPTE_RESULTAT_BENEFICE : self::COMPTE_RESULTAT_PERTE;
                $numeroEcriture = 'FERM-' . $periodeId . '-E' . $eId . '-' . date('YmdHis');

                // Solder les charges
                foreach ($charges as $charge) {
                    $m = $charge['solde'];
                    if ($m <= 0.01) continue;

                    EcrituresComptable::create([
                        'periode_id'      => $periodeId,
                        'date_ecriture'   => $dateFermeture,
                        'compte_numero'   => $compteResultat,
                        'libelle'         => 'Cloture charge - ' . $charge['numero'],
                        'debit'           => $m,
                        'credit'          => 0,
                        'numero_ecriture' => $numeroEcriture,
                        'reference'       => 'CLOTURE CHARGES',
                        'entite_id'       => $eId,
                        'sens_ecriture'   => 'apres',
                        'ordre_affichage' => 1
                    ]);
                    EcrituresComptable::create([
                        'periode_id'      => $periodeId,
                        'date_ecriture'   => $dateFermeture,
                        'compte_numero'   => $charge['numero'],
                        'libelle'         => 'Solde compte de charge',
                        'debit'           => 0,
                        'credit'          => $m,
                        'numero_ecriture' => $numeroEcriture,
                        'reference'       => 'CLOTURE CHARGES',
                        'entite_id'       => $eId,
                        'sens_ecriture'   => 'apres',
                        'ordre_affichage' => 2
                    ]);
                }

                // Solder les produits
                foreach ($produits as $produit) {
                    $m = $produit['solde'];
                    if ($m <= 0.01) continue;

                    EcrituresComptable::create([
                        'periode_id'      => $periodeId,
                        'date_ecriture'   => $dateFermeture,
                        'compte_numero'   => $produit['numero'],
                        'libelle'         => 'Solde compte de produit',
                        'debit'           => $m,
                        'credit'          => 0,
                        'numero_ecriture' => $numeroEcriture,
                        'reference'       => 'CLOTURE PRODUITS',
                        'entite_id'       => $eId,
                        'sens_ecriture'   => 'apres',
                        'ordre_affichage' => 3
                    ]);
                    EcrituresComptable::create([
                        'periode_id'      => $periodeId,
                        'date_ecriture'   => $dateFermeture,
                        'compte_numero'   => $compteResultat,
                        'libelle'         => 'Cloture produit - ' . $produit['numero'],
                        'debit'           => 0,
                        'credit'          => $m,
                        'numero_ecriture' => $numeroEcriture,
                        'reference'       => 'CLOTURE PRODUITS',
                        'entite_id'       => $eId,
                        'sens_ecriture'   => 'apres',
                        'ordre_affichage' => 4
                    ]);
                }
            });
        }
    }

    private function genererBilansFermetureProvisoires(int $periodeId, ?int $entiteId, string $dateFermeture): void
    {
        $entitesIds = $this->getEntitesIds($periodeId, $entiteId);

        foreach ($entitesIds as $eId) {
            DB::transaction(function () use ($periodeId, $eId, $dateFermeture) {
                // Supprimer l'ancien bilan de fermeture PROVISOIRE
                Bilan::where('periode_id', $periodeId)
                    ->where('type_bilan', 'fermeture')
                    ->where('entite_id', $eId)
                    ->where('est_provisoire', true)
                    ->each(function ($bilan) {
                        BilanLigne::where('bilan_id', $bilan->id)->delete();
                        $bilan->delete();
                    });

                $resultatController = app(\App\Http\Controllers\ResultatController::class);
                $request = new \Illuminate\Http\Request([
                    'periode_id' => $periodeId,
                    'entite_id'  => $eId,
                ]);
                $response = $resultatController->calculer($request);
                $resultatData = json_decode($response->getContent(), true);

                if (!isset($resultatData['success']) || !$resultatData['success']) {
                    throw new \RuntimeException(
                        "Impossible de calculer le resultat pour l'entite {$eId}. " .
                        "Verifiez que toutes les ecritures sont correctement saisies."
                    );
                }

                $data = $resultatData['data'];

                \App\Http\Controllers\ResultatController::enregistrerResultat($periodeId, $eId, null, $data);

                $bilanInitial = Bilan::where('periode_id', $periodeId)
                    ->where('type_bilan', 'initial')
                    ->where('entite_id', $eId)
                    ->first();

                $bilanFermeture = Bilan::create([
                    'periode_id'       => $periodeId,
                    'type_bilan'       => 'fermeture',
                    'date_bilan'       => $dateFermeture,
                    'entite_id'        => $eId,
                    'succursale_id'    => $bilanInitial->succursale_id ?? null,
                    'is_actif'         => false,
                    'provient_cloture' => true,
                    'est_provisoire'   => true,
                ]);

                foreach (($data['actif']['detail'] ?? []) as $c) {
                    $montant = abs($c['solde_net']);
                    if ($montant < 0.01) continue;
                    BilanLigne::create([
                        'bilan_id'      => $bilanFermeture->id,
                        'compte_numero' => $c['numero'],
                        'compte_nom'    => $c['nom'],
                        'type_compte'   => 'actif',
                        'montant'       => $montant
                    ]);
                }

                foreach (($data['passif']['detail'] ?? []) as $c) {
                    $montant = abs($c['solde_net']);
                    if ($montant < 0.01) continue;
                    BilanLigne::create([
                        'bilan_id'      => $bilanFermeture->id,
                        'compte_numero' => $c['numero'],
                        'compte_nom'    => $c['nom'],
                        'type_compte'   => 'passif',
                        'montant'       => $montant
                    ]);
                }
            });
        }
    }

    private function genererBilansInitiauxProvisoires(int $periodeId, ?int $entiteId, string $dateFermeture): void
    {
        $periode = PeriodesComptable::find($periodeId);
        if (!$periode) return;

        $periodeSuivante = $this->trouverOuCreerPeriodeSuivante($periode);

        $entitesIds = $entiteId
            ? [$entiteId]
            : Bilan::where('periode_id', $periodeId)
                ->where('type_bilan', 'fermeture')
                ->where('est_provisoire', true)
                ->whereNotNull('entite_id')
                ->pluck('entite_id')
                ->unique()
                ->toArray();

        foreach ($entitesIds as $eId) {
            $this->genererUnBilanInitialProvisoire(
                $periodeId,
                $periodeSuivante->id,
                $eId,
                $periodeSuivante->date_debut->format('Y-m-d')
            );
        }
    }

    private function genererUnBilanInitialProvisoire(
        int $periodeAncienneId,
        int $periodeNouvelleId,
        int $entiteId,
        string $dateOuverture
    ): Bilan {
        return DB::transaction(function () use ($periodeAncienneId, $periodeNouvelleId, $entiteId, $dateOuverture) {
            $bilanFermeture = Bilan::where('periode_id', $periodeAncienneId)
                ->where('type_bilan', 'fermeture')
                ->where('entite_id', $entiteId)
                ->where('est_provisoire', true)
                ->with('bilan_lignes')
                ->first();

            if (!$bilanFermeture) {
                throw new \RuntimeException(
                    "Bilan de fermeture provisoire introuvable pour l'entite {$entiteId}. " .
                    "La cloture provisoire doit etre effectuee avant de generer le bilan initial N+1."
                );
            }

            // Purger l'ancien bilan initial PROVISOIRE
            $existant = Bilan::where('periode_id', $periodeNouvelleId)
                ->where('type_bilan', 'initial')
                ->where('entite_id', $entiteId)
                ->where('est_provisoire', true)
                ->first();

            if ($existant) {
                EcrituresComptable::where('periode_id', $periodeNouvelleId)
                    ->where('reference', 'À-NOUVEAU')
                    ->where('entite_id', $entiteId)
                    ->delete();
                BilanLigne::where('bilan_id', $existant->id)->delete();
                $existant->delete();
            }

            $bilanInitial = Bilan::create([
                'periode_id'       => $periodeNouvelleId,
                'type_bilan'       => 'initial',
                'date_bilan'       => $dateOuverture,
                'entite_id'        => $entiteId,
                'succursale_id'    => $bilanFermeture->succursale_id,
                'is_actif'         => true,
                'provient_cloture' => true,
                'est_provisoire'   => true,
            ]);

            $lignesActif = [];
            $lignesPassif = [];
            $totalActif = 0;
            $totalPassif = 0;

            foreach ($bilanFermeture->bilan_lignes as $ligne) {
                $compteNumero = (string)$ligne->compte_numero;
                $montant = (float)$ligne->montant;
                if ($montant <= 0.01) continue;

                // Transposition : 131→121, 139→129
                if ($compteNumero === '131') {
                    $compteNumero = '121';
                    $nomCompte = 'REPORT A NOUVEAU CREDITEUR';
                    $typeCompte = 'passif';
                } elseif ($compteNumero === '139') {
                    $compteNumero = '129';
                    $nomCompte = 'REPORT A NOUVEAU DEBITEUR';
                    $typeCompte = 'actif';
                } else {
                    $nomCompte = $ligne->compte_nom;
                    $typeCompte = $ligne->type_compte;
                }

                BilanLigne::create([
                    'bilan_id'      => $bilanInitial->id,
                    'compte_numero' => $compteNumero,
                    'compte_nom'    => $nomCompte,
                    'type_compte'   => $typeCompte,
                    'montant'       => $montant
                ]);

                if ($typeCompte === 'actif') {
                    $lignesActif[] = ['compte' => $compteNumero, 'nom' => $nomCompte, 'montant' => $montant];
                    $totalActif += $montant;
                } else {
                    $lignesPassif[] = ['compte' => $compteNumero, 'nom' => $nomCompte, 'montant' => $montant];
                    $totalPassif += $montant;
                }
            }

            // Générer une écriture d'à-nouveau équilibrée
            $numeroEcriture = 'OUV-' . $periodeNouvelleId . '-E' . $entiteId . '-' . date('YmdHis');
            $ordre = 1;

            foreach ($lignesActif as $actif) {
                EcrituresComptable::create([
                    'periode_id'      => $periodeNouvelleId,
                    'date_ecriture'   => $dateOuverture,
                    'compte_numero'   => $actif['compte'],
                    'libelle'         => '[PROVISOIRE] A-nouveau : ' . $actif['nom'],
                    'debit'           => $actif['montant'],
                    'credit'          => 0,
                    'numero_ecriture' => $numeroEcriture,
                    'reference'       => 'À-NOUVEAU',
                    'entite_id'       => $entiteId,
                    'succursale_id'   => $bilanFermeture->succursale_id,
                    'sens_ecriture'   => 'principal',
                    'ordre_affichage' => $ordre++
                ]);
            }

            foreach ($lignesPassif as $passif) {
                EcrituresComptable::create([
                    'periode_id'      => $periodeNouvelleId,
                    'date_ecriture'   => $dateOuverture,
                    'compte_numero'   => $passif['compte'],
                    'libelle'         => '[PROVISOIRE] A-nouveau : ' . $passif['nom'],
                    'debit'           => 0,
                    'credit'          => $passif['montant'],
                    'numero_ecriture' => $numeroEcriture,
                    'reference'       => 'À-NOUVEAU',
                    'entite_id'       => $entiteId,
                    'succursale_id'   => $bilanFermeture->succursale_id,
                    'sens_ecriture'   => 'principal',
                    'ordre_affichage' => $ordre++
                ]);
            }

            // Vérification de l'équilibre
            $ecart = abs($totalActif - $totalPassif);
            if ($ecart > 0.01) {
                if ($totalActif > $totalPassif) {
                    $ecartMontant = round($totalActif - $totalPassif, 2);
                    EcrituresComptable::create([
                        'periode_id'      => $periodeNouvelleId,
                        'date_ecriture'   => $dateOuverture,
                        'compte_numero'   => '471',
                        'libelle'         => '[PROVISOIRE] Ecart a-nouveau',
                        'debit'           => 0,
                        'credit'          => $ecartMontant,
                        'numero_ecriture' => $numeroEcriture,
                        'reference'       => 'À-NOUVEAU',
                        'entite_id'       => $entiteId,
                        'succursale_id'   => $bilanFermeture->succursale_id,
                        'sens_ecriture'   => 'principal',
                        'ordre_affichage' => $ordre++
                    ]);
                } else {
                    $ecartMontant = round($totalPassif - $totalActif, 2);
                    EcrituresComptable::create([
                        'periode_id'      => $periodeNouvelleId,
                        'date_ecriture'   => $dateOuverture,
                        'compte_numero'   => '471',
                        'libelle'         => '[PROVISOIRE] Ecart a-nouveau',
                        'debit'           => $ecartMontant,
                        'credit'          => 0,
                        'numero_ecriture' => $numeroEcriture,
                        'reference'       => 'À-NOUVEAU',
                        'entite_id'       => $entiteId,
                        'succursale_id'   => $bilanFermeture->succursale_id,
                        'sens_ecriture'   => 'principal',
                        'ordre_affichage' => $ordre++
                    ]);
                }
            }

            return $bilanInitial;
        });
    }

    private function nettoyerClotureProvisoire(int $periodeId, ?int $entiteId): void
    {
        $entitesIds = $this->getEntitesIds($periodeId, $entiteId);

        // Supprimer les écritures de clôture sur N
        EcrituresComptable::where('periode_id', $periodeId)
            ->where(function ($q) {
                $q->where('reference', 'LIKE', 'CLÔTURE%')
                  ->orWhere('reference', 'LIKE', 'CLOTURE%');
            })
            ->when($entiteId, fn($q) => $q->whereIn('entite_id', $entitesIds))
            ->delete();

        // Supprimer les bilans de fermeture PROVISOIRES
        foreach ($entitesIds as $eId) {
            Bilan::where('periode_id', $periodeId)
                ->where('type_bilan', 'fermeture')
                ->where('entite_id', $eId)
                ->where('est_provisoire', true)
                ->each(function ($bilan) {
                    BilanLigne::where('bilan_id', $bilan->id)->delete();
                    $bilan->delete();
                });
        }

        // Supprimer les à-nouveaux et bilans initiaux PROVISOIRES sur N+1
        $periodeSuivante = $this->trouverPeriodeSuivante($periodeId);
        if ($periodeSuivante) {
            EcrituresComptable::where('periode_id', $periodeSuivante->id)
                ->where('reference', 'À-NOUVEAU')
                ->when($entiteId, fn($q) => $q->whereIn('entite_id', $entitesIds))
                ->delete();

            foreach ($entitesIds as $eId) {
                Bilan::where('periode_id', $periodeSuivante->id)
                    ->where('type_bilan', 'initial')
                    ->where('entite_id', $eId)
                    ->where('est_provisoire', true)
                    ->each(function ($bilan) {
                        BilanLigne::where('bilan_id', $bilan->id)->delete();
                        $bilan->delete();
                    });
            }
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function getEntitesIds(int $periodeId, ?int $entiteId): array
    {
        if ($entiteId) {
            return [$entiteId];
        }

        return Bilan::where('periode_id', $periodeId)
            ->where('type_bilan', 'initial')
            ->whereNotNull('entite_id')
            ->pluck('entite_id')
            ->unique()
            ->toArray();
    }

    private function trouverOuCreerPeriodeSuivante(PeriodesComptable $periode): PeriodesComptable
    {
        $dateDebutSuivante = Carbon::parse($periode->date_fin)->addDay();
        $codeSuivant = $dateDebutSuivante->year . '-' . str_pad($dateDebutSuivante->month, 2, '0', STR_PAD_LEFT);

        $suivante = PeriodesComptable::where('code', $codeSuivant)->first()
            ?? PeriodesComptable::where('date_debut', '>', $periode->date_fin)
                ->orderBy('date_debut', 'asc')
                ->first();

        if ($suivante) {
            if ($suivante->is_cloture) {
                $suivante->update(['is_cloture' => false, 'statut' => 'ouverte']);
            }
            return $suivante;
        }

        $dateFinSuivante = $dateDebutSuivante->copy()->addMonth()->subDay();
        return PeriodesComptable::create([
            'code'       => $codeSuivant,
            'libelle'    => 'Exercice ' . $dateDebutSuivante->format('F Y'),
            'date_debut' => $dateDebutSuivante,
            'date_fin'   => $dateFinSuivante,
            'statut'     => 'ouverte',
            'is_cloture' => false,
        ]);
    }

    private function trouverPeriodeSuivante(int $periodeId): ?PeriodesComptable
    {
        $periode = PeriodesComptable::find($periodeId);
        if (!$periode) return null;

        return PeriodesComptable::where('date_debut', '>', $periode->date_fin)
            ->orderBy('date_debut', 'asc')
            ->first();
    }

    private function getSoldesComptesParClasse(string $prefixeClasse, int $periodeId, ?int $entiteId): array
    {
        $mouvements = EcrituresComptable::where('compte_numero', 'LIKE', $prefixeClasse . '%')
            ->where('periode_id', $periodeId)
            ->where('reference', 'NOT LIKE', 'CLÔTURE%')
            ->where('reference', 'NOT LIKE', 'CLOTURE%')
            ->when($entiteId, fn($q) => $q->where('entite_id', $entiteId))
            ->select('compte_numero', DB::raw('SUM(debit) as total_debit'), DB::raw('SUM(credit) as total_credit'))
            ->groupBy('compte_numero')
            ->get();

        $resultats = [];
        foreach ($mouvements as $m) {
            $solde = ($prefixeClasse === '6')
                ? ((float)$m->total_debit - (float)$m->total_credit)
                : ((float)$m->total_credit - (float)$m->total_debit);

            if (abs($solde) > 0.01) {
                $plan = PlanComptable::where('numero', $m->compte_numero)->first();
                $resultats[] = [
                    'numero' => $m->compte_numero,
                    'nom'    => $plan->nom ?? 'Compte ' . $m->compte_numero,
                    'solde'  => round($solde, 2)
                ];
            }
        }
        return $resultats;
    }
}