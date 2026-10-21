<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\TransfertBancaire;
use App\Models\TransfertInternational;
use App\Models\Caisses;
use App\Models\OperateursInternational;
use App\Models\OperationChange;
use App\Models\Devise;
use App\Models\SoldeCaisse;
use App\Models\Role;
use App\Models\Transaction_Globale;
use App\Models\Commission;
use App\Models\Transaction_Mobile;
use App\Models\Utilisateur;
use App\Models\ExchangeRate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    // ============================================================
    // STATISTIQUES GÉNÉRALES
    // ============================================================

    public function stats(Request $request)
    {
        $user = auth()->user();
        $isSuperAdmin = $user && $user->role_id === 1; // ou selon votre logique
        $succursaleId = $user->succursale_id ?? null;
        

        // Filtres optionnels (query string)
        $dateDebut = $request->input('date_debut'); // ex: 2026-09-01
        $dateFin   = $request->input('date_fin');   // ex: 2026-09-30
        $module    = $request->input('module');     // VENTE / TRANSACTION / CHANGE
        $operateur = $request->input('operateur');  // ex: ORANGE, WESTERN UNION


        // Métriques générales
        $statsGenerales = $this->getGlobalStats($isSuperAdmin, $succursaleId);
        $statsParAgent = $this->getTransactionsParAgent($isSuperAdmin, $succursaleId);
        $volumeTransactions = $this->getVolumeTransactionsParDevise($isSuperAdmin, $succursaleId);
        $volumeOperateurMobile = $this->getVolumeParOperateurMobile($isSuperAdmin, $succursaleId);
        $volumeOperateurInternational = $this->getVolumeParOperateurInternational($isSuperAdmin, $succursaleId);
        $volumeOperateurBancaire = $this->getVolumeParOperateurBancaire($isSuperAdmin, $succursaleId);
        $volumeCaissier = $this->getVolumeParCaissier($isSuperAdmin, $succursaleId);
        $volumeClient = $this->getVolumeParClient($isSuperAdmin, $succursaleId);
        $commissionsUsd = $this->getCommissionsTotalesEnUsd(
            $isSuperAdmin,
            $succursaleId,
            $dateDebut,
            $dateFin,
            $module,
            $operateur
        );


        // Métriques complémentaires
        $soldesParDevise = $this->getSoldesParDevise($isSuperAdmin, $succursaleId);
        $tauxMoyenAchat = $this->getTauxMoyenAchat();
        $transactionsEnDollars = $this->getTransactionsEnDollars($isSuperAdmin, $succursaleId);
        $montantVenduDollars = $this->getMontantVenduDollars($isSuperAdmin, $succursaleId);
        $soldesCoffres = $this->getSoldesCoffresParSuccursale($isSuperAdmin, $succursaleId);
        $liquidite = $this->getLiquiditeParDevise($isSuperAdmin, $succursaleId);

        return response()->json([
            'success' => true,
            'data' => [
                // 1. Indicateurs généraux
                'total_caisses' => $statsGenerales['total_caisses'],
                'caisses_ouvertes' => $statsGenerales['caisses_ouvertes'],
                'total_operateurs' => $statsGenerales['total_operateurs'],
                'operateurs_actifs' => $statsGenerales['operateurs_actifs'],
                'volume_transactions' => $volumeTransactions,
                'volume_par_operateur_mobile' => $volumeOperateurMobile,
                'volume_par_operateur_international' => $volumeOperateurInternational,
                'volume_par_operateur_bancaire' => $volumeOperateurBancaire,
                'volume_par_caissier' => $volumeCaissier,
                'volume_par_client' => $volumeClient,
                'commissions_totales_usd' => $commissionsUsd['total_usd'],
                'commissions_details' => $commissionsUsd['details'],

                // 2. Détails par agent et devise
                'par_agent' => $statsParAgent,

                // 4. Soldes et taux
                'soldes_par_devise' => $soldesParDevise,
                'taux_moyen_achat' => $tauxMoyenAchat,
                'soldes_coffres' => $soldesCoffres,
                'liquidite' => $liquidite,

                // 5. Transactions en dollars
                'transactions_en_dollars' => $transactionsEnDollars,
                'montant_vendu_dollars' => $montantVenduDollars,
            ],
        ]);
    }

    // ============================================================
    // MÉTHODES PRIVÉES : STATISTIQUES GÉNÉRALES
    // ============================================================

    private function getGlobalStats(bool $isSuperAdmin, ?int $succursaleId): array
    {
        $transfertsQuery = TransfertInternational::query();
        if (!$isSuperAdmin && $succursaleId) {
            $transfertsQuery->where('succursale_id', $succursaleId);
        }

        $caissesQuery = Caisses::query();
        if (!$isSuperAdmin && $succursaleId) {
            $caissesQuery->where('succursale_id', $succursaleId);
        }

        $operateursQuery = OperateursInternational::query();

        return [
            'total_caisses' => $caissesQuery
            ->where('categorie', 'SECONDAIRE')
                ->where('type_caisse', 'PHYSIQUE')
                ->count(),
            'caisses_ouvertes' => $caissesQuery
                ->where('statut', 'OUVERTE')
                ->where('categorie', 'SECONDAIRE')
                ->where('type_caisse', 'PHYSIQUE')
                ->count(),
            'total_operateurs' => $operateursQuery->count(),
            'operateurs_actifs' => $operateursQuery->where('statut', 'ACTIF')->count(),
        ];
    }

    // ============================================================
    // MÉTHODES PRIVÉES : TRANSACTIONS PAR AGENT / DEVISE
    // ============================================================

    private function getTransactionsParAgent(bool $isSuperAdmin, ?int $succursaleId): array
    {
        return TransfertInternational::select(
                'transfert_international.caissier_id',
                DB::raw('count(*) as total'),
                'utilisateurs.nom_complet',
                'utilisateurs.succursale_id'
            )
            ->leftJoin('utilisateurs', 'transfert_international.caissier_id', '=', 'utilisateurs.id')
            ->whereNotNull('transfert_international.caissier_id')
            ->when(!$isSuperAdmin && $succursaleId, function ($q) use ($succursaleId) {
                $q->where('transfert_international.succursale_id', $succursaleId);
            })
            ->groupBy('transfert_international.caissier_id', 'utilisateurs.nom_complet', 'utilisateurs.succursale_id')
            ->get()
            ->map(fn($item) => [
                'caissier_id' => $item->caissier_id,
                'total' => $item->total,
                'caissier' => ['nom_complet' => $item->nom_complet],
                'succursale_id' => $item->succursale_id,
            ])
            ->toArray();
    }

    // ============================================================
    // ✅ NOUVELLES MÉTRIQUES
    // ============================================================

    /**
     * 1. Soldes totaux par devise (toutes caisses confondues)
     */
    private function getSoldesParDevise(bool $isSuperAdmin, ?int $succursaleId): array
    {
        return SoldeCaisse::select('devise_id', DB::raw('SUM(solde) as total'))
            ->join('caisses', 'solde_caisse.caisse_id', '=', 'caisses.id')
            ->when(!$isSuperAdmin && $succursaleId, function ($q) use ($succursaleId) {
                $q->where('caisses.succursale_id', $succursaleId);
            })
            ->groupBy('devise_id')
            ->with('devise')
            ->get()
            ->map(fn($item) => [
                'devise' => $item->devise->code ?? '?',
                'total' => (float) $item->total,
            ])
            ->toArray();
    }

    /**
     * 2. Taux moyen d'achat à jour de toutes les devises (depuis taux_bancaires)
     *    Récupère le dernier taux d'achat pour chaque devise.
     */
    private function getTauxMoyenAchat(): array
    {
        return DB::table('taux_bancaires')
            ->join('devise', 'taux_bancaires.devise_id', '=', 'devise.id')
            ->select('devise.code as devise', DB::raw('AVG(taux) as taux_moyen'))
            ->groupBy('devise.code')
            ->get()
            ->map(fn($item) => [
                'devise' => $item->devise,
                'taux_moyen' => round((float) $item->taux_moyen, 4),
            ])
            ->toArray();
    }

    /**
     * 3. Nombre de transactions en dollars (opérations de change où USD est impliqué)
     *    Ici on compte les opérations où la devise cible est USD (vente de dollars)
     *    ou source est USD (achat de dollars), selon votre métier.
     *    Je prends l'exemple des opérations où devise_cible_id = USD (vente de dollars).
     */
    private function getTransactionsEnDollars(bool $isSuperAdmin, ?int $succursaleId): int
    {
        $usdId = Devise::getIdByCode('USD'); // à adapter

        return OperationChange::where('devise_cible_id', $usdId)
            ->when(!$isSuperAdmin && $succursaleId, function ($q) use ($succursaleId) {
                $q->where('succursale_id', $succursaleId);
            })
            ->count();
    }

    /**
     * 4. Montant total vendu en dollars (somme des montants en dollars)
     */
    private function getMontantVenduDollars(bool $isSuperAdmin, ?int $succursaleId): float
    {
        $usdId = Devise::getIdByCode('USD');

        return (float) OperationChange::where('devise_cible_id', $usdId)
            ->when(!$isSuperAdmin && $succursaleId, function ($q) use ($succursaleId) {
                $q->where('succursale_id', $succursaleId);
            })
            ->sum('montant_cible');
    }

    // ============================================================
    // TOP CLIENTS / CAISSIERS
    // ============================================================

    public function topClients(Request $request)
    {
        $limit = $request->integer('limit', 5);

        return OperationChange::selectRaw('client_id, COUNT(*) as total_operations')
            ->whereNotNull('client_id')
            ->with('client:id,nom_complet,email')
            ->groupBy('client_id')
            ->orderByDesc('total_operations')
            ->limit($limit)
            ->get()
            ->map(fn($item) => [
                'client_id' => $item->client_id,
                'nom' => $item->client?->nom_complet ?? 'Client inconnu',
                'total_operations' => $item->total_operations,
            ])
            ->toArray();
    }

    public function topCaissiers(Request $request)
    {
        $limit = $request->integer('limit', 5);

        return OperationChange::selectRaw('operateur_id, COUNT(*) as total_operations')
            ->whereNotNull('operateur_id')
            ->with('operateur:id,nom_complet')
            ->groupBy('operateur_id')
            ->orderByDesc('total_operations')
            ->limit($limit)
            ->get()
            ->map(fn($item) => [
                'caissier_id' => $item->operateur_id,
                'nom' => $item->operateur?->nom_complet ?? 'Caissier inconnu',
                'total_operations' => $item->total_operations,
            ])
            ->toArray();
    }

    public function statsCommissions(Request $request)
    {
        $user = auth()->user();
        $isSuperAdmin = $user && in_array($user->role_id, [1, 2]);

        // Période
        $mois = $request->input('mois', now()->format('Y-m'));
        $dateDebut = \Carbon\Carbon::createFromFormat('Y-m', $mois)->startOfMonth();
        $dateFin = \Carbon\Carbon::createFromFormat('Y-m', $mois)->endOfMonth();

        // Filtres
        $module = $request->input('module');
        $operateur = $request->input('operateur');
        $caissierId = $request->input('caissier_id');

        // Requête de base
        $query = Commission::with(['caissier', 'transaction', 'devise'])
            ->whereBetween('created_at', [$dateDebut, $dateFin]);

        if (!$isSuperAdmin && $user && $user->succursale_id) {
            $query->where('succursale_id', $user->succursale_id);
        }
        if ($module) {
            $query->where('module', $module);
        }
        if ($operateur) {
            $query->where('operateur', 'like', '%' . $operateur . '%');
        }
        if ($caissierId) {
            $query->where('caissier_id', $caissierId);
        }

        $commissions = $query->orderBy('created_at', 'desc')->get();

        $cdfId = Devise::where('code', 'CDF')->value('id');
        $usdId = Devise::where('code', 'USD')->value('id');

        // ---------- Totaux avec conversion ----------
        $totalCdf = 0;
        $totalUsd = 0;
        $details = [];
        $parModule = [];
        $topOperateurs = [];
        $topCaissiers = [];

        foreach ($commissions as $commission) {
            $montantCdf = 0;
            $montantUsd = 0;

            // Conversion selon la devise
            if ($commission->devise_id == $cdfId) {
                $montantCdf = (float) $commission->montant_total;
            } elseif ($commission->devise_id == $usdId) {
                $montantUsd = (float) $commission->montant_total;
            } else {
                // Devise autre → convertir en CDF puis USD
                $devise = $commission->devise;
                if ($devise) {
                    // Devise → CDF
                    $tauxCdf = ExchangeRate::getTauxActif($devise->code, 'CDF', null, $commission->succursale_id);
                    if (!$tauxCdf) {
                        $tauxCdf = ExchangeRate::getTauxActif('CDF', $devise->code, null, $commission->succursale_id);
                    }
                    if ($tauxCdf) {
                        $rate = $tauxCdf['rate'];
                        $sens = $tauxCdf['sens'];
                        $montantCdf = ($sens === 'direct')
                            ? (float) $commission->montant_total * $rate->getTauxAchat($sens)
                            : (float) $commission->montant_total / $rate->getTauxVente($sens);
                    }

                    // CDF → USD
                    if ($montantCdf > 0) {
                        $tauxUsd = ExchangeRate::getTauxActif('CDF', 'USD', null, $commission->succursale_id);
                        if (!$tauxUsd) {
                            $tauxUsd = ExchangeRate::getTauxActif('USD', 'CDF', null, $commission->succursale_id);
                        }
                        if ($tauxUsd) {
                            $rateUsd = $tauxUsd['rate'];
                            $sensUsd = $tauxUsd['sens'];
                            $montantUsd = ($sensUsd === 'direct')
                                ? $montantCdf / $rateUsd->getTauxVente($sensUsd)
                                : $montantCdf * $rateUsd->getTauxAchat($sensUsd);
                        }
                    }
                }
            }

            $totalCdf += $montantCdf;
            $totalUsd += $montantUsd;

            // Détails
            $details[] = [
                'id'               => $commission->id,
                'reference'        => $commission->transaction?->reference ?? $commission->id_transaction_global,
                'module'           => $commission->module ?? 'AUTRE',
                'operateur'        => $commission->operateur,
                'caissier'         => $commission->caissier?->nom_complet ?? 'N/A',
                'devise_originale' => $commission->devise?->code ?? '?',
                'montant_origine'  => (float) $commission->montant_total,
                'montant_cdf'      => round($montantCdf, 2),
                'montant_usd'      => round($montantUsd, 2),
                'statut'           => $commission->statut,
                'date'             => $commission->created_at->toDateString(),
            ];

            // Par module
            $mod = $commission->module ?? 'AUTRE';
            if (!isset($parModule[$mod])) {
                $parModule[$mod] = ['module' => $mod, 'total_cdf' => 0, 'total_usd' => 0, 'nombre' => 0];
            }
            $parModule[$mod]['total_cdf'] += $montantCdf;
            $parModule[$mod]['total_usd'] += $montantUsd;
            $parModule[$mod]['nombre']++;

            // Top opérateurs
            if ($commission->operateur) {
                $op = $commission->operateur;
                if (!isset($topOperateurs[$op])) {
                    $topOperateurs[$op] = ['operateur' => $op, 'total_cdf' => 0, 'total_usd' => 0, 'nombre' => 0];
                }
                $topOperateurs[$op]['total_cdf'] += $montantCdf;
                $topOperateurs[$op]['total_usd'] += $montantUsd;
                $topOperateurs[$op]['nombre']++;
            }

            // Top caissiers
            if ($commission->caissier_id) {
                $cId = $commission->caissier_id;
                if (!isset($topCaissiers[$cId])) {
                    $topCaissiers[$cId] = [
                        'caissier_id'  => $cId,
                        'caissier_nom' => $commission->caissier?->nom_complet ?? 'Inconnu',
                        'total_cdf'    => 0,
                        'total_usd'    => 0,
                        'nombre'       => 0,
                    ];
                }
                $topCaissiers[$cId]['total_cdf'] += $montantCdf;
                $topCaissiers[$cId]['total_usd'] += $montantUsd;
                $topCaissiers[$cId]['nombre']++;
            }
        }

        // Tri des tops
        usort($topOperateurs, fn($a, $b) => $b['total_cdf'] <=> $a['total_cdf']);
        usort($topCaissiers, fn($a, $b) => $b['total_cdf'] <=> $a['total_cdf']);
        $topOperateurs = array_slice(array_values($topOperateurs), 0, 10);
        $topCaissiers = array_slice(array_values($topCaissiers), 0, 10);

        // Évolution sur 12 mois (toujours en CDF/USD, sans filtre de module/opérateur)
        $evolution = Commission::select(
                DB::raw('DATE_FORMAT(created_at, "%Y-%m") as mois'),
                DB::raw('SUM(CASE WHEN devise_id = ' . $cdfId . ' THEN montant_total ELSE 0 END) as total_cdf'),
                DB::raw('SUM(CASE WHEN devise_id = ' . $usdId . ' THEN montant_total ELSE 0 END) as total_usd')
            )
            ->when(!$isSuperAdmin && $user && $user->succursale_id, function ($q) use ($user) {
                $q->where('succursale_id', $user->succursale_id);
            })
            ->where('created_at', '>=', now()->subMonths(12)->startOfMonth())
            ->groupBy('mois')
            ->orderBy('mois', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'periode' => [
                    'mois'  => $mois,
                    'debut' => $dateDebut->toDateString(),
                    'fin'   => $dateFin->toDateString(),
                ],
                'filtres' => [
                    'module'      => $module,
                    'operateur'   => $operateur,
                    'caissier_id' => $caissierId,
                ],
                'totaux' => [
                    'total_cdf'         => round($totalCdf, 2),
                    'total_usd'         => round($totalUsd, 2),
                    'total_commissions' => $commissions->count(),
                ],
                'par_module'         => array_values($parModule),
                'top_operateurs'     => $topOperateurs,
                'top_caissiers'      => $topCaissiers,
                'evolution_12_mois'  => $evolution,
                'details'            => $details,
            ],
        ]);
    }

    private function getSoldesCoffresParSuccursale(bool $isSuperAdmin, ?int $succursaleId): array
    {
        $query = SoldeCaisse::select('devise_id', DB::raw('SUM(solde) as total'))
            ->join('caisses', 'solde_caisse.caisse_id', '=', 'caisses.id')
            ->where('caisses.categorie', 'COFFRE')
            ->where('caisses.inactif', 0);

        if (!$isSuperAdmin && $succursaleId) {
            $query->where('caisses.succursale_id', $succursaleId);
        }

        return $query->groupBy('devise_id')
            ->with('devise')
            ->get()
            ->map(fn($item) => [
                'devise' => $item->devise->code ?? '?',
                'total' => (float) $item->total,
            ])
            ->toArray();
    }

    /**
     * Calcule la liquidité totale : somme des soldes des caisses PHYSIQUES
     * (coffres et secondaires physiques) d'une succursale, par devise.
     */
    private function getLiquiditeParDevise(bool $isSuperAdmin, ?int $succursaleId): array
    {
        $query = SoldeCaisse::select('devise_id', DB::raw('SUM(solde) as total'))
            ->join('caisses', 'solde_caisse.caisse_id', '=', 'caisses.id')
            ->where('caisses.type_caisse', 'PHYSIQUE') // Seules les caisses physiques
            ->where('caisses.inactif', 0) // Actives
            ->where('caisses.categorie', '!=', 'PRINCIPALE'); // Exclure les principales (non opérationnelles)

        if (!$isSuperAdmin && $succursaleId) {
            $query->where('caisses.succursale_id', $succursaleId);
        }

        return $query->groupBy('devise_id')
            ->with('devise')
            ->get()
            ->map(fn($item) => [
                'devise' => $item->devise->code ?? '?',
                'total' => (float) $item->total,
            ])
            ->toArray();
    }

    /**
     * Calcule le volume total de transactions (dépôts, retraits, envois, transferts)
     * par devise pour une succursale donnée.
     */
    private function getVolumeTransactionsParDevise(bool $isSuperAdmin, ?int $succursaleId): array
    {
        $types = [
            'MOBILE_MONEY',
            'MOBILE_MONEY_RETRAIT',
            'TRANSFERT_INTERNATIONAL',
            'TRANSFERT_INTERNATIONAL_PAIEMENT',
            'TRANSFERT_BANCAIRE',
            'TRANSFERT_BANCAIRE_RETRAIT'
        ];

        $query = transaction_globale::select('devise_id', DB::raw('SUM(montant_local) as total'))
            ->whereIn('type', $types)
            ->where('statut', 'COMPLETEE'); // Transactions réussies uniquement

        if (!$isSuperAdmin && $succursaleId) {
            $query->where('id_succursale', $succursaleId);
        }

        return $query->groupBy('devise_id')
            ->with('devise')
            ->get()
            ->map(fn($item) => [
                'devise' => $item->devise->code ?? '?',
                'total' => (float) $item->total,
            ])
            ->toArray();
    }

    private function getVolumeParOperateurMobile(bool $isSuperAdmin, ?int $succursaleId): array
    {
        $query = Transaction_Mobile::select('id_operateur_mobile', DB::raw('COUNT(*) as volume'))
            ->where('statut_transaction', 'COMPLETEE');

        if (!$isSuperAdmin && $succursaleId) {
            $query->whereHas('caisse', function ($q) use ($succursaleId) {
                $q->where('succursale_id', $succursaleId);
            });
        }

        return $query->groupBy('id_operateur_mobile')
            ->with('operateur')
            ->get()
            ->map(fn($item) => [
                'operateur' => $item->operateur?->nom_operateur ?? 'Inconnu',
                'volume' => $item->volume,
            ])
            ->toArray();
    }

    private function getVolumeParOperateurInternational(bool $isSuperAdmin, ?int $succursaleId): array
    {
        $query = TransfertInternational::select('operateur_transfert_id', DB::raw('COUNT(*) as volume'))
            ->where('statut_transfert', 'PAYE');

        if (!$isSuperAdmin && $succursaleId) {
            $query->where('succursale_id', $succursaleId);
        }

        return $query->groupBy('operateur_transfert_id')
            ->with('operateur')
            ->get()
            ->map(fn($item) => [
                'operateur' => $item->operateur?->nom_operateur ?? 'Inconnu',
                'volume' => $item->volume,
            ])
            ->toArray();
    }
    
    private function getVolumeParOperateurBancaire(bool $isSuperAdmin, ?int $succursaleId): array
    {
        $query = TransfertBancaire::select('id_operateur_bancaire', DB::raw('COUNT(*) as volume'))
            ->where('statut', 'PAYE');

        if (!$isSuperAdmin && $succursaleId) {
            $query->where('succursale_id', $succursaleId);
        }

        return $query->groupBy('id_operateur_bancaire')
            ->with('operateurBancaire')
            ->get()
            ->map(fn($item) => [
                'operateur' => $item->operateurBancaire?->nom_operateur ?? 'Inconnu',
                'volume' => $item->volume,
            ])
            ->toArray();
    }

    private function getVolumeParCaissier(bool $isSuperAdmin, ?int $succursaleId): array
    {
        $query = transaction_globale::select('id_creator', DB::raw('COUNT(*) as volume'))
            ->whereIn('type', ['CHANGE', 'MOBILE_MONEY', 'MOBILE_MONEY_RETRAIT', 'TRANSFERT_INTERNATIONAL'])
            ->where('statut', 'COMPLETEE')
            ->whereNotNull('id_creator');

        if (!$isSuperAdmin && $succursaleId) {
            $query->where('id_succursale', $succursaleId);
        }

        return $query->groupBy('id_creator')
            ->with('creator')
            ->get()
            ->map(fn($item) => [
                'caissier' => $item->creator?->nom_complet ?? 'Inconnu',
                'volume' => $item->volume,
            ])
            ->toArray();
    }

    private function getVolumeParClient(bool $isSuperAdmin, ?int $succursaleId): array
    {
        $query = transaction_globale::select('client_id', DB::raw('COUNT(*) as volume'))
            ->whereIn('type', ['CHANGE', 'MOBILE_MONEY', 'MOBILE_MONEY_RETRAIT', 'TRANSFERT_INTERNATIONAL'])
            ->where('statut', 'COMPLETEE')
            ->whereNotNull('client_id');

        if (!$isSuperAdmin && $succursaleId) {
            $query->where('id_succursale', $succursaleId);
        }

        return $query->groupBy('client_id')
            ->with('client')
            ->get()
            ->map(fn($item) => [
                'client' => $item->client?->nom_complet ?? 'Inconnu',
                'volume' => $item->volume,
            ])
            ->toArray();
    }
    
    /**
     * Calcule la somme totale des commissions en USD.
     * - Les commissions déjà en USD sont additionnées directement.
     * - Les commissions en CDF (ou autre devise) sont converties en USD via ExchangeRate (cascade).
     *
     * @param bool        $isSuperAdmin   Si true, aucune restriction de succursale
     * @param int|null    $succursaleId   Filtre par succursale (si non super admin)
     * @param string|null $dateDebut      Date de début (Y-m-d)
     * @param string|null $dateFin        Date de fin (Y-m-d)
     * @param string|null $module         Filtre optionnel par module (VENTE, TRANSACTION, CHANGE)
     * @param string|null $operateur      Filtre optionnel par opérateur (nom)
     *
     * @return array{total_usd: float, details: array}
     */
    private function getCommissionsTotalesEnUsd(
        bool $isSuperAdmin,
        ?int $succursaleId,
        ?string $dateDebut = null,
        ?string $dateFin = null,
        ?string $module = null,
        ?string $operateur = null
    ): array {
        // Récupérer les IDs des devises de référence
        $usdId = Devise::where('code', 'USD')->value('id');
        $cdf = null;
        $usd = null;

        if (!$usdId) {
            Log::warning('Devise USD introuvable pour le calcul des commissions.');
            return ['total_usd' => 0.0, 'details' => []];
        }

        // Construction de la requête agrégée par devise
        $query = Commission::select('devise_id', DB::raw('SUM(montant_total) as total'))
            ->where('statut', 'PAYEE')
            ->when(!$isSuperAdmin && $succursaleId, function ($q) use ($succursaleId) {
                $q->where('succursale_id', $succursaleId);
            })
            ->when($dateDebut, function ($q) use ($dateDebut) {
                $q->whereDate('created_at', '>=', $dateDebut);
            })
            ->when($dateFin, function ($q) use ($dateFin) {
                $q->whereDate('created_at', '<=', $dateFin);
            })
            ->when($module, function ($q) use ($module) {
                $q->where('module', $module);
            })
            ->when($operateur, function ($q) use ($operateur) {
                $q->where('operateur', $operateur);
            })
            ->groupBy('devise_id');

        $commissionsParDevise = $query->get();

        $totalUsd = 0.0;
        $details = [];

        foreach ($commissionsParDevise as $item) {
            $deviseId = (int) $item->devise_id;
            $montant  = (float) $item->total;
            $devise   = Devise::find($deviseId);

            if (!$devise) {
                continue;
            }

            if ($deviseId === (int) $usdId) {
                // Déjà en USD
                $montantUsd = $montant;
                $usd += $montant;
            } else {
                // Convertir en USD via ExchangeRate (cascade)
                $montantUsd = $this->convertirEnUsd($montant, $devise->code);
                $cdf += $montant;
            }

            $totalUsd += $montantUsd;
            Log::info("Montant en USD : {$usd} ======= Montant en CDF : {$cdf} ");

            $details[] = [
                'devise'          => $devise->code,
                'montant_origine' => round($montant, 4),
                'montant_usd'     => round($montantUsd, 4),
            ];
        }

        return [
            'total_usd' => round($totalUsd, 4),
            'details'   => $details,
        ];
    }

    /**
     * Convertit un montant dans une devise donnée en USD.
     * Utilise la cascade ExchangeRate (caisse → succursale → global).
     *
     * @param float  $montant
     * @param string $deviseCode  Code ISO de la devise source (ex: CDF, EUR, USD)
     *
     * @return float  Montant converti en USD (0 si aucun taux trouvé)
     */
    private function convertirEnUsd(float $montant, string $deviseCode): float
    {
        $usdCode = 'USD';

        if ($deviseCode === $usdCode) {
            return $montant;
        }

        // Essayer la paire directe (devise -> USD)
        $tauxData = ExchangeRate::getTauxActif($deviseCode, $usdCode, null, null);
        if (!$tauxData) {
            Log::warning("Aucun taux trouvé pour la conversion {$deviseCode} -> USD");
            return 0.0;
        }

        $rate    = $tauxData['rate'];
        $sens    = $tauxData['sens'];
        $tauxReference = (float) $rate->taux_reference;

        $tauxAchat = $rate->getTauxAchat($sens);
        $tauxVente = $rate->getTauxVente($sens);

        if ($deviseCode !== $usdCode) {
            $tauxApplique = $tauxVente;
            $montantCible = round($montant / $tauxApplique, 4);
            Log::info("Conversion {$montant} {$deviseCode} -> USD avec taux vente {$tauxVente} = {$montantCible} USD");
        } else {
            $tauxApplique = $tauxAchat;
            $montantCible = round($montant * $tauxApplique, 4);
        }
        return $montantCible;
    }
    
        // ============================================================
    // VOLUME DE VENTES PAR PRODUIT
    // ============================================================

    /**
     * Récupère le volume de ventes par produit
     * Chaque produit avec le nombre de ventes et la somme totale des montants
     */
    public function volumeVentesParProduit(Request $request)
    {
        $user = auth()->user();
        
        $superAdminId = Role::getIdByCode('SUPER_ADMIN');
        $isSuperAdmin = ($user->role_id === $superAdminId);
        $succursaleId = $user->succursale_id ?? null;

        // Filtres de période
        $dateDebut = $request->input('date_debut', now()->startOfMonth()->toDateString());
        $dateFin = $request->input('date_fin', now()->endOfMonth()->toDateString());
        
        // Filtre par produit spécifique (optionnel)
        $produitId = $request->input('produit_id');
        
        // Limite de résultats
        $limit = $request->integer('limit', 50);
        
        // Tri
        $sortBy = $request->input('sort_by', 'quantite_vendue'); // quantite_vendue, montant_total, nom_produit
        $sortDirection = $request->input('sort_direction', 'desc');

        $query = DB::table('lignes_factures')
            ->join('factures', 'lignes_factures.facture_id', '=', 'factures.id')
            ->join('produit', 'lignes_factures.produit_id', '=', 'produit.id')
            ->select(
                'produit.id as produit_id',
                'produit.nom as nom_produit',
                'produit.code_barre',
                'produit.prix_vente as prix_unitaire_actuel',
                DB::raw('SUM(lignes_factures.quantite) as quantite_vendue'),
                DB::raw('COUNT(DISTINCT lignes_factures.facture_id) as nombre_ventes'),
                DB::raw('SUM(lignes_factures.total_ht) as montant_total_ht'),
                DB::raw('SUM(lignes_factures.total_ttc) as montant_total_ttc'),
                DB::raw('AVG(lignes_factures.prix_unitaire_ht) as prix_moyen_ht'),
                DB::raw('SUM(lignes_factures.remise_ligne) as total_remises')
            )
            ->whereIn('factures.statut', ['VALIDEE', 'PARTIELLE', 'PAYEE'])
            ->whereBetween('factures.date_facture', [$dateDebut, $dateFin . ' 23:59:59'])
            ->whereNull('factures.deleted_at'); // Exclure les factures supprimées

        // Filtre par succursale
        if (!$isSuperAdmin && $succursaleId) {
            $query->where('factures.succursale_id', $succursaleId);
        }

        // Filtre par produit spécifique
        if ($produitId) {
            $query->where('lignes_factures.produit_id', $produitId);
        }

        // Filtre par type de facture (VENTE uniquement par défaut, exclut AVOIR et DEVIS)
        $query->where('factures.type_facture', 'VENTE');

        // Groupement par produit
        $query->groupBy(
            'produit.id',
            'produit.nom',
            'produit.code_barre',
            'produit.prix_vente'
        );

        // Tri sécurisé
        $allowedSorts = [
            'quantite_vendue' => 'quantite_vendue',
            'montant_total' => 'montant_total_ttc',
            'nom_produit' => 'produit.nom',
            'nombre_ventes' => 'nombre_ventes'
        ];

        if (isset($allowedSorts[$sortBy])) {
            $sortDirection = in_array(strtolower($sortDirection), ['asc', 'desc']) 
                ? $sortDirection 
                : 'desc';
            $query->orderBy($allowedSorts[$sortBy], $sortDirection);
        } else {
            $query->orderByDesc('quantite_vendue');
        }

        $resultats = $query->limit($limit)->get();

        // Calculer les totaux généraux
        $totaux = [
            'quantite_totale_vendue' => $resultats->sum('quantite_vendue'),
            'montant_total_ht' => $resultats->sum('montant_total_ht'),
            'montant_total_ttc' => $resultats->sum('montant_total_ttc'),
            'nombre_produits_distincts' => $resultats->count(),
        ];

        // Formater les résultats
        $produits = $resultats->map(fn($item) => [
            'produit_id' => $item->produit_id,
            'nom_produit' => $item->nom_produit,
            'code_barre' => $item->code_barre,
            'prix_unitaire_actuel' => (float) $item->prix_unitaire_actuel,
            'quantite_vendue' => (int) $item->quantite_vendue,
            'nombre_ventes' => (int) $item->nombre_ventes,
            'montant_total_ht' => round((float) $item->montant_total_ht, 2),
            'montant_total_ttc' => round((float) $item->montant_total_ttc, 2),
            'prix_moyen_ht' => round((float) $item->prix_moyen_ht, 2),
            'total_remises' => round((float) $item->total_remises, 2),
        ])->toArray();

        return response()->json([
            'success' => true,
            'data' => [
                'periode' => [
                    'date_debut' => $dateDebut,
                    'date_fin' => $dateFin,
                ],
                'totaux' => [
                    'quantite_totale_vendue' => (int) $totaux['quantite_totale_vendue'],
                    'montant_total_ht' => round((float) $totaux['montant_total_ht'], 2),
                    'montant_total_ttc' => round((float) $totaux['montant_total_ttc'], 2),
                    'nombre_produits_distincts' => (int) $totaux['nombre_produits_distincts'],
                ],
                'produits' => $produits,
            ]
        ]);
    }
    
    // ============================================================
    // ALERTES DE STOCK
    // ============================================================

    /**
     * Récupère toutes les alertes de stock :
     *  - Produits en rupture
     *  - Produits en stock critique
     *  - Produits en stock faible
     *  - Lots expirés / expirant bientôt
     *  - Produits sans mouvement récent
     */
    public function alertesStock(Request $request)
    {
        $user = auth()->user();

        $superAdminId = Role::getIdByCode('SUPER_ADMIN');
        $isSuperAdmin = ($user->role_id === $superAdminId);
        $succursaleId = $user->succursale_id ?? null;

        // Filtres optionnels
        $typeAlerte   = $request->input('type'); // rupture, critique, faible, expire, tous
        $succursaleFiltre = $request->input('succursale_id');
        $limit        = $request->integer('limit', 20);
        $limit        = min(max($limit, 1), 100);

        // Déterminer les succursales accessibles
        $succursalesIds = $this->getSuccursalesAccessibles($isSuperAdmin, $succursaleId, $succursaleFiltre);

        // ========================================================
        // 1. ALERTES DE STOCK (Rupture / Critique / Faible)
        // ========================================================
        $alertesQuery = \App\Models\StockSuccursale::query()
            ->with([
                'produit:id,nom,code_barre,prix_vente,prix_achat,est_perissable',
                'succursale:id,nom'
            ])
            ->whereIn('succursale_id', $succursalesIds)
            ->whereIn('statut_alerte', ['RUPTURE', 'CRITIQUE', 'FAIBLE']);

        // Filtre par type d'alerte
        if ($typeAlerte && $typeAlerte !== 'tous') {
            $alertesQuery->where('statut_alerte', strtoupper($typeAlerte));
        }

        // Tri par gravité : RUPTURE > CRITIQUE > FAIBLE
        $alertesQuery->orderByRaw("
            CASE statut_alerte
                WHEN 'RUPTURE' THEN 1
                WHEN 'CRITIQUE' THEN 2
                WHEN 'FAIBLE' THEN 3
                ELSE 4
            END
        ")->orderBy('quantite_disponible', 'asc');

        $alertesStock = $alertesQuery->limit($limit)->get();

        // ========================================================
        // 2. LOTS EXPIRÉS / EXPIRANT BIENTÔT (via LotStock)
        // ========================================================
        $alertesLots = $this->getAlertesLots($succursalesIds, $limit);

        // ========================================================
        // 3. COMPTEURS GLOBAUX
        // ========================================================
        $compteurs = $this->getCompteursAlertes($succursalesIds);

        // ========================================================
        // 4. FORMATAGE
        // ========================================================
        return response()->json([
            'success' => true,
            'data' => [
                'compteurs' => $compteurs,
                'alertes_stock' => $alertesStock->map(function ($stock) {
                    return [
                        'stock_id'          => $stock->id,
                        'produit_id'        => $stock->produit_id,
                        'nom_produit'       => $stock->produit?->nom,
                        'code_barre'        => $stock->produit?->code_barre,
                        'succursale_id'     => $stock->succursale_id,
                        'succursale_nom'    => $stock->succursale?->nom,
                        'statut_alerte'     => $stock->statut_alerte,
                        'quantite_disponible' => (int) $stock->quantite_disponible,
                        'quantite_reserve'  => (int) $stock->quantite_reserve,
                        'quantite_minimale' => (int) $stock->quantite_minimale,
                        'quantite_maximale' => (int) $stock->quantite_maximale,
                        'quantite_manquante'=> (int) $stock->quantiteManquante(),
                        'pourcentage_couverture' => round($stock->pourcentageCouverture(), 2),
                        'est_en_rupture'    => $stock->estEnRupture(),
                        'est_critique'      => $stock->estCritique(),
                        'est_faible'        => $stock->estFaible(),
                        'jours_avant_rupture' => $stock->jours_avant_rupture,
                        'valeur_stock'      => round($stock->valeur_stock, 2),
                        'date_dernier_entree' => $stock->date_dernier_entree?->format('Y-m-d'),
                        'date_dernier_sortie' => $stock->date_dernier_sortie?->format('Y-m-d'),
                    ];
                })->toArray(),
                'alertes_lots' => $alertesLots,
            ]
        ]);
    }

    /**
     * Récupère les lots expirés ou expirant bientôt
     */
    private function getAlertesLots(array $succursalesIds, int $limit): array
    {
        $lotsQuery = \App\Models\LotStock::query()
            ->with([
                'produit:id,nom,code_barre,est_perissable',
                'stockSuccursale.succursale:id,nom'
            ])
            ->where('quantite_restante', '>', 0)
            ->whereNotNull('date_expiration')
            ->where('date_expiration', '<=', now()->addDays(30))
            ->whereHas('stockSuccursale', function ($q) use ($succursalesIds) {
                $q->whereIn('succursale_id', $succursalesIds);
            });

        $lots = $lotsQuery
            ->orderBy('date_expiration', 'asc')
            ->limit($limit)
            ->get();

        return $lots->map(function ($lot) {
            $jours = now()->startOfDay()->diffInDays($lot->date_expiration, false);

            if ($jours < 0) {
                $type = 'EXPIRE';
                $priorite = 'URGENTE';
            } elseif ($jours <= 7) {
                $type = 'CRITIQUE';
                $priorite = 'HAUTE';
            } else {
                $type = 'PROCHE';
                $priorite = 'NORMALE';
            }

            return [
                'lot_id'         => $lot->id,
                'numero_lot'     => $lot->numero_lot,
                'produit_id'     => $lot->produit_id,
                'nom_produit'    => $lot->produit?->nom,
                'code_barre'     => $lot->produit?->code_barre,
                'succursale_id'  => $lot->stockSuccursale?->succursale_id,
                'succursale_nom' => $lot->stockSuccursale?->succursale?->nom,
                'quantite_restante' => (int) $lot->quantite_restante,
                'date_expiration'   => $lot->date_expiration?->format('Y-m-d'),
                'jours_restants'    => $jours,
                'type'              => $type,
                'priorite'          => $priorite,
                'statut_lot'        => $lot->statut,
            ];
        })->toArray();
    }

    /**
     * Compteurs globaux des alertes stock
     */
    private function getCompteursAlertes(array $succursalesIds): array
    {
        // Compteurs par statut d'alerte
        $parStatut = \App\Models\StockSuccursale::query()
            ->whereIn('succursale_id', $succursalesIds)
            ->select('statut_alerte', DB::raw('count(*) as total'))
            ->groupBy('statut_alerte')
            ->pluck('total', 'statut_alerte')
            ->toArray();

        // Lots expirés
        $lotsExpires = \App\Models\LotStock::query()
            ->where('quantite_restante', '>', 0)
            ->whereNotNull('date_expiration')
            ->where('date_expiration', '<=', now())
            ->whereHas('stockSuccursale', function ($q) use ($succursalesIds) {
                $q->whereIn('succursale_id', $succursalesIds);
            })
            ->count();

        // Lots expirant dans 7 jours
        $lotsCritiques = \App\Models\LotStock::query()
            ->where('quantite_restante', '>', 0)
            ->whereNotNull('date_expiration')
            ->whereBetween('date_expiration', [now(), now()->addDays(7)])
            ->whereHas('stockSuccursale', function ($q) use ($succursalesIds) {
                $q->whereIn('succursale_id', $succursalesIds);
            })
            ->count();

        // Lots expirant dans 30 jours (hors 7 jours)
        $lotsProches = \App\Models\LotStock::query()
            ->where('quantite_restante', '>', 0)
            ->whereNotNull('date_expiration')
            ->whereBetween('date_expiration', [now()->addDays(7), now()->addDays(30)])
            ->whereHas('stockSuccursale', function ($q) use ($succursalesIds) {
                $q->whereIn('succursale_id', $succursalesIds);
            })
            ->count();

        $rupture  = (int) ($parStatut['RUPTURE'] ?? 0);
        $critique = (int) ($parStatut['CRITIQUE'] ?? 0);
        $faible   = (int) ($parStatut['FAIBLE'] ?? 0);

        return [
            'total_alertes' => $rupture + $critique + $faible,
            'stock' => [
                'rupture'  => $rupture,
                'critique' => $critique,
                'faible'   => $faible,
            ],
            'lots' => [
                'expires'    => $lotsExpires,
                'critiques'  => $lotsCritiques,
                'proches'    => $lotsProches,
                'total'      => $lotsExpires + $lotsCritiques + $lotsProches,
            ],
            'priorite_urgente' => $rupture + $lotsExpires,
        ];
    }

    /**
     * Détermine les IDs des succursales accessibles selon le rôle
     */
    private function getSuccursalesAccessibles(bool $isSuperAdmin, ?int $succursaleId, $succursaleFiltre = null): array
    {
        if ($isSuperAdmin || $user->hasPermission('manage-stock-agence')) {
            if ($succursaleFiltre) {
                return [(int) $succursaleFiltre];
            }
            return \App\Models\Succursale::pluck('id')->toArray();
        }

        if ($succursaleId) {
            return [$succursaleId];
        }

        return [];
    }


}