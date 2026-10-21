<?php

namespace App\Services;

use App\Models\ImmobilisationsComptable;
use App\Models\OperationsJournal;
use App\Models\OperationsAuto;
use App\Models\EcrituresComptable;
use App\Models\Entite;
use App\Models\Succursale;
use App\Models\PlanComptable;
use App\Models\PeriodesComptable;
use App\Models\EchecEcriture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class EcritureService
{
    private const TVA_COMPTE_DEBIT = '445';
    private const TVA_COMPTE_CREDIT = '443';
    private const PRECISION = 0.0001;
    private const LIBELLE_FALLBACK = 'Opération automatique';

    private array $comptesCache = [];

    // =========================================================================
    // API PUBLIQUE
    // =========================================================================

    public function creerEcriture(array $data): array
    {
        return $this->traiterEcriture($data, true);
    }

    public function previewEcriture(array $data): array
    {
        return $this->traiterEcriture($data, false);
    }

    public function creerEcritureSimple(array $data): array
    {
        Log::info("=== Création écriture simplifiée ===", $this->sanitizeLogData($data));

        $data = $this->validerDonneesSimplifiees($data);

        if (!empty($data['code']) && empty($data['operation_libelle'])) {
            $data['operation_libelle'] = $data['code'];
        }

        $data['operation_journal_id'] = $this->resoudreOperation($data['operation_libelle']);
        $data['libelle'] = $data['libelle'] ?? OperationsJournal::find($data['operation_journal_id'])->libelle;

        return $this->creerEcriture($data);
    }

    public function annulerEcriture(int $ecritureId, ?string $motif = null, ?string $dateAnnulation = null): array
    {
        try {
            $ecritureOriginale = EcrituresComptable::with(['operationJournal', 'entite', 'succursale'])
                ->findOrFail($ecritureId);

            if ($ecritureOriginale->statut === 'annulee') {
                throw new \RuntimeException("Cette écriture a déjà été annulée.");
            }

            $lignesOriginales = EcrituresComptable::where('numero_ecriture', $ecritureOriginale->numero_ecriture)
                ->where('entite_id', $ecritureOriginale->entite_id)
                ->orderBy('ordre_affichage')
                ->get();

            $dateAnnulation = $dateAnnulation ?? now()->format('Y-m-d');
            $periodeAnnulation = $this->getPeriodeEnCours(['date_ecriture' => $dateAnnulation, 'periode_id' => null]);
            $numeroAnnulation = $this->generateNumeroEcritureAnnulation($ecritureOriginale, $dateAnnulation);

            DB::beginTransaction();
            try {
                $idsAnnulation = [];
                $ordreAnnulation = 0;

                foreach ($lignesOriginales as $ligne) {
                    $ordreAnnulation++;
                    $nouvelleLigne = EcrituresComptable::create([
                        'operation_journal_id' => $ecritureOriginale->operation_journal_id,
                        'entite_id' => $ecritureOriginale->entite_id,
                        'succursale_id' => $ecritureOriginale->succursale_id,
                        'periode_id' => $periodeAnnulation->id,
                        'id_utilisateur' => $this->getUserId(),
                        'date_ecriture' => $dateAnnulation,
                        'compte_numero' => $ligne->compte_numero,
                        'libelle' => $this->buildLibelleAnnulation($ligne->libelle, $motif, $ecritureOriginale->numero_ecriture),
                        'debit' => round($ligne->credit, 4),
                        'credit' => round($ligne->debit, 4),
                        'reference' => $ecritureOriginale->reference . '-ANNUL',
                        'numero_ecriture' => $numeroAnnulation,
                        'sens_ecriture' => 'annulation',
                        'ordre_affichage' => $ordreAnnulation,
                        'auto_operation_id' => $ligne->auto_operation_id,
                        'is_auto' => $ligne->is_auto,
                        'is_tva' => $ligne->is_tva,
                        'taux_tva' => $ligne->taux_tva,
                        'sens_tva' => $ligne->sens_tva,
                        'parent_ecriture_id' => $ecritureId,
                        'statut' => 'annulation',
                        'motif_annulation' => $motif,
                        'date_annulation' => $dateAnnulation,
                    ]);
                    $idsAnnulation[] = $nouvelleLigne->id;
                }

                EcrituresComptable::where('numero_ecriture', $ecritureOriginale->numero_ecriture)
                    ->where('entite_id', $ecritureOriginale->entite_id)
                    ->update(['statut' => 'annulee', 'date_annulation' => $dateAnnulation, 'motif_annulation' => $motif]);

                DB::commit();

                return [
                    'success' => true,
                    'message' => "L'écriture N° {$ecritureOriginale->numero_ecriture} a été annulée avec succès.",
                    'ecriture_originale' => ['id' => $ecritureId, 'numero' => $ecritureOriginale->numero_ecriture, 'statut' => 'annulee'],
                    'ecriture_annulation' => ['ids' => $idsAnnulation, 'numero' => $numeroAnnulation, 'date' => $dateAnnulation],
                    'motif' => $motif
                ];
            } catch (\Exception $e) {
                DB::rollBack();
                throw new \RuntimeException("Erreur technique lors de l'annulation : " . $e->getMessage());
            }
        } catch (\Exception $e) {
            Log::error("Echec annulation écriture", ['ecriture_id' => $ecritureId, 'message' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage(), 'ecriture_id' => $ecritureId];
        }
    }

    public function previewAnnulation(int $ecritureId, ?string $motif = null, ?string $dateAnnulation = null): array
    {
        try {
            $ecritureOriginale = EcrituresComptable::findOrFail($ecritureId);
            $lignesOriginales = EcrituresComptable::where('numero_ecriture', $ecritureOriginale->numero_ecriture)
                ->where('entite_id', $ecritureOriginale->entite_id)
                ->orderBy('ordre_affichage')
                ->get();

            $lignesPreview = [];
            foreach ($lignesOriginales as $ligne) {
                $lignesPreview[] = [
                    'compte_numero' => $ligne->compte_numero,
                    'compte_nom' => $this->getNomCompte($ligne->compte_numero),
                    'libelle_original' => $ligne->libelle,
                    'libelle_annulation' => $this->buildLibelleAnnulation($ligne->libelle, $motif, $ecritureOriginale->numero_ecriture),
                    'debit_original' => round($ligne->debit, 4),
                    'credit_original' => round($ligne->credit, 4),
                    'debit_annulation' => round($ligne->credit, 4),
                    'credit_annulation' => round($ligne->debit, 4),
                ];
            }

            return ['success' => true, 'lignes' => $lignesPreview, 'motif' => $motif];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function annulerEcrituresMultiple(array $ecritureIds, ?string $motif = null, ?string $dateAnnulation = null): array
    {
        $resultats = [];
        $successCount = 0;
        $failCount = 0;

        foreach ($ecritureIds as $id) {
            $resultat = $this->annulerEcriture($id, $motif, $dateAnnulation);
            $resultats[$id] = $resultat;
            if ($resultat['success']) $successCount++; else $failCount++;
        }

        return [
            'success' => $failCount === 0,
            'message' => "{$successCount} écriture(s) annulée(s), {$failCount} échec(s)",
            'total' => count($ecritureIds),
            'succes' => $successCount,
            'echecs' => $failCount,
            'details' => $resultats
        ];
    }

    // =========================================================================
    // LOGIQUE PRINCIPALE
    // =========================================================================

    private function traiterEcriture(array $data, bool $persist): array
    {
        Log::info("=== Début traitement écriture ===", $this->sanitizeLogData($data));

        $this->validerDonnees($data);

        try {
            $periode = $this->getPeriodeEnCours($data);
            $this->validerPeriode($periode, $data['date_ecriture']);
            $this->validerDateDansPeriode($data['date_ecriture'], $periode);

            $contexte = $this->preparerContexte($data, $periode);

            $this->validerConfigurationAmortissement($contexte, $data);

            $traitement = $persist
                ? $this->traiterAvecPersistance($contexte)
                : $this->traiterSansPersistance($contexte);

            return array_merge([
                'success' => true,
                'message' => "L'écriture comptable a été " . ($persist ? "enregistrée" : "prévisualisée") . " avec succès.",
                'periode' => ['id' => $periode->id, 'code' => $periode->code, 'libelle' => $periode->libelle]
            ], $traitement);

        } catch (\Exception $e) {
            Log::error("Erreur traitement écriture", ['message' => $e->getMessage(), 'data' => $this->sanitizeLogData($data)]);

            if ($persist) {
                $this->enregistrerEchecDepuisData($data, $e);
            }

            return ['success' => false, 'message' => $e->getMessage(), 'echec_enregistre' => $persist];
        }
    }

    // =========================================================================
    // PRÉPARATION DU CONTEXTE
    // =========================================================================

    private function preparerContexte(array $data, PeriodesComptable $periode): array
    {
        $operation = OperationsJournal::with([
            'operations_autos' => function ($query) {
                $query->where('is_active', true)->orderBy('created_at', 'asc');
            }
        ])->findOrFail($data['operation_journal_id']);

        $entite = Entite::findOrFail($data['entite_id']);
        $succursale = !empty($data['succursale_id']) ? Succursale::find($data['succursale_id']) : null;

        $montantBase = (float) $data['montant'];
        $tva = $this->calculerTva($data, $operation, $montantBase);
        $numeroEcriture = $this->generateNumeroEcriture($entite, $data['date_ecriture']);

        $toutesLesAutos = $operation->operations_autos ?? collect();
        $autosAvant = $toutesLesAutos->filter(fn($auto) => ($auto->sens_operation ?? 'apres') === 'avant')->values();
        $autosApres = $toutesLesAutos->filter(fn($auto) => ($auto->sens_operation ?? 'apres') !== 'avant')->values();

        return [
            'operation' => $operation,
            'entite' => $entite,
            'succursale' => $succursale,
            'periode' => $periode,
            'autos_avant' => $autosAvant,
            'autos_apres' => $autosApres,
            'overrides' => $data['auto_overrides'] ?? [],
            'numero_ecriture' => $numeroEcriture,
            'montant_base' => $montantBase,
            'tva' => $tva,
            'libelle_principal' => $data['libelle'] ?? $operation->libelle ?? 'Écriture',
            'is_achat_vente' => in_array($operation->type_operation ?? '', ['achat', 'vente']),
            'reference' => $data['reference'] ?? 'REF-' . time(),
            'date_ecriture' => $data['date_ecriture'],
            'user_id' => $this->getUserId(),
            'compte_immobilisation' => $this->detecterCompteImmobilisation($operation),
        ];
    }

    // =========================================================================
    // CALCUL DE LA TVA
    // =========================================================================

    private function calculerTva(array $data, OperationsJournal $operation, float $montantBase): array
    {
        $active = !empty($data['appliquer_tva']) || !empty($operation['has_tva']);
        $taux = (float) ($data['taux_tva'] ?? $operation['taux_tva'] ?? 0);
        $montantEstTTC = !empty($data['montant_est_ttc']) || !empty($operation['montant_est_ttc']);
        $sens = $data['sens_tva'] ?? $operation['sens_tva'] ?? 'debit';

        if (!$active || $taux <= 0) {
            return [
                'active' => false,
                'ht' => round($montantBase, 4),
                'montant_tva' => 0.0,
                'ttc' => round($montantBase, 4),
                'taux' => 0.0,
                'sens' => $sens,
                'compte' => $sens === 'debit' ? self::TVA_COMPTE_DEBIT : self::TVA_COMPTE_CREDIT,
                'montant_est_ttc' => $montantEstTTC,
            ];
        }

        if ($montantEstTTC) {
            $ht = round($montantBase / (1 + ($taux / 100)), 4);
            $montantTva = round($montantBase - $ht, 4);
            $ttc = round($montantBase, 4);
        } else {
            $ht = round($montantBase, 4);
            $montantTva = round($montantBase * ($taux / 100), 4);
            $ttc = round($ht + $montantTva, 4);
        }

        return [
            'active' => true,
            'ht' => $ht,
            'montant_tva' => $montantTva,
            'ttc' => $ttc,
            'taux' => $taux,
            'sens' => $sens,
            'compte' => $sens === 'debit' ? self::TVA_COMPTE_DEBIT : self::TVA_COMPTE_CREDIT,
            'montant_est_ttc' => $montantEstTTC,
        ];
    }

    // =========================================================================
    // 🎯 GÉNÉRATION DES ÉCRITURES
    // =========================================================================

    private function genererEcritures(array $contexte, bool $persist): array
    {
        $operation = $contexte['operation'];
        $autosAvant = $contexte['autos_avant'];
        $autosApres = $contexte['autos_apres'];
        $overrides = $contexte['overrides'];
        $numeroEcriture = $contexte['numero_ecriture'];
        $montantBase = $contexte['montant_base'];
        $tva = $contexte['tva'];
        $libellePrincipal = $contexte['libelle_principal'];
        $isAchatVente = $contexte['is_achat_vente'];

        $lignes = [];
        $ids = [];
        $totalDebit = 0;
        $totalCredit = 0;
        $ordre = 0;

        // Montant de référence pour les autos : HT si TVA active, sinon TTC
        $montantPourAutos = $tva['active'] ? $tva['ht'] : $montantBase;
        if ($montantPourAutos <= 0) {
            $montantPourAutos = $montantBase;
        }

        Log::info('🎯 [genererEcritures] Calcul montants', [
            'persist' => $persist,
            'montant_base' => $montantBase,
            'montant_pour_autos' => $montantPourAutos,
            'tva_active' => $tva['active'],
            'nb_autos_avant' => $autosAvant->count(),
            'nb_autos_apres' => $autosApres->count(),
        ]);

        // =============================
        // BLOC AVANT (DéStockage)
        // =============================
        foreach ($autosAvant as $auto) {
            $montant = $this->calculerMontantAuto(
                $auto,
                $montantPourAutos,
                $overrides,
                $montantPourAutos
            );

            Log::info('🔍 [BLOC AVANT] Auto analysée', [
                'auto_id' => $auto->id,
                'auto_libelle' => $auto->libelle,
                'sens' => $auto->sens_operation,
                'type_calcul' => $auto->type_calcul,
                'montant_calcule' => $montant,
            ]);

            if ($montant === null || $montant <= 0) {
                Log::warning('⚠️ [BLOC AVANT] Auto SKIPPÉE', [
                    'auto_id' => $auto->id,
                    'montant' => $montant,
                ]);
                continue;
            }

            // 🎯 CORRECTION : libellé garanti non vide
            $libelle = $this->getLibelleAuto($auto, $libellePrincipal, $isAchatVente, $overrides);

            $ordre++;
            $lignes[] = $this->buildLigneArray($contexte, 'avant', $ordre, $auto->compte_debit, $libelle, $montant, 0, true, $auto->id);
            $totalDebit += $montant;
            if ($persist) $ids[] = $this->persisterLigne($contexte, 'avant', $ordre, $auto->compte_debit, $libelle, $montant, 0, true, $auto->id);

            $ordre++;
            $lignes[] = $this->buildLigneArray($contexte, 'avant', $ordre, $auto->compte_credit, $libelle, 0, $montant, true, $auto->id);
            $totalCredit += $montant;
            if ($persist) $ids[] = $this->persisterLigne($contexte, 'avant', $ordre, $auto->compte_credit, $libelle, 0, $montant, true, $auto->id);
        }

        // =============================
        // BLOC PRINCIPAL (Vente)
        // =============================
        $debitPrincipal = $tva['sens'] === 'debit' ? $tva['ht'] : $tva['ttc'];
        $creditPrincipal = $tva['sens'] === 'credit' ? $tva['ht'] : $tva['ttc'];

        $ordre++;
        $lignes[] = $this->buildLigneArray($contexte, 'principal', $ordre, $operation->compte_debit, $libellePrincipal, $debitPrincipal, 0);
        $totalDebit += $debitPrincipal;
        if ($persist) $ids[] = $this->persisterLigne($contexte, 'principal', $ordre, $operation->compte_debit, $libellePrincipal, $debitPrincipal, 0);

        // Ligne TVA si active
        if ($tva['active'] && $tva['montant_tva'] > 0) {
            $ordre++;
            $tvaLibelle = "TVA {$tva['taux']}% sur {$libellePrincipal}";
            if ($tva['sens'] === 'debit') {
                $lignes[] = $this->buildLigneArray($contexte, 'principal', $ordre, $tva['compte'], $tvaLibelle, $tva['montant_tva'], 0, false, null, null, true, $tva['taux'], $tva['sens']);
                $totalDebit += $tva['montant_tva'];
                if ($persist) $ids[] = $this->persisterLigne($contexte, 'principal', $ordre, $tva['compte'], $tvaLibelle, $tva['montant_tva'], 0, false, null, true, $tva['taux'], $tva['sens']);
            } else {
                $lignes[] = $this->buildLigneArray($contexte, 'principal', $ordre, $tva['compte'], $tvaLibelle, 0, $tva['montant_tva'], false, null, null, true, $tva['taux'], $tva['sens']);
                $totalCredit += $tva['montant_tva'];
                if ($persist) $ids[] = $this->persisterLigne($contexte, 'principal', $ordre, $tva['compte'], $tvaLibelle, 0, $tva['montant_tva'], false, null, true, $tva['taux'], $tva['sens']);
            }
        }

        $ordre++;
        $lignes[] = $this->buildLigneArray($contexte, 'principal', $ordre, $operation->compte_credit, $libellePrincipal, 0, $creditPrincipal);
        $totalCredit += $creditPrincipal;
        if ($persist) $ids[] = $this->persisterLigne($contexte, 'principal', $ordre, $operation->compte_credit, $libellePrincipal, 0, $creditPrincipal);

        // =============================
        // BLOC APRÈS
        // =============================
        foreach ($autosApres as $auto) {
            $montant = $this->calculerMontantAuto(
                $auto,
                $montantPourAutos,
                $overrides,
                $montantPourAutos
            );

            if ($montant === null || $montant <= 0) continue;

            // 🎯 CORRECTION : libellé garanti non vide
            $libelle = $this->getLibelleAuto($auto, $libellePrincipal, $isAchatVente, $overrides);

            $ordre++;
            $lignes[] = $this->buildLigneArray($contexte, 'apres', $ordre, $auto->compte_debit, $libelle, $montant, 0, true, $auto->id);
            $totalDebit += $montant;
            if ($persist) $ids[] = $this->persisterLigne($contexte, 'apres', $ordre, $auto->compte_debit, $libelle, $montant, 0, true, $auto->id);

            $ordre++;
            $lignes[] = $this->buildLigneArray($contexte, 'apres', $ordre, $auto->compte_credit, $libelle, 0, $montant, true, $auto->id);
            $totalCredit += $montant;
            if ($persist) $ids[] = $this->persisterLigne($contexte, 'apres', $ordre, $auto->compte_credit, $libelle, 0, $montant, true, $auto->id);
        }

        $equilibre = abs($totalDebit - $totalCredit) < self::PRECISION;

        if ($persist && !$equilibre) {
            throw new \RuntimeException("L'écriture n'est pas équilibrée. Débit: " . round($totalDebit, 4) . ", Crédit: " . round($totalCredit, 4));
        }

        return [
            'numero_ecriture' => $numeroEcriture,
            'ecritures_ids' => $ids,
            'lignes' => $lignes,
            'total_debit' => round($totalDebit, 4),
            'total_credit' => round($totalCredit, 4),
            'equilibre' => $equilibre,
            'montant_ht' => round($tva['ht'], 4),
            'montant_tva' => round($tva['montant_tva'], 4),
            'montant_ttc' => round($tva['ttc'], 4),
            'compte_immobilisation' => $contexte['compte_immobilisation'] ?? null,
        ];
    }

    // =========================================================================
    // 🎯 GESTION DES OVERRIDES & LIBELLÉS AUTO — CORRIGÉE
    // =========================================================================

    /**
     * Recherche un override correspondant à une opération automatique.
     * 🎯 CORRECTION : ignore les overrides dont le libellé est vide.
     */
    private function trouverOverride(array $overrides, $auto): ?array
    {
        $autoId = is_object($auto) ? $auto->id : (int)$auto;
        $autoLibelle = is_object($auto) ? ($auto->libelle ?? '') : '';
        $autoCode = is_object($auto) ? ($auto->code_operation ?? '') : '';

        foreach ($overrides as $override) {
            if (!is_array($override)) continue;

            // 🎯 Ignore les overrides vides
            $hasId = !empty($override['id_auto']);
            $hasLibelle = !empty(trim($override['libelle'] ?? ''));
            $hasValeur = isset($override['valeur']) && $override['valeur'] > 0;

            if (!$hasId && !$hasLibelle && !$hasValeur && !isset($override['use_default'])) {
                continue;
            }

            $overrideId = $hasId ? (int)$override['id_auto'] : null;

            if ($overrideId && $overrideId === $autoId) {
                return $override;
            }

            if (!$overrideId && $hasLibelle) {
                $overrideLibelle = strtolower(trim($override['libelle']));
                $autoLibelleClean = strtolower(trim($autoLibelle));
                $autoCodeClean = strtolower(trim($autoCode));

                if ($autoLibelleClean === $overrideLibelle || $autoCodeClean === $overrideLibelle) {
                    return $override;
                }

                if (!empty($autoLibelleClean) && (str_contains($overrideLibelle, $autoLibelleClean) || str_contains($autoLibelleClean, $overrideLibelle))) {
                    return $override;
                }
            }
        }

        return null;
    }

    private function calculerMontantAuto($auto, float $montantBase, array $overrides, float $fallback = 0): ?float
    {
        $override = $this->trouverOverride($overrides, $auto);

        if ($override) {
            if (isset($override['valeur']) && $override['valeur'] > 0) {
                return round((float) $override['valeur'], 4);
            }

            if (isset($override['use_default']) && $override['use_default'] === false) {
                return $this->calculerMontantAutoParDefaut($auto, $montantBase, $fallback);
            }
        }

        return $this->calculerMontantAutoParDefaut($auto, $montantBase, $fallback);
    }

    private function calculerMontantAutoParDefaut($auto, float $montantBase, float $fallback = 0): ?float
    {
        $typeCalcul = $auto->type_calcul ?? 'meme_montant';
        $montant = null;

        switch ($typeCalcul) {
            case 'montant_fixe':
                $montantFixe = (float)($auto->montant_fixe ?? 0);
                $montant = $montantFixe > 0 ? round($montantFixe, 4) : null;
                break;

            case 'pourcentage':
                $pourcentage = (float)($auto->pourcentage ?? 0);
                if ($pourcentage > 0) {
                    $montant = round(($montantBase * $pourcentage) / 100, 4);
                }
                break;

            case 'meme_montant':
            default:
                $montant = round($montantBase, 4);
                break;
        }

        if ($montant === null || $montant <= 0) {
            if ($fallback > 0) {
                Log::info('🎯 [calculerMontantAutoParDefaut] Utilisation du fallback', [
                    'auto_id' => $auto->id ?? null,
                    'type_calcul' => $typeCalcul,
                    'fallback' => $fallback,
                ]);
                return round($fallback, 4);
            }
            return null;
        }

        return $montant;
    }

    /**
     * 🎯 CORRECTION MAJEURE : Obtenir le libellé d'une opération automatique.
     *
     * Ordre de priorité :
     *   1. Override explicite (libellé non vide)
     *   2. Libellé de l'auto en base ($auto->libelle)
     *   3. Transformation achat/vente si applicable
     *   4. Libellé principal de l'écriture
     *   5. Libellé de l'opération journal
     *   6. Fallback générique
     *
     * → Ne retourne JAMAIS une chaîne vide ou null.
     */
    private function getLibelleAuto($auto, string $libellePrincipal, bool $isAchatVente, array $overrides): string
    {
        $override = $this->trouverOverride($overrides, $auto);

        // 1. Override explicite avec libellé non vide
        if ($override && !empty(trim($override['libelle'] ?? ''))) {
            return trim($override['libelle']);
        }

        // 2. Libellé de l'auto en base
        $libelleAuto = trim((string)($auto->libelle ?? ''));

        // 3. Transformation achat/vente si applicable
        if ($isAchatVente && $libelleAuto !== '') {
            $transformed = $this->transformerLibelleAchatVente($libelleAuto, $libellePrincipal);
            if (!empty(trim($transformed))) {
                return $transformed;
            }
        }

        // 4. Libellé de l'auto brut
        if ($libelleAuto !== '') {
            return $libelleAuto;
        }

        // 5. Libellé principal
        if (!empty(trim($libellePrincipal))) {
            return trim($libellePrincipal);
        }

        // 6. Libellé de l'opération journal (si dispo via auto->operation)
        if (isset($auto->operation) && !empty(trim($auto->operation->libelle ?? ''))) {
            return trim($auto->operation->libelle);
        }

        // 7. Fallback générique
        Log::warning('⚠️ [getLibelleAuto] Aucun libellé trouvé, utilisation du fallback générique', [
            'auto_id' => $auto->id ?? null,
        ]);
        return self::LIBELLE_FALLBACK;
    }

    // =========================================================================
    // VALIDATION AMORTISSEMENT (OPTIONNELLE)
    // =========================================================================

    private function validerConfigurationAmortissement(array $contexte, array $data): void
    {
        $compteImmobilisation = $contexte['compte_immobilisation'];

        if (!$compteImmobilisation) {
            return;
        }

        $forcerAmortissement = $data['forcer_amortissement'] ?? false;

        if (!$forcerAmortissement) {
            Log::info("ℹ️ Écriture sur compte d'immobilisation sans configuration d'amortissement", [
                'compte' => $compteImmobilisation,
                'message' => 'L\'amortissement est optionnel, l\'écriture sera enregistrée normalement.'
            ]);
            return;
        }

        if (!$this->validerConfigurationAmortissementObligatoire($contexte, $data)) {
            throw new \RuntimeException("Configuration d'amortissement incomplète pour le compte {$compteImmobilisation}.");
        }
    }

    private function validerConfigurationAmortissementObligatoire(array $contexte, array $data): bool
    {
        $compteImmobilisation = $contexte['compte_immobilisation'];

        $champsRequis = [
            'duree_amortissement' => 'Durée d\'amortissement',
            'date_mise_service' => 'Date de mise en service',
            'valeur_residuelle' => 'Valeur résiduelle',
        ];

        foreach ($champsRequis as $champ => $libelle) {
            if (empty($data[$champ])) {
                Log::warning("⚠️ Champ amortissement manquant: {$libelle}", [
                    'compte' => $compteImmobilisation,
                    'champ' => $champ
                ]);
                return false;
            }
        }

        return true;
    }

    private function detecterCompteImmobilisation(OperationsJournal $operation): ?string
    {
        $comptes = [$operation->compte_debit, $operation->compte_credit];

        foreach ($comptes as $compte) {
            if ($this->isCompteImmobilisation($compte)) {
                return $compte;
            }
        }

        return null;
    }

    private function isCompteImmobilisation(string $compte): bool
    {
        if (empty($compte)) return false;
        if (str_starts_with($compte, '211')) return false;
        if (str_starts_with($compte, '25')) return false;
        if (str_starts_with($compte, '26') || str_starts_with($compte, '27')) return false;
        return str_starts_with($compte, '21') || str_starts_with($compte, '22') || str_starts_with($compte, '23') || str_starts_with($compte, '24');
    }

    // =========================================================================
    // MÉTHODES RESTANTES
    // =========================================================================

    private function getPeriodeEnCours(array $data): PeriodesComptable
    {
        $dateEcriture = $data['date_ecriture'] ?? now()->format('Y-m-d');
        $date = Carbon::parse($dateEcriture);

        if (!empty($data['periode_id'])) {
            $periode = PeriodesComptable::find($data['periode_id']);
            if (!$periode) throw new \RuntimeException("Période introuvable.");
            if ($periode->statut === 'cloture_definitive') throw new \RuntimeException("Période clôturée.");
            return $periode;
        }

        $periode = PeriodesComptable::where('date_debut', '<=', $date)
            ->where('date_fin', '>=', $date)
            ->where('statut', '!=', 'cloture_definitive')
            ->orderBy('date_debut', 'asc')
            ->first();

        if (!$periode) throw new \RuntimeException("Aucune période ouverte trouvée.");
        return $periode;
    }

    private function validerPeriode(PeriodesComptable $periode, string $dateEcriture): void
    {
        if ($periode->statut === 'cloture_definitive') {
            throw new \RuntimeException("La période comptable '{$periode->libelle}' est clôturée définitivement.");
        }
    }

    private function validerDateDansPeriode(string $dateEcriture, PeriodesComptable $periode): void
    {
        $date = Carbon::parse($dateEcriture);
        $dateDebut = Carbon::parse($periode->date_debut);
        $dateFin = Carbon::parse($periode->date_fin);
        if ($date->lt($dateDebut) || $date->gt($dateFin)) {
            throw new \RuntimeException("La date d'écriture n'est pas dans la période.");
        }
    }

    private function traiterAvecPersistance(array $contexte): array
    {
        DB::beginTransaction();
        try {
            $resultat = $this->genererEcritures($contexte, true);
            DB::commit();
            return $resultat;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \RuntimeException("Erreur lors de l'enregistrement : " . $e->getMessage());
        }
    }

    private function traiterSansPersistance(array $contexte): array
    {
        return $this->genererEcritures($contexte, false);
    }

    private function getUserId(): int
    {
        if (auth()->check()) return auth()->id();
        $posSession = session()->get('pos_user_id');
        if ($posSession) return (int) $posSession;
        if (isset($_SESSION['pos_user_id'])) return (int) $_SESSION['pos_user_id'];
        if (isset($_COOKIE['pos_session'])) {
            $cookieData = json_decode($_COOKIE['pos_session'], true);
            if (isset($cookieData['user_id'])) return (int) $cookieData['user_id'];
        }
        throw new \RuntimeException("Aucun utilisateur connecté détecté.");
    }

    private function validerDonnees(array $data): void
    {
        $champsRequis = [
            'entite_id' => 'Entité',
            'operation_journal_id' => 'Opération journal',
            'montant' => 'Montant',
            'date_ecriture' => 'Date d\'écriture'
        ];

        foreach ($champsRequis as $champ => $libelle) {
            if (empty($data[$champ])) {
                throw new \InvalidArgumentException("Champs obligatoires manquants : {$libelle}");
            }
        }

        if (!is_numeric($data['montant']) || $data['montant'] <= 0) {
            throw new \InvalidArgumentException("Le montant doit être un nombre positif.");
        }
    }

    private function validerDonneesSimplifiees(array $data): array
    {
        if (empty($data['operation_libelle']) && empty($data['code'])) {
            throw new \InvalidArgumentException("Le libellé de l'opération est obligatoire.");
        }
        return $data;
    }

    private function resoudreOperation(string $libelle): int
    {
        $op = OperationsJournal::where('libelle', $libelle)
            ->orWhere('code_operation', $libelle)
            ->first();

        if (!$op) throw new \RuntimeException("Impossible de trouver l'opération journal '{$libelle}'.");
        return $op->id;
    }

    private function transformerLibelleAchatVente(string $libelleAuto, string $libellePrincipal): string
    {
        $libelleAuto = trim($libelleAuto);
        $libellePrincipal = trim($libellePrincipal);

        if ($libelleAuto === '') {
            return $libellePrincipal;
        }

        if ($libellePrincipal === '') {
            return $libelleAuto;
        }

        return "{$libelleAuto} - {$libellePrincipal}";
    }

    /**
     * 🎯 CORRECTION : Garantie d'un libellé non vide.
     */
    private function buildLigneArray(
        array $contexte,
        string $bloc,
        int $ordre,
        string $compte,
        string $libelle,
        float $debit,
        float $credit,
        bool $isAuto = false,
        ?int $autoId = null,
        ?string $calculInfo = null,
        bool $isTva = false,
        float $tauxTva = 0,
        ?string $sensTva = null
    ): array {
        // 🎯 Sécurité : libellé non vide
        $libelle = trim($libelle);
        if ($libelle === '') {
            $libelle = $contexte['libelle_principal'] ?? self::LIBELLE_FALLBACK;
        }

        return [
            'ordre' => $ordre,
            'bloc' => $bloc,
            'compte_numero' => $compte,
            'compte_nom' => $this->getNomCompte($compte),
            'libelle' => $libelle,
            'debit' => round($debit, 4),
            'credit' => round($credit, 4),
            'is_auto' => $isAuto,
            'auto_operation_id' => $autoId,
            'calcul_info' => $calculInfo,
            'is_tva' => $isTva,
            'taux_tva' => $tauxTva,
            'sens_tva' => $sensTva
        ];
    }

    /**
     * 🎯 CORRECTION : Garantie d'un libellé non vide lors de la persistance.
     */
    private function persisterLigne(
        array $contexte,
        string $bloc,
        int $ordre,
        string $compte,
        string $libelle,
        float $debit,
        float $credit,
        bool $isAuto = false,
        ?int $autoId = null,
        bool $isTva = false,
        float $tauxTva = 0,
        ?string $sensTva = null
    ): int {
        // 🎯 Sécurité : libellé non vide
        $libelle = trim($libelle);
        if ($libelle === '') {
            $libelle = $contexte['libelle_principal'] ?? self::LIBELLE_FALLBACK;
        }

        $ecriture = EcrituresComptable::create([
            'operation_journal_id' => $contexte['operation']->id,
            'entite_id' => $contexte['entite']->id,
            'succursale_id' => $contexte['succursale']?->id,
            'periode_id' => $contexte['periode']->id,
            'id_utilisateur' => $contexte['user_id'],
            'date_ecriture' => $contexte['date_ecriture'],
            'compte_numero' => $compte,
            'libelle' => $libelle,
            'debit' => round($debit, 4),
            'credit' => round($credit, 4),
            'reference' => $contexte['reference'],
            'numero_ecriture' => $contexte['numero_ecriture'],
            'sens_ecriture' => $bloc,
            'ordre_affichage' => $ordre,
            'auto_operation_id' => $autoId,
            'is_auto' => $isAuto,
            'is_tva' => $isTva,
            'taux_tva' => $tauxTva,
            'sens_tva' => $sensTva,
            'statut' => 'validee',
        ]);

        return $ecriture->id;
    }

    private function getNomCompte(string $numero): string
    {
        if (isset($this->comptesCache[$numero])) {
            return $this->comptesCache[$numero];
        }

        $compte = PlanComptable::where('numero', $numero)->first();
        $nom = $compte ? $compte->nom : "Compte {$numero}";
        $this->comptesCache[$numero] = $nom;

        return $nom;
    }

    private function generateNumeroEcriture(Entite $entite, string $dateEcriture): string
    {
        $date = Carbon::parse($dateEcriture);
        $prefixe = "EC-" . $entite->code . "-" . $date->format('Ym') . "-";
        $count = EcrituresComptable::where('numero_ecriture', 'LIKE', "{$prefixe}%")
            ->distinct('numero_ecriture')
            ->count('numero_ecriture');

        return $prefixe . str_pad($count + 1, 5, '0', STR_PAD_LEFT);
    }

    private function generateNumeroEcritureAnnulation(EcrituresComptable $ecritureOriginale, string $dateAnnulation): string
    {
        return "ANN-" . $ecritureOriginale->numero_ecriture;
    }

    private function buildLibelleAnnulation(string $libelleOriginal, ?string $motif, string $numeroOriginal): string
    {
        $libelleOriginal = trim($libelleOriginal);
        if ($libelleOriginal === '') {
            $libelleOriginal = self::LIBELLE_FALLBACK;
        }

        $base = "Annulation écriture {$numeroOriginal} - {$libelleOriginal}";
        return $motif ? "{$base} (Motif: {$motif})" : $base;
    }

    private function sanitizeLogData(array $data): array
    {
        unset($data['password'], $data['_token']);
        return $data;
    }

    private function enregistrerEchecDepuisData(array $data, \Exception $e): void
    {
        try {
            EchecEcriture::create([
                'entite_id' => $data['entite_id'] ?? null,
                'succursale_id' => $data['succursale_id'] ?? null,
                'periode_id' => $data['periode_id'] ?? null,
                'operation_journal_id' => $data['operation_journal_id'] ?? null,
                'utilisateur_id' => $this->getUserId(),
                'montant' => round($data['montant'] ?? 0, 4),
                'libelle' => $data['libelle'] ?? 'Non spécifié',
                'reference' => $data['reference'] ?? 'Non spécifié',
                'date_ecriture' => $data['date_ecriture'] ?? now()->format('Y-m-d'),
                'raison_echec' => $e->getMessage(),
                'details' => json_encode([
                    'type_erreur' => get_class($e),
                    'fichier' => $e->getFile(),
                    'ligne' => $e->getLine()
                ], JSON_UNESCAPED_UNICODE),
                'statut' => 'echec',
            ]);
        } catch (\Exception $ex) {
            Log::error("Impossible d'enregistrer l'échec", ['erreur' => $ex->getMessage()]);
        }
    }
}