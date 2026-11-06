<?php

namespace App\Services;

use App\Models\ImmobilisationsComptable;
use App\Models\EcrituresComptable;
use App\Models\PeriodesComptable;
use App\Models\PlanComptable;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AmortissementService
{
    /**
     * ═══════════════════════════════════════════════════════════════════
     *  POINT D'ENTRÉE : exécute l'amortissement avec RATTRAPAGE AUTOMATIQUE
     * ═══════════════════════════════════════════════════════════════════
     *
     *   On retourne :
     *     - "details"  : les immos TRAITÉES (mois créés) avec libellé
     *     - "ignorees" : les VRAIES erreurs avec libellé
     *     - "resume"   : compteurs (traitees, ignorees, skipped, mois_total)
     *
     *  ❌ Les immos déjà à jour ou déjà écrites ne sont PAS listées.
     */
    public function executerAmortissementMensuel(?int $entiteId = null): array
    {
        // ─────────────────────────────────────────────────────────────────
        // 1. VÉRIFICATION PÉRIODE OUVERTE (pour aujourd'hui)
        // ─────────────────────────────────────────────────────────────────
        $periode = PeriodesComptable::where('statut', 'ouverte')
            ->where('date_debut', '<=', now())
            ->where('date_fin', '>=', now())
            ->first();

        if (!$periode) {
            throw new \RuntimeException(
                "❌ Aucune période comptable n'est ouverte pour le " . now()->format('d/m/Y') . ".\n\n" .
                "📋 Actions à faire :\n" .
                "1. Allez dans Configuration → Périodes comptables\n" .
                "2. Ouvrez une période qui couvre la date du jour\n" .
                "3. Relancez l'amortissement"
            );
        }

        $moisActuel      = now()->format('Y-m');
        $debutMoisActuel = now()->startOfMonth()->toDateString();

        // ─────────────────────────────────────────────────────────────────
        // 2. RÉCUPÉRATION DES IMMOS CANDIDATES
        // ─────────────────────────────────────────────────────────────────
        $immobilisations = ImmobilisationsComptable::actives()
            ->enCours()
            ->when($entiteId, fn($q) => $q->parEntite($entiteId))
            ->where(function ($q) {
                $q->whereNotNull('date_debut_utilisation')
                  ->orWhereNotNull('date_debut_amortissement')
                  ->orWhereNotNull('date_acquisition');
            })
            ->where(function ($q) use ($debutMoisActuel) {
                $q->whereNull('dernier_mois_amorti')
                  ->orWhere('dernier_mois_amorti', '')
                  ->orWhere('dernier_mois_amorti', 'LIKE', '0000-%')
                  ->orWhere('dernier_mois_amorti', '<', $debutMoisActuel);
            })
            ->get();

        if ($immobilisations->isEmpty()) {
            return [
                'success'     => true,
                'message'     => 'Aucune immobilisation à amortir pour cette période.',
                'mois_actuel' => $moisActuel,
                'details'     => [],
                'ignorees'    => [],
                'resume'      => [
                    'traitees'   => 0,
                    'ignorees'   => 0,
                    'skipped'    => 0,
                    'mois_total' => 0,
                ],
            ];
        }

        // ─────────────────────────────────────────────────────────────────
        // 3. TRAITEMENT
        // ─────────────────────────────────────────────────────────────────
        $resultats = [];
        $echecs    = [];
        $skipped   = 0;

        foreach ($immobilisations as $immo) {
            try {
                $resultat = $this->amortirAvecRattrapage($immo, $periode, $moisActuel);

                if ($resultat) {
                    $resultats[] = $resultat;
                } else {
                    // 🔥 Immo déjà à jour / déjà écrite → silencieuse
                    $skipped++;
                }
            } catch (\RuntimeException $e) {
                $echecs[] = [
                    'code'    => $immo->code,
                    'libelle' => $immo->libelle,
                    'raison'  => $e->getMessage(),
                ];
            } catch (\Throwable $e) {
                Log::error('AMORT — erreur technique sur immo', [
                    'immo'    => $immo->code,
                    'libelle' => $immo->libelle,
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                ]);

                $echecs[] = [
                    'code'    => $immo->code,
                    'libelle' => $immo->libelle,
                    'raison'  => "Erreur technique : " . $e->getMessage(),
                ];
            }
        }

        // ─────────────────────────────────────────────────────────────────
        // 4. RÉSUMÉ (mois totaux sur toutes les immos traitées)
        // ─────────────────────────────────────────────────────────────────
        $moisTotal = array_sum(array_column($resultats, 'nb_mois'));

        $message = count($resultats) . ' immobilisation(s) traitée(s)';
        if ($moisTotal > 0) {
            $message .= ' — ' . $moisTotal . ' mois d\'amortissement enregistrés';
        }
        if (count($echecs) > 0) {
            $message .= ', ' . count($echecs) . ' en erreur';
        }

        return [
            'success'     => true,
            'message'     => $message,
            'mois_actuel' => $moisActuel,
            'details'     => $resultats,
            'ignorees'    => $echecs,
            'resume'      => [
                'traitees'   => count($resultats),
                'ignorees'   => count($echecs),
                'skipped'    => $skipped,
                'mois_total' => $moisTotal,
            ],
        ];
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     *  AMORTISSEMENT AVEC RATTRAPAGE
     * ═══════════════════════════════════════════════════════════════════
     */
    private function amortirAvecRattrapage(
        ImmobilisationsComptable $immo,
        PeriodesComptable $periode,
        string $moisActuel
    ): ?array {

        // ─── ÉTAPE A : Date source (validation stricte) ───
        $dateSource = $this->dateValide($immo->date_debut_utilisation)
            ?? $this->dateValide($immo->date_debut_amortissement)
            ?? $this->dateValide($immo->date_acquisition)
            ?? null;

        if (!$dateSource) {
            throw new \RuntimeException(
                "❌ L'immobilisation '{$immo->libelle}' n'a AUCUNE date valide.\n\n" .
                "Valeurs trouvées en base :\n" .
                "• date_debut_utilisation   : " . var_export($immo->date_debut_utilisation, true) . "\n" .
                "• date_debut_amortissement : " . var_export($immo->date_debut_amortissement, true) . "\n" .
                "• date_acquisition         : " . var_export($immo->date_acquisition, true) . "\n\n" .
                "📋 Actions à faire :\n" .
                "1. Allez dans Immobilisations → modifier cette immobilisation\n" .
                "2. Renseignez une date d'utilisation ou d'acquisition valide\n" .
                "3. Relancez l'amortissement\n\n" .
                "⚠️ Les dates '0000-00-00' ne sont pas acceptées."
            );
        }

        // ─── ÉTAPE B : Bornes ───
        $dateDebut = Carbon::parse($dateSource)->startOfMonth();
        $dateFin   = Carbon::parse($moisActuel . '-01')->startOfMonth();

        // ─── ÉTAPE C : Reprise après dernier_mois_amorti ───
        $dernierMoisValide = $this->moisValide($immo->dernier_mois_amorti);
        if ($dernierMoisValide) {
            $dateDebut = Carbon::parse($dernierMoisValide . '-01')
                ->addMonth()
                ->startOfMonth();
        }

        // ─── ÉTAPE D : Déjà à jour → null ───
        if ($dateDebut->greaterThan($dateFin)) {
            return null;
        }

        // ─── ÉTAPE E : Nombre de mois ───
        $moisARattraper = (int) $dateDebut->diffInMonths($dateFin) + 1;

        // ─── ÉTAPE F : mois_restants ───
        $moisRestants = max(0, (int) ($immo->mois_restants ?? 0));

        if ($moisRestants <= 0) {
            return null;
        }

        $moisARattraper = min($moisARattraper, $moisRestants);

        if ($moisARattraper <= 0) {
            return null;
        }

        // ─── ÉTAPE G : Montant mensuel ───
        $montantMensuel = (float) $immo->montant_mensuel;

        if ($montantMensuel <= 0 && (int) $immo->duree_mois > 0) {
            $amortissable   = (float) $immo->valeur_acquisition - (float) $immo->valeur_residuelle;
            $montantMensuel = round(max(0, $amortissable) / (int) $immo->duree_mois, 4);
        }

        if ($montantMensuel <= 0) {
            throw new \RuntimeException(
                "❌ Le montant mensuel d'amortissement de '{$immo->libelle}' est nul.\n\n" .
                "Valeur d'acquisition : {$immo->valeur_acquisition} $\n" .
                "Valeur résiduelle    : {$immo->valeur_residuelle} $\n" .
                "Durée de vie         : {$immo->duree_mois} mois\n\n" .
                "📋 Vérifiez ces valeurs dans la fiche de l'immobilisation."
            );
        }

        // ─── ÉTAPE H : Transaction ───
        $montantTotal = 0;
        $moisTraites  = [];
        $moisIgnores  = [];

        return DB::transaction(function () use (
            $immo, $periode, $dateDebut, $moisARattraper, $montantMensuel,
            &$montantTotal, &$moisTraites, &$moisIgnores
        ) {
            $curseurDate = $dateDebut->copy();

            for ($i = 0; $i < $moisARattraper; $i++) {
                $moisCourant = $curseurDate->format('Y-m');

                // Sécurité an 0
                if (str_starts_with($moisCourant, '0000')
                    || (int) $curseurDate->year < 1900) {
                    throw new \RuntimeException(
                        "❌ Bug détecté : curseur de date à l'an 0 ou antérieur ({$moisCourant}) " .
                        "pour '{$immo->libelle}'.\n\n" .
                        "📋 Corrigez les dates en base (retirez les '0000-*')."
                    );
                }

                // Anti-doublon
                $numeroBase = 'AMORT-' . $immo->id . '-' . $moisCourant;
                $dejaFait   = EcrituresComptable::where('numero_ecriture', 'LIKE', $numeroBase . '%')->exists();

                if ($dejaFait) {
                    $moisIgnores[] = $moisCourant;
                    $curseurDate->addMonth();
                    continue;
                }

                // Calcul du montant (ajustement dernier mois)
                $resteAAmortir = round(
                    $immo->valeur_acquisition - $immo->valeur_residuelle
                    - $immo->montant_amorti_cumule - $montantTotal,
                    2
                );

                $estDernierMois = ($immo->mois_restants - count($moisTraites)) <= 1;

                $montantCeMois = $estDernierMois
                    ? max(0, $resteAAmortir)
                    : $montantMensuel;

                if ($montantCeMois <= 0) {
                    $curseurDate->addMonth();
                    continue;
                }

                $this->creerEcrituresMois($immo, $moisCourant, $montantCeMois);

                $montantTotal += $montantCeMois;
                $moisTraites[] = $moisCourant;

                $curseurDate->addMonth();
            }

            // ─── Mise à jour immobilisation ───
            if (count($moisTraites) > 0) {
                $nouveauCumul = round($immo->montant_amorti_cumule + $montantTotal, 2);
                $nouvelleVNC  = round($immo->valeur_acquisition - $nouveauCumul, 2);
                $moisRestants = max(0, (int) $immo->mois_restants - count($moisTraites));
                $estAmorti    = $moisRestants <= 0 || $nouvelleVNC <= $immo->valeur_residuelle;

                $dernierMois = end($moisTraites);

                $immo->update([
                    'montant_amorti_cumule'  => $nouveauCumul,
                    'valeur_nette_comptable' => max($immo->valeur_residuelle, $nouvelleVNC),
                    'dernier_mois_amorti'    => $dernierMois,
                    'mois_restants'          => $moisRestants,
                    'est_amorti'             => $estAmorti,
                ]);

                if ($estAmorti) {
                    $this->solderImmobilisation($immo, $periode);
                }

                return [
                    'immo_id'       => $immo->id,
                    'code'          => $immo->code,
                    'libelle'       => $immo->libelle,
                    'mois_traites'  => $moisTraites,
                    'mois_ignores'  => $moisIgnores,
                    'nb_mois'       => count($moisTraites),
                    'montant_total' => $montantTotal,
                    'vnc'           => $nouvelleVNC,
                    'mois_restants' => $moisRestants,
                    'est_amorti'    => $estAmorti,
                ];
            }

            return null;
        });
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     *  🔥 CRÉATION DES ÉCRITURES COMPTABLES
     * ═══════════════════════════════════════════════════════════════════
     */
    private function creerEcrituresMois(
        ImmobilisationsComptable $immo,
        string $mois,
        float $montant
    ): void {

        // 🔥 ÉTAPE 1 : Période du mois
        $dateEcriture = Carbon::parse($mois . '-01')->endOfMonth();

        $periodeDuMois = PeriodesComptable::where('date_debut', '<=', $dateEcriture)
            ->where('date_fin', '>=', $dateEcriture)
            ->first();

        if (!$periodeDuMois) {
            throw new \RuntimeException(
                "❌ Aucune période comptable ne couvre le mois {$mois}.\n\n" .
                "Immobilisation : {$immo->libelle}\n" .
                "Date de l'écriture prévue : {$dateEcriture->format('d/m/Y')}\n\n" .
                "📋 Créez une période couvrant le mois {$mois}."
            );
        }

        if ($periodeDuMois->statut !== 'ouverte') {
            throw new \RuntimeException(
                "❌ La période du mois {$mois} est FERMÉE (statut : {$periodeDuMois->statut}).\n\n" .
                "Immobilisation : {$immo->libelle}\n" .
                "Période : du {$periodeDuMois->date_debut} au {$periodeDuMois->date_fin}\n\n" .
                "📋 Rouvrez la période du mois {$mois}."
            );
        }

        $periode = $periodeDuMois;

        // 🔥 ÉTAPE 2 : Validation des comptes
        $comptesAVerifier = [
            ['numero' => $immo->compte_dotation,      'libelle' => "Compte de dotation (classe 68)"],
            ['numero' => $immo->compte_amortissement, 'libelle' => "Compte d'amortissement (classe 28)"],
        ];

        $erreurs = [];

        foreach ($comptesAVerifier as $compte) {
            if (empty($compte['numero'])) {
                $erreurs[] = "• {$compte['libelle']} : non renseigné";
                continue;
            }
            if (!PlanComptable::where('numero', $compte['numero'])->exists()) {
                $erreurs[] = "• {$compte['libelle']} : le compte '{$compte['numero']}' n'existe pas dans le plan comptable";
            }
        }

        if (!empty($immo->compte_acquisition)) {
            if (!PlanComptable::where('numero', $immo->compte_acquisition)->exists()) {
                $erreurs[] = "• Compte d'acquisition (classe 2) : le compte '{$immo->compte_acquisition}' n'existe pas dans le plan comptable";
            }
        }

        if (!empty($erreurs)) {
            throw new \RuntimeException(
                "❌ Impossible de créer l'amortissement pour '{$immo->libelle}'.\n\n" .
                "Les comptes comptables suivants sont invalides :\n" .
                implode("\n", $erreurs) . "\n\n" .
                "📋 Ajoutez les comptes manquants dans Configuration → Plan comptable."
            );
        }

        // ═══════════════════════════════════════════════════════════════════
        // ÉCRITURE 1/2 — DÉBIT dotation
        // ═══════════════════════════════════════════════════════════════════
        $numeroEcriture = 'AMORT-' . $immo->id . '-' . $mois . '-' . date('YmdHis');

        try {
            EcrituresComptable::create([
                'operation_journal_id' => null,
                'periode_id'           => $periode->id,
                'date_ecriture'        => $dateEcriture->format('Y-m-d'),
                'compte_numero'        => $immo->compte_dotation,
                'libelle'              => 'Dotation amortissement - ' . $immo->libelle . ' - ' . $mois,
                'debit'                => $montant,
                'credit'               => 0,
                'numero_ecriture'      => $numeroEcriture,
                'reference'            => 'AMORTISSEMENT ' . $mois,
                'entite_id'            => $immo->entite_id,
                'succursale_id'        => $immo->succursale_id,
                'sens_ecriture'        => 'principal',
                'ordre_affichage'      => 1,
            ]);
        } catch (\Throwable $e) {
            Log::error('AMORT — échec écriture DÉBIT', [
                'immo' => $immo->code, 'libelle' => $immo->libelle, 'mois' => $mois,
                'compte' => $immo->compte_dotation, 'message' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                "❌ Erreur lors de la création de l'écriture de DÉBIT.\n\n" .
                "Immobilisation : {$immo->libelle}\n" .
                "Mois : {$mois}\n" .
                "Compte : {$immo->compte_dotation}\n\n" .
                "Détail : " . $this->traduireErreurSQL($e)
            );
        }

        // ═══════════════════════════════════════════════════════════════════
        // ÉCRITURE 2/2 — CRÉDIT amortissement cumulé
        // ═══════════════════════════════════════════════════════════════════
        try {
            EcrituresComptable::create([
                'operation_journal_id' => null,
                'periode_id'           => $periode->id,
                'date_ecriture'        => $dateEcriture->format('Y-m-d'),
                'compte_numero'        => $immo->compte_amortissement,
                'libelle'              => 'Amortissement cumulé - ' . $immo->libelle . ' - ' . $mois,
                'debit'                => 0,
                'credit'               => $montant,
                'numero_ecriture'      => $numeroEcriture,
                'reference'            => 'AMORTISSEMENT ' . $mois,
                'entite_id'            => $immo->entite_id,
                'succursale_id'        => $immo->succursale_id,
                'sens_ecriture'        => 'principal',
                'ordre_affichage'      => 2,
            ]);
        } catch (\Throwable $e) {
            Log::error('AMORT — échec écriture CRÉDIT', [
                'immo' => $immo->code, 'libelle' => $immo->libelle, 'mois' => $mois,
                'compte' => $immo->compte_amortissement, 'message' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                "❌ Erreur lors de la création de l'écriture de CRÉDIT.\n\n" .
                "Immobilisation : {$immo->libelle}\n" .
                "Mois : {$mois}\n" .
                "Compte : {$immo->compte_amortissement}\n\n" .
                "Détail : " . $this->traduireErreurSQL($e)
            );
        }
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     *  SORTIE D'ACTIF
     * ═══════════════════════════════════════════════════════════════════
     */
    private function solderImmobilisation(
        ImmobilisationsComptable $immo,
        PeriodesComptable $periode
    ): void {
        $montant = round($immo->valeur_acquisition - $immo->valeur_residuelle, 2);

        if ($montant <= 0) {
            return;
        }

        $numeroEcriture = 'SORTIE-IMMO-' . $immo->id;

        if (EcrituresComptable::where('numero_ecriture', $numeroEcriture)->exists()) {
            return;
        }

        $comptes = [
            'Compte d\'amortissement (28)' => $immo->compte_amortissement,
            'Compte d\'acquisition (2)'    => $immo->compte_acquisition,
        ];

        $erreurs = [];

        foreach ($comptes as $libelle => $numero) {
            if (empty($numero)) {
                $erreurs[] = "• {$libelle} : non renseigné";
                continue;
            }
            if (!PlanComptable::where('numero', $numero)->exists()) {
                $erreurs[] = "• {$libelle} : le compte '{$numero}' n'existe pas dans le plan comptable";
            }
        }

        if (!empty($erreurs)) {
            throw new \RuntimeException(
                "❌ Impossible de solder l'actif '{$immo->libelle}'.\n\n" .
                "Comptes invalides :\n" . implode("\n", $erreurs)
            );
        }

        $dateSortie  = now();
        $periodeSortie = PeriodesComptable::where('date_debut', '<=', $dateSortie)
            ->where('date_fin', '>=', $dateSortie)
            ->where('statut', 'ouverte')
            ->first();

        if (!$periodeSortie) {
            throw new \RuntimeException(
                "❌ Impossible de solder l'actif '{$immo->libelle}' : aucune période ouverte ne couvre le " .
                $dateSortie->format('d/m/Y') . "."
            );
        }

        try {
            EcrituresComptable::create([
                'operation_journal_id' => null,
                'periode_id'           => $periodeSortie->id,
                'date_ecriture'        => $dateSortie->format('Y-m-d'),
                'compte_numero'        => $immo->compte_amortissement,
                'libelle'              => 'Sortie actif amorti - ' . $immo->libelle,
                'debit'                => $montant,
                'credit'               => 0,
                'numero_ecriture'      => $numeroEcriture,
                'reference'            => 'SORTIE ACTIF',
                'entite_id'            => $immo->entite_id,
                'succursale_id'        => $immo->succursale_id,
                'sens_ecriture'        => 'principal',
                'ordre_affichage'      => 1,
            ]);

            EcrituresComptable::create([
                'operation_journal_id' => null,
                'periode_id'           => $periodeSortie->id,
                'date_ecriture'        => $dateSortie->format('Y-m-d'),
                'compte_numero'        => $immo->compte_acquisition,
                'libelle'              => 'Sortie actif - ' . $immo->libelle,
                'debit'                => 0,
                'credit'               => $montant,
                'numero_ecriture'      => $numeroEcriture,
                'reference'            => 'SORTIE ACTIF',
                'entite_id'            => $immo->entite_id,
                'succursale_id'        => $immo->succursale_id,
                'sens_ecriture'        => 'principal',
                'ordre_affichage'      => 2,
            ]);
        } catch (\Throwable $e) {
            Log::error('AMORT — échec sortie actif', [
                'immo' => $immo->code, 'libelle' => $immo->libelle,
                'message' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                "❌ Erreur lors de la sortie d'actif '{$immo->libelle}'.\n\n" .
                "Détail : " . $this->traduireErreurSQL($e)
            );
        }
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     *  🔥 VALIDATION D'UNE DATE VENANT DE LA BDD
     * ═══════════════════════════════════════════════════════════════════
     */
    private function dateValide($valeur): ?string
    {
        if (empty($valeur)) {
            return null;
        }

        $str = trim((string) $valeur);

        if (str_starts_with($str, '0000')) {
            return null;
        }

        try {
            $carbon = Carbon::parse($str);

            if ($carbon->year < 1900 || $carbon->year > 2100) {
                return null;
            }

            return $carbon->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     *  🔥 VALIDATION D'UN MOIS "YYYY-MM"
     * ═══════════════════════════════════════════════════════════════════
     */
    private function moisValide($valeur): ?string
    {
        if (empty($valeur)) {
            return null;
        }

        $str = trim((string) $valeur);

        if (str_starts_with($str, '0000')) {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $str, $m)) {
            $str = $m[1] . '-' . $m[2];
        }

        if (!preg_match('/^(\d{4})-(\d{2})$/', $str, $m)) {
            return null;
        }

        $annee = (int) $m[1];
        $mois  = (int) $m[2];

        if ($annee < 1900 || $annee > 2100) {
            return null;
        }
        if ($mois < 1 || $mois > 12) {
            return null;
        }

        return sprintf('%04d-%02d', $annee, $mois);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════
     *  🔥 TRADUCTION DES ERREURS SQL
     * ═══════════════════════════════════════════════════════════════════
     */
    private function traduireErreurSQL(\Throwable $e): string
    {
        $msg = strtolower($e->getMessage());

        if (str_contains($msg, 'foreign key constraint fails')) {
            if (str_contains($msg, 'periode_id')) {
                return "La période comptable associée n'existe pas en base.";
            }
            if (str_contains($msg, 'compte_numero')) {
                return "Le compte comptable n'existe pas dans le plan comptable.";
            }
            if (str_contains($msg, 'entite_id')) {
                return "L'entité associée n'existe pas.";
            }
            if (str_contains($msg, 'succursale_id')) {
                return "La succursale associée n'existe pas.";
            }
            return "Contrainte de clé étrangère violée.";
        }

        if (str_contains($msg, 'duplicate entry')) {
            return "Cette écriture existe déjà.";
        }
        if (str_contains($msg, 'cannot be null')) {
            return "Un champ obligatoire est vide.";
        }
        if (str_contains($msg, 'unknown column')) {
            return "Erreur de structure de base de données (colonne inconnue).";
        }
        if (str_contains($msg, "doesn't exist")) {
            return "Erreur de structure de base de données (table manquante).";
        }
        if (str_contains($msg, 'periode est fermee') || str_contains($msg, 'période est fermée')) {
            return "La période comptable est fermée.";
        }

        return "Erreur technique : " . $e->getMessage();
    }
}