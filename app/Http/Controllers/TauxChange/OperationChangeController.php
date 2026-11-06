<?php

namespace App\Http\Controllers\TauxChange;

use App\Http\Controllers\Controller;
use App\Models\OperationChange;
use App\Models\Caisses;
use App\Models\Commission;
use App\Models\Devise;
use App\Models\Utilisateur;
use App\Models\ExchangeRate;
use App\Models\Transaction_Globale;
use App\Models\Seuil_Transactionnels;
use App\Models\Receipt;
use App\Services\ReceiptService;
use App\Services\EcritureService;
use App\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class OperationChangeController extends Controller
{
    
    // Constantes
    protected const PEUT_CHANGER = 'CHANGE';
    
           public function index(Request $request)
    {
        $user = auth()->user();
    
        $query = OperationChange::with([
            'deviseSource',
            'deviseCible',
            'caisse',
            'succursale',
            'client'
        ]);
    

        if ($user->isSuperAdmin() || $user->isChefOperation()||$user->isAuditeur()) {

        } elseif ($user->isChefAgence()) {
            $query->where('succursale_id', $user->succursale_id);
        } else {
            $query->where('operateur_id', $user->id);
        }
    
        /*
        ============================================================
        FILTRES
        ============================================================
        */
    
        // Seuls le SUPER_ADMIN et le CHEF_OPERATIONS peuvent filtrer
        // sur n'importe quelle succursale.
        if (
            $request->filled('succursale_id') &&
            ($user->isSuperAdmin() || $user->isChefOperation())
        ) {
            $query->where('succursale_id', $request->succursale_id);
        }
    
        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }
    
        if ($request->filled('date')) {
            $query->whereDate('date_operation', $request->date);
        }
    
        if ($request->filled('date_debut')) {
            $query->whereDate('date_operation', '>=', $request->date_debut);
        }
    
        if ($request->filled('date_fin')) {
            $query->whereDate('date_operation', '<=', $request->date_fin);
        }
    
        if ($request->filled('type')) {
            $query->where('type_operation', $request->type);
        }
    
        if ($request->filled('caisse_id')) {
            $query->where('caisse_id', $request->caisse_id);
        }
    
        if ($request->has('caisse_principale_id')) {
            $principale = Caisses::find($request->caisse_principale_id);
    
            if ($principale && $principale->isPrincipale()) {
                $ids = $principale->enfants()->pluck('id')->toArray();
    
                $query->whereIn('caisse_id', $ids);
            }
        }
    
        // Retour limité sans pagination
        if ($request->filled('limit')) {
            $operations = $query
                ->orderBy('created_at', 'desc')
                ->limit($request->integer('limit'))
                ->get();
    
            return response()->json([
                'success' => true,
                'data' => $operations,
            ]);
        }
    
        // Pagination par défaut
        $operations = $query
            ->orderBy('created_at', 'desc')
            ->paginate(10);
    
        return response()->json([
            'success' => true,
            'data' => $operations,
        ]);
    }

    public function store(Request $request)
    {
        $user= auth()->user(); 
        
        $validated = $request->validate([
            'succursale_id'    => 'required|integer|exists:succursales,id',
            'devise_source_id' => 'required|integer|exists:devise,id',
            'devise_cible_id'  => 'required|integer|exists:devise,id|different:devise_source_id',
            'montant_source'   => 'required|numeric|min:0',
            'caisse_id'        => 'required|integer|exists:caisses,id',
            // 'operateur_id'     => 'nullable|integer|exists:utilisateurs,id',
            'client_id'        => 'required|integer|exists:clients,id',
            'forcer'           => 'nullable|boolean', // Permet de forcer l'opération même si le seuil minimal est dépassé
            'taux_achat_force' => 'nullable|numeric|min:0',
            'taux_vente_force' => 'nullable|numeric|min:0',
            'motif_taux_force' => 'nullable|string|max:255|required_with:taux_achat_force,taux_vente_force',
        ]);
        
        // ── Codes devises ──────────────────────────────────────────────
        $sourceCode = Devise::find($validated['devise_source_id'])->code;
        $destCode = Devise::find($validated['devise_cible_id'])->code;
        $cdfCode = 'CDF';

        // ── 1. Déterminer la caisse opérationnelle (physique) ─────
        $caissePrincipale = null;
        $caisseOperationnelle = null;

        if ($validated['caisse_id']) {
            $caisseSelectionnee = Caisses::find($validated['caisse_id']);
            if (!$caisseSelectionnee) {
                return response()->json(['success' => false, 'message' => 'Caisse introuvable'], 404);
            }

            if ($caisseSelectionnee->isPrincipale()) {
                $caisseOperationnelle = $caisseSelectionnee->secondairePhysique();
                if (!$caisseOperationnelle) {
                    return response()->json(['success' => false, 'message' => "La caisse principale n'a pas de caisse secondaire physique ouverte."], 422);
                }
                $caissePrincipale = $caisseSelectionnee;
            } elseif ($caisseSelectionnee->isSecondaire()) {
                if ($caisseSelectionnee->type_caisse !== 'PHYSIQUE') {
                    return response()->json(['success' => false, 'message' => 'Un change nécessite une caisse physique.'], 422);
                }
                if ($caisseSelectionnee->statut != 'OUVERTE') {
                    return response()->json(['success' => false, 'message' => 'La caisse doit être ouverte pour effectuer un change.'], 422);
                }
                $caisseOperationnelle = $caisseSelectionnee;
                $caissePrincipale = $caisseSelectionnee->parent ?? $caisseSelectionnee;
            } else {
                return response()->json(['success' => false, 'message' => 'Type de caisse non autorisé pour un change.'], 422);
            }

            // Vérifier la permission CHANGE sur la caisse principale de référence
            if (!$caissePrincipale->peut('CHANGE')) {
                return response()->json(['success' => false, 'message' => 'Cette caisse n\'est pas autorisée à effectuer un change.'], 403);
            }
        }

        // ── 2. Détecter le seuil ─────────────────────────────────
        $roleId = $user->role_id;
        $deviseId = $validated['devise_source_id'];

        $seuil = Seuil_Transactionnels::detecter(
            'CHANGE',
            (float) $validated['montant_source'],
            $deviseId,
            $roleId
        );

        if (!$seuil) {
            return response()->json(['success' => false, 'message' => 'Aucun seuil configuré pour cette opération de change.'], 422);
        }

        // ── 3. Vérifier le solde de la caisse opérationnelle ─────
        if ($caisseOperationnelle) {
            $soldeCible = $caisseOperationnelle->getSolde($validated['devise_cible_id']);
            if ($soldeCible <= 0) {
                return response()->json(['success' => false, 'message' => 'Solde insuffisant dans la caisse pour la devise cible.'], 422);
            }
        }

        // ── 4. BRANCHE TAUX FORCÉ → soumission pour validation ──
        if (!empty($validated['taux_achat_force']) || !empty($validated['taux_vente_force'])) {
            $tauxData = ExchangeRate::getTauxActif($sourceCode, $destCode, $validated['caisse_id'] ?? null, $validated['succursale_id'] ?? null );
            if (!$tauxData) {
                return response()->json(['success' => false, 'message' => "Aucun taux de référence trouvé pour la paire {$sourceCode}/{$destCode}."], 422);
            }
            $exchangeRate = $tauxData['rate'];
            $niveau = $tauxData['niveau']; // 'caisse', 'succursale' ou 'global'
            $sens = $tauxData['sens'];

            $tauxReference = (float) $exchangeRate->taux_reference;


            $tauxAchat = $exchangeRate->getTauxAchat($sens);
            $tauxVente = $exchangeRate->getTauxVente($sens);

            // Déterminer le type d'opération selon la devise source
            if ($sourceCode === $cdfCode) {
                // Client achète la devise cible (ex: USD)
                $typeOperation = 'ACHAT';
                $tauxApplique = (float) $validated['taux_vente_force'];
                $montantCible = round($validated['montant_source'] / $tauxApplique, 4);
                $commission = round(($tauxApplique - $tauxReference) * $montantCible, 4);
            } else {
                // Client vend la devise source (ex: USD)
                $typeOperation = 'VENTE';
                $tauxApplique = (float) $validated['taux_achat_force'];
                $montantCible = round($validated['montant_source'] * $tauxApplique, 4);
                $commission = round(($tauxReference - $tauxApplique) * $validated['montant_source'], 4);
            }

            // ---- Récupérer l'ID du taux global correspondant pour exchange_rate_id ----
            $globalRate = ExchangeRate::where('devise_source', $sourceCode)
                ->where('devise_destination', $destCode)
                ->where('actif', true)
                ->orderBy('date_effet', 'desc')
                ->first();
            if (!$globalRate) {
                // Essayer l'inverse
                $globalRate = ExchangeRate::where('devise_source', $destCode)
                    ->where('devise_destination', $sourceCode)
                    ->where('actif', true)
                    ->orderBy('date_effet', 'desc')
                    ->first();
            }
            $exchangeRateId = $globalRate ? $globalRate->id : null;
        
            // ---- Préparer les IDs des taux personnalisés pour traçabilité ----
            $caisseExchangeRateId = null;
            $succursaleExchangeRateId = null;
            if ($niveau === 'caisse') {
                $caisseExchangeRateId = $exchangeRate->id;
            } elseif ($niveau === 'succursale') {
                $succursaleExchangeRateId = $exchangeRate->id;
            }
        
            $operation = OperationChange::create([
                'reference'        => OperationChange::genererReference(),
                'type_operation'   => $typeOperation,
                'succursale_id'    => $validated['succursale_id'],
                'caisse_id'        => $caisseOperationnelle->id,
                'devise_source_id' => $validated['devise_source_id'],
                'devise_cible_id'  => $validated['devise_cible_id'],
                'montant_source'   => $validated['montant_source'],
                'montant_cible'    => $montantCible,
                'taux_achat'       => ($sourceCode === $cdfCode) ? null : $tauxApplique,
                'taux_vente'       => ($sourceCode === $cdfCode) ? $tauxApplique : null,
                'taux_reference'   => $tauxReference,
                'spread_achat'     => $tauxAchat,
                'spread_vente'     => $tauxVente,
                'commission'       => $commission,
                'exchange_rate_id' => $exchangeRateId, //  toujours un ID de exchange_rates
                'caisse_exchange_rate_id'   => $caisseExchangeRateId,
                'succursale_exchange_rate_id' => $succursaleExchangeRateId,
                'niveau_alerte'    => 'NORMAL',
                'operateur_id'     => $user->id,
                'statut'           => 'EN_ATTENTE_VALIDATION',
                'date_operation'   => now()->format('Y-m-d'),
                'client_id'        => $validated['client_id'] ?? null,
                'taux_achat_force' => $validated['taux_achat_force'] ?? null, 
                'taux_vente_force' => $validated['taux_vente_force'] ?? null,
                'motif_taux_force' => $validated['motif_taux_force'] ?? null,
                'sens'             => $sens ?? null,
                'id_creator'       => $user->id,
            ]);
            
            // ───────────  Écriture comptable ───────────
            $deviseCible = Devise::getCodeById($validated['devise_cible_id']);
            $deviseSource = Devise::getCodeById($validated['devise_source_id']);
            
            // Recuperer juste le prénom du user
            $nomUser = auth()->user()->nom_utilisateur;
            $nomUser = Utilisateur::extrairePrenom($nomUser);
            
            $ecriture = new EcritureService();

                Log::info('Change_Fonction Effectuer', [
                    'montant' => ($deviseCible === 'CDF') ? $validated['montant_source'] : $montantCible,
                    'dviseCible' => $deviseCible,
                    'deviseSource' => $deviseSource,
                    'reference' => $operation->reference,
                    'commission' => $commission,
                ]);

                $ecriture->creerEcritureSimple([
                    'montant' => ($deviseCible === 'CDF') ? $validated['montant_source'] : $montantCible,
                    'code' => 'FIT',
                    'entite_id' => 2,
                    'operation_libelle' => 'Change ' .$deviseSource. ' - ' .$deviseCible,
                    'libelle' =>'Change de ' .$validated['montant_source']. ' ' .$deviseSource. ' montant remis ' .$montantCible. ' ' .$deviseCible,
                    'date_ecriture' => now()->format('Y-m-d'),
                    'reference' => $operation->reference
                ]);

                Log::info('Operation de change effectuer avec succès ×× écriture incluse');

            return response()->json([
                'success' => true,
                'message' => 'Opération soumise pour validation superviseur (taux forcé).',
                'data'    => $operation,
            ], 201);
        }

        // ── 4.1 NIVEAU CRITIQUE D'ALERTE → soumission pour validation ──

        $alerte = ($seuil->niveau_alerte === 'CRITIQUE');
        if ($alerte) {
            $tauxData = ExchangeRate::getTauxActif($sourceCode, $destCode, $validated['caisse_id'] ?? null, $validated['succursale_id'] ?? null);
            if (!$tauxData) {
                return response()->json(['success' => false, 'message' => "Aucun taux de référence trouvé pour la paire {$sourceCode}/{$destCode}."], 422);
            }
            $exchangeRate = $tauxData['rate'];
            $sens = $tauxData['sens'];
            $tauxReference = (float) $exchangeRate->taux_reference;

            $tauxAchat = $exchangeRate->getTauxAchat($sens);
            $tauxVente = $exchangeRate->getTauxVente($sens);
            
            // ---- Récupérer l'ID du taux global correspondant pour exchange_rate_id ----
            $globalRate = ExchangeRate::where('devise_source', $sourceCode)
                ->where('devise_destination', $destCode)
                ->where('actif', true)
                ->orderBy('date_effet', 'desc')
                ->first();
            if (!$globalRate) {
                // Essayer l'inverse
                $globalRate = ExchangeRate::where('devise_source', $destCode)
                    ->where('devise_destination', $sourceCode)
                    ->where('actif', true)
                    ->orderBy('date_effet', 'desc')
                    ->first();
            }
            $exchangeRateId = $globalRate ? $globalRate->id : null;
        
            // ---- Préparer les IDs des taux personnalisés pour traçabilité ----
            $caisseExchangeRateId = null;
            $succursaleExchangeRateId = null;
            if ($niveau === 'caisse') {
                $caisseExchangeRateId = $exchangeRate->id;
            } elseif ($niveau === 'succursale') {
                $succursaleExchangeRateId = $exchangeRate->id;
            }

            // On stocke les données de taux pour la validation ultérieure
            // On détermine le type d'opération pour cohérence
            $typeOperation = ($sourceCode === $cdfCode) ? 'ACHAT' : 'VENTE';

            $operation = OperationChange::create([
                'reference'        => OperationChange::genererReference(),
                'type_operation'   => $typeOperation,
                'succursale_id'    => $validated['succursale_id'],
                'caisse_id'        => $caisseOperationnelle->id,
                'devise_source_id' => $validated['devise_source_id'],
                'devise_cible_id'  => $validated['devise_cible_id'],
                'montant_source'   => $validated['montant_source'],
                'montant_cible'    => 0, // sera calculé à la validation
                'taux_achat'       => 0,
                'taux_vente'       => 0,
                'taux_reference'   => $tauxReference,
                'spread_achat'     => $tauxAchat,
                'spread_vente'     => $tauxVente,
                'commission'       => 0,
                'exchange_rate_id' => $exchangeRateId, //  toujours un ID de exchange_rates
                'caisse_exchange_rate_id'   => $caisseExchangeRateId,
                'succursale_exchange_rate_id' => $succursaleExchangeRateId,
                'niveau_alerte'    => $seuil->niveau_alerte,
                'operateur_id'     => $validated['operateur_id'] ?? null,
                'statut'           => 'EN_ATTENTE_VALIDATION',
                'date_operation'   => now()->format('Y-m-d'),
                'client_id'        => $validated['client_id'] ?? null,
                'operateur_id'     => $user->id,
                'sens'             => $sens,
                'id_creator'       => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Opération soumise pour validation superviseur (seuil critique).',
                'data'    => $operation,
            ], 201);    
        }

        // ── 5. EXÉCUTION DIRECTE (sans taux forcé) ──────────────
        // Toute l'opération sera dans une transaction pour assurer l'atomicité

        try {
            DB::beginTransaction();

            $resultat = OperationChange::effectuer(
                $validated['succursale_id'],
                $validated['devise_source_id'],
                $validated['devise_cible_id'],
                (float) $validated['montant_source'],
                $validated['caisse_id'],
                $validated['operateur_id'] ?? null,
                false, // inclureDansTransfert
                (bool) ($validated['forcer'] ?? false),
                true, // risqueDoubleEnregistrement
                $validated['client_id'],
                null, // tauxAchatForce
                null, // tauxVenteForce
                null, // motifTauxForce
                $seuil->niveau_alerte,
                $user->id
            );

            if (isset($resultat['avertissement'])) {
                DB::rollBack();
                return response()->json([
                    'success'       => false,
                    'avertissement' => true,
                    'message'       => $resultat['message'],
                    'data'          => [
                        'solde_actuel'   => $resultat['solde_actuel'],
                        'montant_requis' => $resultat['montant_requis'],
                        'solde_minimal'  => $resultat['solde_minimal'],
                        'solde_apres'    => $resultat['solde_apres'],
                        'action'         => 'Renvoyer la requête avec force: true pour confirmer',
                    ],
                ], 200);
            }

            if (!$resultat || isset($resultat['erreur'])) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => $resultat['erreur'] ?? 'Aucun taux disponible pour ces devises',
                ], 422);
            }

            $operation = $resultat['operation'];

            // Débiter/créditer la caisse opérationnelle
            if ($caisseOperationnelle) {
                if (!$caisseOperationnelle->debiter(
                    (float) $operation->montant_cible,
                    $validated['devise_cible_id'],
                    'Change ' . $operation->reference,
                    $operation->reference,
                    $validated['operateur_id'] ?? null,
                    'CHANGE'
                )) {
                    $operation->annuler();
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Solde insuffisant dans la caisse pour remettre la devise cible'], 422);
                }

                if (!$caisseOperationnelle->crediter(
                    (float) $operation->montant_source,
                    $validated['devise_source_id'],
                    'Change ' . $operation->reference,
                    $operation->reference,
                    $validated['operateur_id'] ?? null,
                    'CHANGE'
                )) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Échec du crédit de la caisse'], 422);
                }
            }

            // Créer la transaction globale
            $transactionGlobale = Transaction_Globale::create([
                'reference'            => Transaction_Globale::genererReference(),
                'type'                 => 'CHANGE',
                'montant_local'        => $validated['montant_source'],
                'devise_id'            => $validated['devise_source_id'],
                'date_transaction'     => now()->format('Y-m-d'),
                'statut'               => 'COMPLETEE',
                'commission_starehe'   => 0,
                'id_succursale'        => $validated['succursale_id'],
                'caisse_id'            => $caisseOperationnelle->id,
                'operation_change_id'  => $operation->id,
                'id_seuil_transaction' => $seuil->id,
                'montant_devise_pivot' => $operation->montant_cible,
            ]);

            // Créer la commission
            $commission = Commission::creerCommissionChange($transactionGlobale);
            if ($commission) {
                $transactionGlobale->commission_starehe = $resultat['commission'];
                $transactionGlobale->save();
            }

            // ─── Génération du reçu ──────────────────────────────────
            $receiptService = new ReceiptService();
            $receipt = $receiptService->generate($operation);

            // Si la génération du reçu échoue, une exception sera levée et le rollback se fera automatiquement
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Opération de change effectuée avec succès',
                'data'    => [
                    'operation'           => $operation,
                    'transaction_globale' => $transactionGlobale,
                    'commission'          => $commission,
                    'taux_achat'          => $resultat['taux_achat'],
                    'taux_vente'          => $resultat['taux_vente'],
                    'niveau_alerte'       => $seuil->niveau_alerte,
                    'receipt' => [
                        'reference' => $receipt->reference,
                        'pdf_url'   => asset('storage/' . $receipt->pdf_path),
                    ],
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'opération : ' . $e->getMessage(),
            ], 422);
        }
    }

    public function show($id)
    {
        if (!ctype_digit(strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null,
            ], 400);
        }

        $operation = OperationChange::with([
            'deviseSource',
            'deviseCible',
            'succursale',
            'caisse',
            'operateur',
        ])->find($id);

        if (!$operation) {
            return response()->json([
                'success' => false,
                'message' => 'Opération introuvable',
                'data'    => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Détails de l\'opération de change',
            'data'    => $operation,
        ], 200);
    }

    public function annuler(Request $request, $id)
    {
        $validated = $request->validate([
            'motif' => 'nullable|string|max:500',
        ]);

        $operation = OperationChange::findOrFail($id);
        $user = auth()->user();

        // Vérifier les droits (superviseur/admin)
        if (!$user || !in_array($user->role_id, [1, 2])) {
            return response()->json(['success' => false, 'message' => 'Seul un superviseur peut annuler une opération.'], 403);
        }

        try {
            // 1. Annuler l'opération (restauration des soldes, commission, etc.)
            $operation->annuler($user->id, $validated['motif'] ?? null);

            // 2. Gérer le reçu associé : générer un reçu de contrepassation
            $receipt = Receipt::where('operation_change_id', $operation->id)->first();
            if ($receipt) {
                $service = new ReceiptService();
                $counterReceipt = $service->cancel($receipt, $validated['motif'] ?? 'Annulation de l\'opération');
            } else {
                $counterReceipt = null;
            }

            return response()->json([
                'success' => true,
                'message' => 'Opération de change annulée avec succès.',
                'data'    => [
                    'operation' => $operation->fresh(['deviseSource', 'deviseCible', 'caisse']),
                    'receipt_contrepassation' => $counterReceipt,
                ],
            ]);
        } catch (BusinessException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de l\'annulation : ' . $e->getMessage()], 500);
        }
    }

    public function simuler(Request $request)
    {
        $validated = $request->validate([
            'devise_source_id' => 'required|integer|exists:devise,id',
            'devise_cible_id'  => 'required|integer|exists:devise,id',
            'montant_source'   => 'required|numeric|min:0',
            'succursale_id'    => 'required|integer|exists:succursales,id',
            'caisse_id'        => 'required|integer|exists:caisses,id',
        ]);

 Log::info('==================================');
 Log::info('==================================');
 Log::info('==================================');
 Log::info('==================================');
 Log::info('==================================');
 Log::info('==================================');
 Log::info('==================================');
 Log::info('==================================');
 Log::info('==================================');
 Log::info('==================================');
 Log::info($validated['succursale_id']);
 Log::info('==================================');
 Log::info('==================================');
 Log::info('==================================');
 Log::info('==================================');
 Log::info('==================================');
 Log::info('==================================');
 Log::info('==================================');
 Log::info('==================================');
 Log::info('==================================');

        $sourceCode = Devise::find($validated['devise_source_id'])?->code;
        $destCode = Devise::find($validated['devise_cible_id'])?->code;
        $cdfCode = 'CDF';
        
        // Récupérer le taux actif (direct ou inverse)
        $tauxData = ExchangeRate::getTauxActif($sourceCode, $destCode, $validated['caisse_id'] ?? null, $validated['succursale_id'] ?? null);
        if (!$tauxData) {
            return response()->json([
                'success' => false,
                'message' => "Aucun taux de référence trouvé pour la paire {$sourceCode}/{$destCode}."
            ], 404);
        }
        
        $exchangeRate = $tauxData['rate'];
        $sensTaux = $tauxData['sens']; // 'direct' ou 'inverse'

        $tauxReference = (float) $exchangeRate->taux_reference;

        $tauxAchat = $exchangeRate->getTauxAchat($sensTaux);
        $tauxVente = $exchangeRate->getTauxVente($sensTaux);

Log::info('tauxAchat: ' . $tauxAchat . ', tauxVente: ' . $tauxVente . ', sens: ' . $sensTaux);

        $montantSource = (float) $validated['montant_source'];

        // Déterminer le type d'opération : si source = CDF => achat, sinon vente
        if ($sourceCode === $cdfCode) {
            // Client achète la devise cible (ex: USD) -> on utilise le taux de vente
            $tauxApplique = $tauxVente;
            $montantCible = round($montantSource / $tauxApplique, 4);
            $commission = round(($tauxVente - $tauxReference) * $montantSource, 4);
            Log::info('montant cible : ' .$montantCible);
        } else {
            // Client vend la devise source (ex: USD) -> on utilise le taux d'achat
            $tauxApplique = $tauxAchat;
            $montantCible = round($montantSource * $tauxApplique, 4);
            $commission = round(($tauxReference - $tauxAchat) * $montantSource, 4);
            Log::info('montant cible : ' .$montantCible);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'montant_source'   => $montantSource,
                'montant_cible'    => $montantCible,
                'taux_utilise'     => $tauxApplique,
                'taux_reference'   => $tauxReference,
                'spread_achat'     => $tauxAchat, // pour information
                'spread_vente'     => $tauxVente, // pour information
                'commission'       => $commission,
                'type_operation'   => ($sourceCode === $cdfCode) ? 'ACHAT' : 'VENTE',
                'sens_taux'        => $sensTaux,
                'devise_source_id' => $validated['devise_source_id'],
                'devise_cible_id'  => $validated['devise_cible_id'],
            ]
        ], 200);
    }

    // ===========================================================
    // Permet de valider une opération de change avec taux forcés 
    // ===========================================================

    public function valider(Request $request, $id)
    {
        $validated = $request->validate([
            'utilisateur_id' => 'required|integer|exists:utilisateurs,id',
            'decision'       => 'required|in:VALIDER,REJETER',
            'notes'          => 'nullable|string',
        ]);
    
        $operation = OperationChange::findOrFail($id);
        if ($operation->statut !== 'EN_ATTENTE_VALIDATION') {
            return response()->json(['success' => false, 'message' => 'Opération non en attente de validation.'], 422);
        }
    
        if ($validated['decision'] === 'REJETER') {
            $operation->update([
                'statut'          => 'ANNULEE',
                'valide_par'      => $validated['utilisateur_id'],
                'date_validation' => now(),
            ]);
            return response()->json(['success' => true, 'message' => 'Opération rejetée.']);
        }
        
        // Vérifier le seuil
        
        $user = auth()->user();
        $roleId = $user->role_id;
        $deviseId = $operation->devise_source_id;

        $seuil = Seuil_Transactionnels::detecter(
            'CHANGE',
            (float) $operation->montant_source,
            $deviseId,
            $roleId
        );

        if (!$seuil || $seuil->niveau_alerte === 'CRITIQUE') {
            return response()->json(['success' => false, 'message' => 'Le montant dépasse le seuil autorisé pour votre rôle ou seuil non configuré.'], 422);
        }
    
        // VALIDER : exécuter le change avec les taux forcés
        $resultat = OperationChange::effectuer(
            $operation->succursale_id,
            $operation->devise_source_id,
            $operation->devise_cible_id,
            (float) $operation->montant_source,
            $operation->caisse_id,
            $operation->operateur_id,
            false,
            true, // forcer le seuil si nécessaire
            false,
            $operation->client_id,
            $operation->taux_achat_force,
            $operation->taux_vente_force,
            $operation->motif_taux_force,
            null,
            $user->id
        );
    
        if (isset($resultat['erreur'])) {
            return response()->json(['success' => false, 'message' => $resultat['erreur']], 422);
        }
    
        // Utiliser une transaction DB
        DB::beginTransaction();
        try {
            // Mettre à jour l'opération avec les résultats
            $operation->update([
                'montant_cible'    => $resultat['operation']->montant_cible,
                'taux_achat'       => $resultat['taux_achat'],
                'taux_vente'       => $resultat['taux_vente'],
                'commission'       => $resultat['commission'],
                'statut'           => 'COMPLETEE',
                'valide_par'       => $validated['utilisateur_id'],
                'date_validation'  => now(),
            ]);
    
            // Créer la transaction globale et la commission
            
            $transactionGlobale = Transaction_Globale::create([
                'reference'            => Transaction_Globale::genererReference(),
                'type'                 => 'CHANGE',
                'montant_local'        => $operation->montant_source,
                'devise_id'            => $operation->devise_source_id,
                'date_transaction'     => now()->format('Y-m-d'),
                'statut'               => 'COMPLETEE',
                'commission_starehe'   => 0,
                'id_succursale'        => $operation->succursale_id,
                'caisse_id'            => $operation->caisse_id,
                'operation_change_id'  => $operation->id,
                'id_seuil_transaction' => $seuil->id,
                'montant_devise_pivot' => $operation->montant_cible,
            ]);
    
            Commission::creerCommissionChange($transactionGlobale);

/*
            // Écriture comptable ────────────────────────────

            $dollar = Devise::getCodeById($operation->devise_source_id);
            $tauxAchat = null;
            $tauxVente = null;
            $montantCible = null;

            if ($dollar !== 'USD') {

                $idCDF = Devise::getIdByCode('USD');

                [$tauxAchat, $tauxVente, $sens] = OperationChange::getTauxApplicables(
                    $operation->devise_source_id, $idCDF, $operation->caisse_id, $operation->succursale_id,
                    $operation->taux_achat_force, $operation->taux_vente_force
                );

                if ($tauxAchat == 0 || $tauxVente == 0 || $sens === null) {
                    return ['erreur' => 'Taux de change introuvable pour cette paire.'];
                }

                // 2. Calcul du montant en dollar
                if ($sens === 'direct') {
                    $montantCible = round($operation->montant_source / $tauxVente, 4);
                } else { // inverse
                    $montantCible = round($operation->montant_source * $tauxAchat, 4);
                }
            }

            $typeOperation = $operation->type_operation;
            $ecriture = new EcritureService();

            if ($typeOperation === 'ACHAT') {
                Log::info('Opération CHANGE '.$typeOperation, [
                    'montant' => $montantCible,
                    'operateur' => $typeOperation,
                    'reference' => $operation->reference,
                    'commission' => $resultat['commission'],
                ]);

                $ecriture->creerEcritureSimple([
                    'montant' => $montantCible,
                    'code' => 'CHANGE',
                    'entite_id' => 1, // TODO: l'ID de l'entité change à revoir.
                    'operation_libelle' => 'Change achat - ', // TODO: libelle à revoir.
                    'date_ecriture' => now()->format('Y-m-d'),
                    'reference' => $operation->reference,
                    'auto_overrides' => [
                        [
                            'libelle' => 'Commission achat ',
                            'use_default' => false,
                            'valeur' => $operation->commission . ' de commission',
                        ]
                    ]
                ]);

                Log::info('Écriture CHANGE ACHAT créée avec succès', ['reference' => $resultat['operation']->reference]);
            } else {
                Log::info('Opération CHANGE '.$typeOperation, [
                    'montant' => $montantCible,
                    'operateur' => $typeOperation,
                    'reference' => $operation->reference,
                    'commission' => $resultat['commission'],
                ]);

                $ecriture->creerEcritureSimple([
                    'montant' => $montantCible,
                    'code' => 'CHANGE',
                    'entite_id' => 2, // TODO: l'ID de l'entité change à revoir.
                    'operation_libelle' => 'Change vente - ', // TODO: libelle à revoir.
                    'date_ecriture' => now()->format('Y-m-d'),
                    'reference' => $operation->reference,
                    'auto_overrides' => [
                        [
                            'libelle' => 'Commission vente ',
                            'use_default' => false,
                            'valeur' => $operation->commission . ' de commission',
                        ]
                    ]
                ]);

                Log::info('Écriture CHANGE VENTE créée avec succès', ['reference' => $resultat['operation']->reference]);
            }
*/
            // Génération du reçu
            $receiptService = new ReceiptService();
            $receipt = $receiptService->generate($operation);
    
            // Si tout s'est bien passé, on valide la transaction
            DB::commit();
    
            return response()->json([
                'success' => true,
                'message' => 'Opération validée et exécutée.',
                'data'    => [
                    'operation' => $operation->load(['deviseSource', 'deviseCible']),
                    'receipt' => $receipt,
                ],
            ]);
    
        } catch (\Exception $e) {
            DB::rollBack();
            // Log de l'erreur
            \Log::error('Erreur validation opération change', [
                'operation_id' => $operation->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la validation: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ===================================================================
    // Permet de lister les opérations de change en attente de validation 
    // ===================================================================

    public function enAttente()
    {
        $operations = OperationChange::with(['deviseSource', 'deviseCible', 'caisse', 'operateur'])
            ->enAttenteValidation()
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true, 
            'data' => $operations
        ]);
    }

}