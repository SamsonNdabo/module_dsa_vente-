<?php

namespace App\Services;

use App\Models\Bilan;
use App\Models\BilanLigne;
use App\Models\PeriodesComptable;
use App\Models\PlanComptable;
use App\Models\EcrituresComptable;
use App\Models\Entite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class BilanService
{
    const DATE_COLUMN = 'date_ecriture';
    const DECIMALES = 4;
    const COMPTE_CAPITAL = '101';
    const COMPTE_RESULTAT_BENEFICE = '131';
    const COMPTE_RESULTAT_PERTE = '139';
    const COMPTE_REPORT_CREDITEUR = '121';
    const COMPTE_REPORT_DEBITEUR = '129';

    public function estVerrouille(Bilan $bilan): bool
    {
        if ($bilan->periode_comptable && $bilan->periode_comptable->statut === 'cloture_definitive') return true;
        if ($bilan->type_bilan === 'fermeture') return true;
        if ($bilan->type_bilan === 'initial') {
            return EcrituresComptable::where('periode_id', $bilan->periode_id)
                ->where('entite_id', $bilan->entite_id)
                ->where('reference', 'NOT LIKE', 'BILAN%')
                ->where('reference', 'NOT LIKE', 'À-NOUVEAU%')
                ->where('reference', 'NOT LIKE', 'CLÔTURE%')
                ->exists();
        }
        return false;
    }

    public function verifierEtCreerBilanInitial(int $entiteId, int $periodeId): ?Bilan
    {
        $bilan = Bilan::where('periode_id', $periodeId)
            ->where('type_bilan', 'initial')
            ->where('entite_id', $entiteId)
            ->first();
        
        if (!$bilan) {
            $bilan = $this->creerBilanInitialAutomatique($entiteId, $periodeId);
            Log::info('Bilan initial créé automatiquement', [
                'bilan_id' => $bilan->id, 
                'entite_id' => $entiteId, 
                'periode_id' => $periodeId
            ]);
        }
        
        return $bilan;
    }

    private function creerBilanInitialAutomatique(int $entiteId, int $periodeId): Bilan
    {
        $periode = PeriodesComptable::findOrFail($periodeId);
        $dateOuverture = $periode->date_debut->format('Y-m-d');
        $entite = Entite::findOrFail($entiteId);
        $exercice = date('Y', strtotime($dateOuverture));

        $bilan = Bilan::create([
            'periode_id' => $periodeId,
            'type_bilan' => 'initial',
            'date_bilan' => $dateOuverture,
            'exercice' => $exercice,
            'entite_id' => $entiteId,
            'succursale_id' => null,
            'is_actif' => true,
            'provient_cloture' => false,
            'est_provisoire' => false,
        ]);

        $soldes = $this->calculerSoldesParEntite($entiteId, $periodeId);
        
        // Préchargement du plan comptable
        $comptesNumeros = array_keys($soldes);
        $plansComptables = PlanComptable::whereIn('numero', $comptesNumeros)
            ->get()
            ->keyBy('numero');

        foreach ($soldes as $compte => $solde) {
            if (abs($solde['solde']) < 0.0001) continue;
            
            $plan = $plansComptables->get($compte);
            $typeCompte = $this->determinerTypeCompte($compte, $solde['solde'], $plan->position_bilan ?? null);
            $montant = round(abs($solde['solde']), self::DECIMALES);

            BilanLigne::create([
                'bilan_id' => $bilan->id,
                'compte_numero' => $compte,
                'compte_nom' => $plan->nom ?? 'Compte ' . $compte,
                'type_compte' => $typeCompte,
                'montant' => $montant,
            ]);
        }

        $this->genererEcrituresOuverture($bilan, $dateOuverture, $entite, $exercice);
        return $bilan;
    }

    private function calculerSoldesParEntite(int $entiteId, int $periodeId): array
    {
        $soldes = [];
        $mouvements = EcrituresComptable::where('entite_id', $entiteId)
            ->where('periode_id', $periodeId)
            ->select('compte_numero', 
                DB::raw('SUM(debit) as total_debit'), 
                DB::raw('SUM(credit) as total_credit'))
            ->groupBy('compte_numero')
            ->orderBy('compte_numero', 'asc')
            ->get();

        $comptesNumeros = $mouvements->pluck('compte_numero')->toArray();
        $plansComptables = PlanComptable::whereIn('numero', $comptesNumeros)
            ->get()
            ->keyBy('numero');

        foreach ($mouvements as $m) {
            $plan = $plansComptables->get($m->compte_numero);
            $soldeBrut = round($m->total_debit - $m->total_credit, self::DECIMALES);
            $typeCompte = $this->determinerTypeCompte($m->compte_numero, $soldeBrut, $plan->position_bilan ?? null);
            
            if (abs($soldeBrut) > 0.0001) {
                $soldes[$m->compte_numero] = [
                    'solde' => $soldeBrut, 
                    'type' => $typeCompte
                ];
            }
        }
        
        return $soldes;
    }

    private function genererEcrituresOuverture(Bilan $bilan, string $dateOuverture, $entite, string $exercice): void
    {
        $now = Carbon::now();
        $libelleBilan = "BILAN INITIAL - {$entite->nom} - EXERCICE {$exercice}";
        $numeroEcriture = 'BILAN-OUV-' . $bilan->periode_id . '-E' . $bilan->entite_id . '-' . date('YmdHis');
        $ordre = 1;
        
        $lignesTriees = $bilan->bilan_lignes()
            ->orderBy('compte_numero', 'asc')
            ->get();
        
        foreach ($lignesTriees as $ligne) {
            $estActif = $ligne->type_compte === 'actif';
            
            $ecriture = EcrituresComptable::create([
                'periode_id' => $bilan->periode_id,
                'date_ecriture' => $dateOuverture,
                'created_at' => $now,
                'updated_at' => $now,
                'compte_numero' => $ligne->compte_numero,
                'libelle' => $libelleBilan,
                'debit' => $estActif ? (float)$ligne->montant : 0,
                'credit' => !$estActif ? (float)$ligne->montant : 0,
                'numero_ecriture' => $numeroEcriture,
                'reference' => $libelleBilan,
                'entite_id' => $bilan->entite_id,
                'succursale_id' => $bilan->succursale_id,
                'sens_ecriture' => 'principal',
                'ordre_affichage' => $ordre++,
            ]);
            
            $ligne->update([
                'ecriture_id' => $ecriture->id,
                'date_ecriture' => $dateOuverture,
                'libelle_ecriture' => $libelleBilan
            ]);
        }
    }

    public function equilibrerBilan(array $comptes, string $typeBilan = 'initial'): array
    {
        $totalActif = 0;
        $totalPassif = 0;
        
        $numeros = array_column($comptes, 'compte_numero');
        $plansComptables = PlanComptable::whereIn('numero', $numeros)
            ->get()
            ->keyBy('numero');

        foreach ($comptes as $c) {
            $plan = $plansComptables->get($c['compte_numero']);
            $position = $plan->position_bilan ?? 'actif';
            
            if ($position === 'actif') {
                $totalActif += (float)$c['montant'];
            } else {
                $totalPassif += (float)$c['montant'];
            }
        }
        
        $ecart = round($totalActif - $totalPassif, self::DECIMALES);
        
        if (abs($ecart) > 0.0001) {
            $compteAjustement = $typeBilan === 'initial' ? self::COMPTE_CAPITAL : self::COMPTE_RESULTAT_BENEFICE;
            $trouve = false;
            
            foreach ($comptes as &$c) {
                if ($c['compte_numero'] === $compteAjustement) {
                    $c['montant'] = round((float)$c['montant'] + $ecart, self::DECIMALES);
                    $trouve = true;
                    break;
                }
            }
            
            if (!$trouve && PlanComptable::where('numero', $compteAjustement)->exists()) {
                $comptes[] = [
                    'compte_numero' => $compteAjustement, 
                    'montant' => round($ecart, self::DECIMALES)
                ];
            }
        }
        
        usort($comptes, fn($a, $b) => strcmp($a['compte_numero'], $b['compte_numero']));
        return $comptes;
    }

    public function creerLignesBilan(Bilan $bilan, array $comptes, array $validated): void
    {
        $periode = PeriodesComptable::findOrFail($validated['periode_id']);
        $entite = Entite::findOrFail($validated['entite_id'] ?? $bilan->entite_id);
        $exercice = date('Y', strtotime($validated['date_bilan'] ?? Carbon::now()));
        $prefixe = $validated['type_bilan'] === 'initial' ? 'BILAN INITIAL' : 'BILAN FERMETURE';
        $libelleBilan = "{$prefixe} - {$entite->nom} - EXERCICE {$exercice}";
        $numeroEcriture = 'BIL-' . strtoupper(substr($validated['type_bilan'], 0, 3)) . '-' . $periode->code . '-' . date('YmdHis');
        $ordre = 1;
        $dateEcriture = $validated['date_bilan'] ?? Carbon::now()->toDateString();
        $now = Carbon::now();

        $numeros = array_column($comptes, 'compte_numero');
        $plansComptables = PlanComptable::whereIn('numero', $numeros)
            ->get()
            ->keyBy('numero');

        foreach ($comptes as $c) {
            $plan = $plansComptables->get($c['compte_numero']);
            if (!$plan) continue;
            
            $position = $plan->position_bilan ?? 'actif';
            $estActif = $position === 'actif';

            $ligneBilan = BilanLigne::create([
                'bilan_id' => $bilan->id,
                'compte_numero' => $c['compte_numero'],
                'compte_nom' => $plan->nom,
                'type_compte' => $position,
                'montant' => (float)$c['montant']
            ]);

            $ecriture = EcrituresComptable::create([
                'periode_id' => $validated['periode_id'],
                'date_ecriture' => $dateEcriture,
                'created_at' => $now,
                'updated_at' => $now,
                'compte_numero' => $c['compte_numero'],
                'libelle' => $libelleBilan,
                'debit' => $estActif ? (float)$c['montant'] : 0,
                'credit' => !$estActif ? (float)$c['montant'] : 0,
                'numero_ecriture' => $numeroEcriture,
                'reference' => $libelleBilan,
                'entite_id' => $validated['entite_id'] ?? null,
                'succursale_id' => $validated['succursale_id'] ?? null,
                'sens_ecriture' => 'principal',
                'ordre_affichage' => $ordre++,
            ]);
            
            $ligneBilan->update([
                'ecriture_id' => $ecriture->id,
                'date_ecriture' => $dateEcriture,
                'libelle_ecriture' => $libelleBilan
            ]);
        }
    }

    public function synchroniserEcritures(Bilan $bilan, ?array $comptes = null, ?array $validated = null): void
    {
        if ($comptes === null) {
            $comptes = $bilan->bilan_lignes()
                ->orderBy('compte_numero', 'asc')
                ->get()
                ->map(fn($l) => [
                    'compte_numero' => $l->compte_numero, 
                    'montant' => $l->montant
                ])->toArray();
        }
        
        if ($validated === null) {
            $validated = [
                'periode_id' => $bilan->periode_id,
                'entite_id' => $bilan->entite_id,
                'type_bilan' => $bilan->type_bilan,
                'date_bilan' => $bilan->date_bilan
            ];
        }

        $periode = PeriodesComptable::findOrFail($validated['periode_id'] ?? $bilan->periode_id);
        $entite = Entite::findOrFail($validated['entite_id'] ?? $bilan->entite_id);
        $exercice = date('Y', strtotime($validated['date_bilan'] ?? $bilan->date_bilan ?? Carbon::now()));
        $prefixe = ($validated['type_bilan'] ?? $bilan->type_bilan) === 'initial' ? 'BILAN INITIAL' : 'BILAN FERMETURE';
        $libelleBilan = "{$prefixe} - {$entite->nom} - EXERCICE {$exercice}";
        $dateEcriture = $validated['date_bilan'] ?? $bilan->date_bilan ?? Carbon::now()->toDateString();
        $now = Carbon::now();
        $ordre = 1;

        DB::beginTransaction();
        try {
            $ecritureIdsExistantes = EcrituresComptable::where('entite_id', $bilan->entite_id)
                ->where('periode_id', $bilan->periode_id)
                ->where('reference', 'LIKE', 'BILAN%')
                ->pluck('id', 'compte_numero')
                ->toArray();

            $numeros = array_column($comptes, 'compte_numero');
            $plansComptables = PlanComptable::whereIn('numero', $numeros)
                ->get()
                ->keyBy('numero');

            foreach ($comptes as $c) {
                $plan = $plansComptables->get($c['compte_numero']);
                if (!$plan) continue;
                
                $position = $plan->position_bilan ?? 'actif';
                $estActif = $position === 'actif';
                
                if (isset($ecritureIdsExistantes[$c['compte_numero']])) {
                    EcrituresComptable::where('id', $ecritureIdsExistantes[$c['compte_numero']])->update([
                        'date_ecriture' => $dateEcriture,
                        'libelle' => $libelleBilan,
                        'debit' => $estActif ? (float)$c['montant'] : 0,
                        'credit' => !$estActif ? (float)$c['montant'] : 0,
                        'reference' => $libelleBilan,
                        'ordre_affichage' => $ordre++
                    ]);
                } else {
                    $numeroEcriture = 'BIL-SYNC-' . $bilan->periode_id . '-E' . $bilan->entite_id . '-' . date('YmdHis');
                    EcrituresComptable::create([
                        'periode_id' => $bilan->periode_id,
                        'date_ecriture' => $dateEcriture,
                        'created_at' => $now,
                        'compte_numero' => $c['compte_numero'],
                        'libelle' => $libelleBilan,
                        'debit' => $estActif ? (float)$c['montant'] : 0,
                        'credit' => !$estActif ? (float)$c['montant'] : 0,
                        'numero_ecriture' => $numeroEcriture,
                        'reference' => $libelleBilan,
                        'entite_id' => $bilan->entite_id,
                        'succursale_id' => $bilan->succursale_id,
                        'sens_ecriture' => 'principal',
                        'ordre_affichage' => $ordre++
                    ]);
                }
            }
            
            $comptesNumeros = array_column($comptes, 'compte_numero');
            $ecrituresASupprimer = array_diff_key($ecritureIdsExistantes, array_flip($comptesNumeros));
            
            if (!empty($ecrituresASupprimer)) {
                EcrituresComptable::whereIn('id', $ecrituresASupprimer)->delete();
            }
            
            $bilan->update([
                'date_bilan' => $dateEcriture, 
                'exercice' => $exercice
            ]);
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur synchronisation', ['message' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * CALCUL DU BILAN - RESPECT STRICT DU PLAN COMPTABLE
     * Chaque compte a une position_bilan définie (actif ou passif).
     */
    public function calculerBilanFiltre(array $params): array
    {
        $periodeId = $params['periode_id'] ?? null;
        $entiteId = $params['entite_id'] ?? null;
        $succursaleId = $params['succursale_id'] ?? null;
        $filterType = $params['filter_type'] ?? 'all';
        $dateDebut = $params['date_debut'] ?? null;
        $dateFin = $params['date_fin'] ?? null;
        $useCustomDates = $params['use_custom_dates'] ?? false;

        if (!$periodeId || $periodeId === 'en-cours') {
            $periodeEnCours = PeriodesComptable::where('date_debut', '<=', now())
                ->where('date_fin', '>=', now())
                ->where('statut', '!=', 'cloture_definitive')
                ->orderBy('date_debut', 'asc')
                ->first();
            if ($periodeEnCours) $periodeId = $periodeEnCours->id;
        }

        if ($periodeId && $periodeId !== 'all') {
            $periode = PeriodesComptable::find($periodeId);
            if ($periode && $periode->statut === 'cloture_definitive') {
                return $this->getBilanFinalData($periodeId, $entiteId, $succursaleId);
            }
        }

        $baseQuery = EcrituresComptable::query();
        if ($periodeId && $periodeId !== 'all') $baseQuery->where('periode_id', $periodeId);
        if ($entiteId) $baseQuery->where('entite_id', $entiteId);
        if ($succursaleId) $baseQuery->where('succursale_id', $succursaleId);
        $this->applyTimeFilter($baseQuery, $filterType, $dateDebut, $dateFin, $useCustomDates);

        // BILAN (Classes 1 à 5)
        $mouvementsBilan = (clone $baseQuery)
            ->where(function($q) {
                foreach (['1%', '2%', '3%', '4%', '5%'] as $c) {
                    $q->orWhere('compte_numero', 'LIKE', $c);
                }
            })
            ->select('compte_numero', 
                DB::raw('SUM(debit) as total_debit'), 
                DB::raw('SUM(credit) as total_credit'))
            ->groupBy('compte_numero')
            ->orderBy('compte_numero', 'asc')
            ->get();

        // CHARGES (Classe 6)
        $chargesMouvements = (clone $baseQuery)
            ->where('compte_numero', 'LIKE', '6%')
            ->select('compte_numero', 
                DB::raw('SUM(debit) as total_debit'), 
                DB::raw('SUM(credit) as total_credit'))
            ->groupBy('compte_numero')
            ->orderBy('compte_numero', 'asc')
            ->get();

        $chargesList = [];
        $totalCharges = 0;
        
        $numerosCharges = $chargesMouvements->pluck('compte_numero')->toArray();
        $plansCharges = PlanComptable::whereIn('numero', $numerosCharges)->get()->keyBy('numero');

        foreach ($chargesMouvements as $cm) {
            $plan = $plansCharges->get($cm->compte_numero);
            $soldeCharge = round($cm->total_debit - $cm->total_credit, self::DECIMALES);
            if (abs($soldeCharge) < 0.0001) continue;
            
            $totalCharges += $soldeCharge;
            $chargesList[] = [
                'numero' => $cm->compte_numero,
                'nom' => $plan->nom ?? 'Compte ' . $cm->compte_numero,
                'total_debit' => round($cm->total_debit, self::DECIMALES),
                'total_credit' => round($cm->total_credit, self::DECIMALES),
                'solde_net' => $soldeCharge,
                'solde_debiteur' => ($soldeCharge >= 0)
            ];
        }
        $totalCharges = round($totalCharges, self::DECIMALES);

        // PRODUITS (Classe 7)
        $produitsMouvements = (clone $baseQuery)
            ->where('compte_numero', 'LIKE', '7%')
            ->select('compte_numero', 
                DB::raw('SUM(debit) as total_debit'), 
                DB::raw('SUM(credit) as total_credit'))
            ->groupBy('compte_numero')
            ->orderBy('compte_numero', 'asc')
            ->get();

        $produitsList = [];
        $totalProduits = 0;
        
        $numerosProduits = $produitsMouvements->pluck('compte_numero')->toArray();
        $plansProduits = PlanComptable::whereIn('numero', $numerosProduits)->get()->keyBy('numero');

        foreach ($produitsMouvements as $pm) {
            $plan = $plansProduits->get($pm->compte_numero);
            $soldeProduit = round($pm->total_credit - $pm->total_debit, self::DECIMALES);
            if (abs($soldeProduit) < 0.0001) continue;
            
            $totalProduits += $soldeProduit;
            $produitsList[] = [
                'numero' => $pm->compte_numero,
                'nom' => $plan->nom ?? 'Compte ' . $pm->compte_numero,
                'total_debit' => round($pm->total_debit, self::DECIMALES),
                'total_credit' => round($pm->total_credit, self::DECIMALES),
                'solde_net' => $soldeProduit,
                'solde_debiteur' => ($soldeProduit >= 0)
            ];
        }
        $totalProduits = round($totalProduits, self::DECIMALES);

        // Construire l'actif et le passif selon position_bilan
        $actif = [];
        $passif = [];

        $numerosBilan = $mouvementsBilan->pluck('compte_numero')->toArray();
        $plansBilan = PlanComptable::whereIn('numero', $numerosBilan)->get()->keyBy('numero');

        foreach ($mouvementsBilan as $m) {
            $compte = $m->compte_numero;
            
            // Exclure les comptes de résultat intermédiaire déjà traités
            if (in_array($compte, ['131', '139', '121', '129'])) continue;

            $plan = $plansBilan->get($compte);
            if (!$plan) continue;
            
            $position = $plan->position_bilan;
            
            if (!$position || !in_array($position, ['actif', 'passif'])) {
                continue;
            }

            $td = (float)$m->total_debit;
            $tc = (float)$m->total_credit;
            $soldeBrut = round($td - $tc, self::DECIMALES);
            
            if (abs($soldeBrut) < 0.0001) continue;

            if ($position === 'actif') {
                $actif[] = [
                    'numero' => $compte,
                    'nom' => $plan->nom ?? 'Compte ' . $compte,
                    'total_debit' => $td,
                    'total_credit' => $tc,
                    'solde_net' => $soldeBrut,
                    'type' => 'actif',
                    'solde_debiteur' => ($soldeBrut >= 0)
                ];
            } else {
                $passif[] = [
                    'numero' => $compte,
                    'nom' => $plan->nom ?? 'Compte ' . $compte,
                    'total_debit' => $td,
                    'total_credit' => $tc,
                    'solde_net' => -$soldeBrut,
                    'type' => 'passif',
                    'solde_debiteur' => ($soldeBrut >= 0)
                ];
            }
        }

        // RÉSULTAT NET
        $resultatNet = round($totalProduits - $totalCharges, self::DECIMALES);

        // Intégration du résultat dans le Passif (Capitaux Propres)
        if (abs($resultatNet) >= 0.0001) {
            if ($resultatNet > 0) {
                $passif[] = [
                    'numero' => self::COMPTE_RESULTAT_BENEFICE,
                    'nom' => 'RESULTAT NET : BENEFICE',
                    'total_debit' => 0,
                    'total_credit' => $resultatNet,
                    'solde_net' => $resultatNet,
                    'type' => 'passif',
                    'solde_debiteur' => false
                ];
            } else {
                $passif[] = [
                    'numero' => self::COMPTE_RESULTAT_PERTE,
                    'nom' => 'RESULTAT NET : PERTE',
                    'total_debit' => abs($resultatNet),
                    'total_credit' => 0,
                    'solde_net' => $resultatNet, // Négatif au passif
                    'type' => 'passif',
                    'solde_debiteur' => true
                ];
            }
        }

        usort($actif, fn($a, $b) => strcmp($a['numero'], $b['numero']));
        usort($passif, fn($a, $b) => strcmp($a['numero'], $b['numero']));

        $totalActif = round(array_sum(array_column($actif, 'solde_net')), self::DECIMALES);
        $totalPassifFinal = round(array_sum(array_column($passif, 'solde_net')), self::DECIMALES);
        $ecart = round($totalActif - $totalPassifFinal, self::DECIMALES);

        return [
            'actif' => [
                'solde' => $totalActif,
                'detail' => array_values($actif)
            ],
            'passif' => [
                'solde' => $totalPassifFinal,
                'detail' => array_values($passif)
            ],
            'charges' => [
                'solde' => $totalCharges,
                'detail' => $chargesList
            ],
            'produits' => [
                'solde' => $totalProduits,
                'detail' => $produitsList
            ],
            'resultat_net' => $resultatNet,
            'resultat_texte' => $resultatNet >= 0 ? 'BÉNÉFICE' : 'PERTE',
            'equilibre' => abs($ecart) < 0.0001,
            'ecart' => $ecart,
        ];
    }

    private function getBilanFinalData($periodeId, $entiteId, $succursaleId): array
    {
        $bilanFinal = Bilan::with(['bilan_lignes' => function($query) {
                $query->orderBy('compte_numero', 'asc');
            }])
            ->where('periode_id', $periodeId)
            ->where('type_bilan', 'fermeture')
            ->when($entiteId, fn($q) => $q->where('entite_id', $entiteId))
            ->when($succursaleId, fn($q) => $q->where('succursale_id', $succursaleId))
            ->first();

        if (!$bilanFinal) {
            return [
                'actif' => ['solde' => 0, 'detail' => []],
                'passif' => ['solde' => 0, 'detail' => []],
                'equilibre' => true,
                'ecart' => 0
            ];
        }

        $actif = [];
        $passif = [];
        
        foreach ($bilanFinal->bilan_lignes as $ligne) {
            $item = [
                'numero' => $ligne->compte_numero,
                'nom' => $ligne->compte_nom,
                'solde_net' => round((float)$ligne->montant, self::DECIMALES),
                'type' => $ligne->type_compte
            ];
            
            if ($ligne->type_compte === 'actif') {
                $actif[] = $item;
            } else {
                $passif[] = $item;
            }
        }
        
        usort($actif, fn($a, $b) => strcmp($a['numero'], $b['numero']));
        usort($passif, fn($a, $b) => strcmp($a['numero'], $b['numero']));

        return [
            'actif' => [
                'solde' => round(array_sum(array_column($actif, 'solde_net')), self::DECIMALES),
                'detail' => $actif
            ],
            'passif' => [
                'solde' => round(array_sum(array_column($passif, 'solde_net')), self::DECIMALES),
                'detail' => $passif
            ],
            'equilibre' => true,
            'ecart' => 0
        ];
    }

    private function applyTimeFilter($query, string $filterType, $dateDebut = null, $dateFin = null, $useCustomDates = false): void
    {
        $now = Carbon::now();
        
        if ($useCustomDates && ($dateDebut || $dateFin)) {
            if ($dateDebut && $dateFin) {
                $query->whereDate(self::DATE_COLUMN, '>=', $dateDebut)
                    ->whereDate(self::DATE_COLUMN, '<=', $dateFin);
            } elseif ($dateDebut) {
                $query->whereDate(self::DATE_COLUMN, '>=', $dateDebut)
                    ->whereDate(self::DATE_COLUMN, '<=', $now->toDateString());
            } elseif ($dateFin) {
                $query->whereDate(self::DATE_COLUMN, '<=', $dateFin);
            }
            return;
        }
        
        switch ($filterType) {
            case 'today':
            case 'last3days':
            case 'last7days':
            case 'last30days':
                $query->whereDate(self::DATE_COLUMN, '<=', $now->toDateString());
                break;
            case 'yesterday':
                $query->whereDate(self::DATE_COLUMN, '<=', $now->copy()->subDay()->toDateString());
                break;
            case 'this_week':
                $query->whereDate(self::DATE_COLUMN, '<=', $now->copy()->endOfWeek()->toDateString());
                break;
            case 'last_week':
                $query->whereDate(self::DATE_COLUMN, '<=', $now->copy()->subWeek()->endOfWeek()->toDateString());
                break;
            case 'this_month':
                $query->whereDate(self::DATE_COLUMN, '<=', $now->copy()->endOfMonth()->toDateString());
                break;
            case 'last_month':
                $query->whereDate(self::DATE_COLUMN, '<=', $now->copy()->subMonth()->endOfMonth()->toDateString());
                break;
            case 'this_year':
                $query->whereDate(self::DATE_COLUMN, '<=', $now->copy()->endOfYear()->toDateString());
                break;
            case 'all':
            case 'exercice':
                break;
            case 'custom':
                if ($dateDebut && $dateFin) {
                    $query->whereBetween(self::DATE_COLUMN, [$dateDebut, $dateFin]);
                } elseif ($dateDebut) {
                    $query->whereDate(self::DATE_COLUMN, '>=', $dateDebut);
                } elseif ($dateFin) {
                    $query->whereDate(self::DATE_COLUMN, '<=', $dateFin);
                }
                break;
        }
    }

    private function determinerTypeCompte(string $numero, float $soldeBrut, ?string $typeParDefaut): string
    {
        $plan = PlanComptable::where('numero', $numero)->first();

        if ($plan && in_array($plan->position_bilan, ['actif', 'passif'])) {
            return $plan->position_bilan;
        }

        if (in_array($typeParDefaut, ['actif', 'passif'])) {
            return $typeParDefaut;
        }

        $classe = substr($numero, 0, 1);
        
        if ($classe === '1') return 'passif';
        if (in_array($classe, ['2', '3', '5'])) return 'actif';
        if ($classe === '4') {
            return $soldeBrut >= 0 ? 'actif' : 'passif';
        }

        return 'actif';
    }

    public function getTypesBilanDisponibles($periodeId, $entiteId = null, $succursaleId = null): array
    {
        $types = [];

        $query = Bilan::where('periode_id', $periodeId)
            ->when($entiteId, function ($q) use ($entiteId) {
                return $q->where('entite_id', $entiteId);
            })
            ->when($succursaleId, function ($q) use ($succursaleId) {
                return $q->where('succursale_id', $succursaleId);
            });

        $typesExistants = (clone $query)->select('type_bilan')
            ->distinct()
            ->pluck('type_bilan')
            ->toArray();

        $labels = [
            'initial' => 'Bilan Initial',
            'fermeture' => 'Bilan de Fermeture',
            'provisoire' => 'Bilan Provisoire',
        ];

        foreach ($typesExistants as $type) {
            $types[] = [
                'value' => $type,
                'label' => $labels[$type] ?? ucfirst($type),
            ];
        }

        return $types;
    }
}