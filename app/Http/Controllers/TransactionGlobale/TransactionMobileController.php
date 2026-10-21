<?php

namespace App\Http\Controllers\TransactionGlobale;

use App\Http\Controllers\Controller;
use App\Models\Transaction_Mobile;
use App\Models\Transaction_Globale;
use App\Models\Operateur_Mobile;
use Illuminate\Http\Request;
use App\Models\OperationChange;
use App\Models\Caisses;
use App\Models\Utilisateur;
use App\Models\Seuil_Transactionnels;
use App\Models\SoldeCaisse;
use App\Services\KeccelSmsService;

class TransactionMobileController extends Controller
{
    // Constantes
    protected Const PEUT_TRANSFERER_MOBILE= 'TRANS_MM';

        public function index(Request $request)
    {
        $user = auth()->user();
    
        $query = Transaction_Mobile::with([
                'transactionGlobale.devise',
                'operateur'
            ])
            ->whereNotNull('id_operateur_mobile')
            ->orderBy('created_at', 'desc');
    
    
        if ($user->isSuperAdmin() || $user->isChefOperation()) {
    
            // Accès complet
    
        } else {
    
            $query->whereHas('transactionGlobale', function ($q) use ($user) {
                $q->where('id_succursale', $user->succursale_id);
            });
    
        }
    
    
        /*
        ============================================================
        FILTRES MÉTIER
        ============================================================
        */
    
        if ($request->has('id_operateur_mobile')) {
            $query->parOperateur($request->id_operateur_mobile);
        }
    
    
        if ($request->has('statut_transaction')) {
            $query->parStatut($request->statut_transaction);
        }
    
    
        if ($request->has('numero_client')) {
            $query->where(
                'numero_client',
                'like',
                '%' . $request->numero_client . '%'
            );
        }
    
    
        /*
        ============================================================
        FILTRE SUCCURSALE
        ============================================================
        Disponible uniquement pour SUPER_ADMIN et CHEF_OPERATIONS
        ============================================================
        */
        if (
            $request->filled('id_succursale') &&
            ($user->isSuperAdmin() || $user->isChefOperation())
        ) {
            $query->whereHas('transactionGlobale', function ($q) use ($request) {
                $q->where('id_succursale', $request->id_succursale);
            });
        }
    
    
        $transactions = $query->paginate(10);
    
    
        return response()->json([
            'success' => true,
            'message' => 'Liste des transactions mobile',
            'data'    => $transactions,
        ], 200);
    }

    public function store(Request $request)
    {
        $user = auth()->user();
        $validated = $request->validate([
            'id_operateur_mobile'   => 'required|integer|exists:operateur_mobile,id',
            'reference_transaction' => 'nullable|string|max:150',
            'numero_client'         => 'required|string|max:150',
            'nom_client'            => 'required|string|max:250',
            'type_operation'        => 'required|in:DEPOT,RETRAIT',
            'montant'               => 'required|numeric|min:0',
            'devise_id'             => 'required|integer|exists:devise,id',
            'id_succursale'         => 'required|integer|exists:succursale,id',
            'caisse_principale_id'  => 'required|integer|exists:caisses,id',
            'caissier_id'           => 'required|integer|exists:utilisateurs,id',
            'inclure_change'        => 'nullable|boolean',
            'devise_change_id'      => 'required_if:inclure_change,true|integer|exists:devise,id',
            'force'                 => 'nullable|boolean'
        ]);

        // --- Déterminer les caisses opérationnelles ---
        $caissePhysique = null;
        $caisseMobile   = null;

        $principale = Caisses::find($validated['caisse_principale_id']);
        if (!$principale) {
            return response()->json(['success' => false, 'message' => 'Caisse principale introuvable.'], 404);
        }

        if ($principale->statut != 'OUVERTE') {
            return response()->json(['success' => false, 'message' => 'La caisse principale doit être ouverte.'], 422);
        }

        if (!$principale->peut(self::PEUT_TRANSFERER_MOBILE)) {
            return response()->json(['success' => false, 'message' => 'Cette caisse n\'est pas autorisée pour les transactions mobile money.'], 403);
        }

        $caissePhysique = $principale->secondairePhysique();
        if (!$caissePhysique) {
            return response()->json(['success' => false, 'message' => 'Aucune caisse secondaire physique trouvée.'], 422);
        }

        $operateur = Operateur_Mobile::find($validated['id_operateur_mobile']);
        if (!$operateur) {
            return response()->json(['success' => false, 'message' => 'Opérateur mobile introuvable.'], 404);
        }
        if (empty($operateur->service_mobile_money_id)) {
            return response()->json(['success' => false, 'message' => 'L\'opérateur n\'est pas associé à un service mobile money.'], 422);
        }

        $caisseMobile = $principale->secondaireMobileMoney($operateur->service_mobile_money_id);
        if (!$caisseMobile) {
            return response()->json(['success' => false, 'message' => 'Aucune caisse secondaire mobile money trouvée pour cet opérateur.'], 422);
        }

        // Vérifier que la caisse mobile est bien ouverte
        if ($caisseMobile->statut != 'OUVERTE') {
            return response()->json(['success' => false, 'message' => 'La caisse mobile money doit être ouverte.'], 422);
        }

        // Détecter le seuil
        $roleId = $user->role_id;
        $deviseId = $validated['devise_id'];

        $seuil = Seuil_Transactionnels::detecter(
            'CHANGE',
            (float) $validated['montant'],
            $deviseId,
            $roleId
        );

        if (!$seuil) {
            return response()->json(['success' => false, 'message' => 'Aucun seuil configuré pour ce type de transaction.'], 422);
        }


        if ($seuil->niveau_alerte === 'CRITIQUE') {
            return response()->json([
                'success' => false,
                'message' => 'Le montant dépasse le seuil  de transaction mobile autorisé.',
                'data'    => [
                    'seuil' => $seuil,
                ],
            ], 422);
        }
/*
        // Calcul de la commission opérateur
        if ($validated['type_operation'] === 'ENVOI') {
            $calcul = Transaction_Mobile::calculer(
                (float) $validated['montant'],
                (float) ($operateur->commission_defaut ?? 0)
            );
        } else {
            // Pour un retrait, pas de commission opérateur normalement
            $calcul = [
                'commission_operateur' => 0,
                'montant_net'          => (float) $validated['montant']
            ];
        }
*/
        // Créer la transaction globale
        $transactionGlobale = Transaction_Globale::create([
            'reference'            => Transaction_Globale::genererReference(),
            'type'                 => $validated['type_operation'] === 'DEPOT' ? 'MOBILE_MONEY' : 'MOBILE_MONEY_RETRAIT',
            'montant_local'        => $validated['montant'],
            'devise_id'            => $validated['devise_id'],
            'date_transaction'     => now()->format('Y-m-d'),
            'statut'               => 'COMPLETEE',  // seront mis à jour si retrait
            'commission_starehe'   => 0,
            'id_succursale'        => $validated['id_succursale'],
            'id_creator'            => auth()->id(),
            'caisse_id'            => $caissePhysique->id,
            'caisse_mobile_id'     => $caisseMobile->id,
            'id_seuil_transaction' => $seuil->id,
            'montant_devise_pivot' => $validated['montant'],
        ]);

        // Change sur montant_net si demandé
        if ($validated['inclure_change'] ?? false) {
            $change = OperationChange::effectuer(
                $validated['id_succursale'],
                $validated['devise_id'],
                $validated['devise_change_id'],
                (float) $validated['montant'], // $calcul['montant_net'],
                $caissePhysique->id,
                $validated['caissier_id'] ?? null,
                true, false, true
            );
        

            if (!$change) {
                // Supprimer la transaction globale si le change échoue
                $transactionGlobale->delete();
                return response()->json(['success' => false, 'message' => 'Aucun taux de change disponible.'], 422);
            }

            $transactionGlobale->operation_change_id  = $change['operation']->id;
            $transactionGlobale->montant_devise_pivot = $change['operation']->montant_cible;
            $transactionGlobale->save();
        }
        // Récuperation du montant a débiter si jamais il y a change
        $montantChange = isset($validated['inclure_change']) ? $change['montant_cible'] : $validated['montant'];
        $commissionChange = isset($validated['inclure_change']) ? $change['commission'] : 0;
/*
        // Commission
        $commission = Commission::creerPourTransaction($transactionGlobale, 'PAYEE');
*/
        // Créer la transaction mobile
        $reference   = Transaction_Mobile::genererReference($operateur->code_operateur);
        $transaction = Transaction_Mobile::create([
            'id_transaction_global' => $transactionGlobale->id,
            'id_operateur_mobile'   => $validated['id_operateur_mobile'],
            'reference_transaction' => $reference,
            'numero_client'         => $validated['numero_client'],
            'nom_client'            => $validated['nom_client'],
            'type_operation'        => $validated['type_operation'],
            'montant'               => $validated['montant'],
            'devise_id'             => $validated['devise_id'],
            'caisse_id'             => $caissePhysique->id,
            'commission_operateur'  => Null, // $calcul['commission_operateur'],
            'montant_net'           => $montantChange,
            'statut_transaction'    => 'COMPLETEE', 
            'id_creator'            => $user->id,
        ]);

        // ═══════════════════════════════════════════════════════
        // MOUVEMENTS DE CAISSE SELON LE TYPE D'OPÉRATION
        // ═══════════════════════════════════════════════════════
        if ($validated['type_operation'] === 'DEPOT') {
            // DEPOT : le client donne du cash → caisse physique encaisse (crédit)
            //         le système envoie l'argent mobile → caisse mobile débite
            $this->debiterAvecSeuil($caisseMobile, $montantChange, $validated['devise_id'], $validated['force'] ?? false);
            $caissePhysique->crediter((float) $validated['montant'], $validated['devise_id']);
        } else {
            // Retrait : le client reçoit du cash → caisse physique débite
            //           l'argent mobile arrive → caisse mobile crédite
            $this->debiterAvecSeuil($caissePhysique, $montantChange, $validated['devise_id'], $validated['force'] ?? false);
            $caisseMobile->crediter((float) $validated['montant'], $validated['devise_id']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Transaction mobile créée avec succès',
            'data'    => [
                'transaction'         => $transaction->load(['transactionGlobale.devise', 'operateur']),
                'transaction_globale' => $transactionGlobale->load(['operationChange']),
                'commission_change'   => $commissionChange, // 'commission'=> $commission,
                'niveau_alerte'       => $seuil->niveau_alerte,
            ],
        ], 201);
    }
    

    /**
     * Débite une caisse en vérifiant le solde et le seuil minimal,
     * renvoie une réponse d'avertissement si nécessaire.
     */
    private function debiterAvecSeuil(Caisses $caisse, float $montant, int $deviseId, bool $force = false)
    {
        $statutSolde = $caisse->verifierSolde($montant, $deviseId);

        if ($statutSolde === 'solde_insuffisant') {
            abort(422, 'Solde insuffisant dans la caisse ' . $caisse->nom_caisse);
        }

        if ($statutSolde === 'seuil_minimal_atteint' && !$force) {
            $soldeCaisse = SoldeCaisse::where('caisse_id', $caisse->id)
                ->where('devise_id', $deviseId)->first();
            abort(422, 'Avertissement : seuil minimal atteint', [
                'data' => [
                    'solde_actuel'   => (float) $soldeCaisse->solde,
                    'montant_requis' => $montant,
                    'solde_minimal'  => (float) $soldeCaisse->solde_minimal,
                    'solde_apres'    => round((float) $soldeCaisse->solde - $montant, 4),
                ]
            ]);
        }

        $caisse->debiter($montant, $deviseId);
    }

    public function show($id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null
            ], 400);
        }

        $transaction = Transaction_Mobile::with([
            'transactionGlobale.devise',
            'transactionGlobale.succursale',
            'transactionGlobale.caisse',
            'operateur',
        ])->find($id);

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction mobile introuvable',
                'data'    => null
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Détails de la transaction mobile',
            'data'    => $transaction,
        ], 200);
    }

    public function update(Request $request, $id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null
            ], 400);
        }

        $transaction = Transaction_Mobile::find($id);

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction mobile introuvable',
                'data'    => null
            ], 404);
        }

        // Bloquer si la transaction est déjà complète
        if ($transaction->statut_transaction == 'COMPLETEE') {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de modifier une transaction complète',
                'data'    => null
            ], 422);
        }

        $validated = $request->validate([
            'statut_transaction'    => 'sometimes|string|in:EN_ATTENTE,EN_COURS,COMPLETEE,ECHOUEE',
            'reference_transaction' => 'sometimes|string|max:150',
        ]);

        $transaction->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Transaction mobile mise à jour avec succès',
            'data'    => \array_intersect_key(
                $transaction->fresh()->toArray(),
                $validated
            ),
        ], 200);
    }

    public function destroy($id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null
            ], 400);
        }

        $transaction = Transaction_Mobile::find($id);

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction mobile introuvable',
                'data'    => null
            ], 404);
        }

        // Bloquer si complète
        if ($transaction->statut_transaction == 'COMPLETEE') {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer une transaction complète',
                'data'    => null
            ], 422);
        }

        $transaction->delete();

        return response()->json([
            'success' => true,
            'message' => 'Transaction mobile supprimée avec succès',
            'data'    => [
                'id'             => $transaction->id,
                'nom_client'     => $transaction->nom_client,
                'numero_client'  => $transaction->numero_client,
                'montant'        => $transaction->montant,
            ]
        ], 200);
    }

    // Méthode pour compléter un retrait en attente
    public function completer(Request $request, $id)
    {
        // Verifier que l'ID est un entier
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null,
            ], 400);
        }

        $transaction = Transaction_Mobile::find($id);

        // Vérifier que la transaction existe
        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction mobile introuvable',
                'data'    => null,
            ], 404);
        }

        // Vérifier que c'est un retrait en attente
        if ($transaction->type_operation !== 'RETRAIT') {
            return response()->json([
                'success' => false,
                'message' => 'Seules les transactions de type RETRAIT peuvent être complétées',
                'data'    => null,
            ], 422);
        }

        if ($transaction->statut_transaction !== 'EN_ATTENTE') {
            return response()->json([
                'success' => false,
                'message' => 'Cette transaction est déjà ' . $transaction->statut_transaction,
                'data'    => null,
            ], 422);
        }

        $validated = $request->validate([
            'caisse_mobile_id'   => 'required|integer|exists:caisses,id',
            'caisse_physique_id' => 'required|integer|exists:caisses,id',
            'force'              => 'nullable|boolean',
        ]);

        $caisseMobile = Caisses::find($validated['caisse_mobile_id']);
        $caissePhys   = Caisses::find($validated['caisse_physique_id']);

        // Vérifier types
        if ($caisseMobile->type_caisse !== 'MOBILE_MONEY') {
            return response()->json([
                'success' => false,
                'message' => 'La caisse mobile doit être de type MOBILE_MONEY',
                'data'    => null,
            ], 422);
        }

        if ($caissePhys->type_caisse !== 'PHYSIQUE') {
            return response()->json([
                'success' => false,
                'message' => 'La caisse physique doit être de type PHYSIQUE',
                'data'    => null,
            ], 422);
        }

        // Vérifier statuts des caisses
        if ($caisseMobile->statut != 'OUVERTE') {
            return response()->json([
                'success' => false,
                'message' => 'La caisse mobile doit être ouverte',
                'data'    => null,
            ], 422);
        }

        if ($caissePhys->statut != 'OUVERTE') {
            return response()->json([
                'success' => false,
                'message' => 'La caisse physique doit être ouverte',
                'data'    => null,
            ], 422);
        }

        // Récupérer la devise depuis transaction_globale
        $transactionGlobale = $transaction->transactionGlobale;
        $deviseId           = $transactionGlobale->devise_id;

        // Créditer caisse MOBILE_MONEY → argent reçu électroniquement
        $caisseMobile->crediter((float) $transaction->montant, $deviseId);

        // Débiter caisse PHYSIQUE → billets donnés au client
        $statutSolde = $caissePhys->verifierSolde((float) $transaction->montant_net, $deviseId);

        if ($statutSolde === 'solde_insuffisant') {
            return response()->json([
                'success' => false,
                'message' => 'Solde insuffisant dans la caisse physique pour payer le client',
                'data'    => null,
            ], 422);
        }

        if ($statutSolde === 'seuil_minimal_atteint' && !($validated['force'] ?? false)) {
            $soldeCaisse = \App\Models\SoldeCaisse::where('caisse_id', $caissePhys->id)
                ->where('devise_id', $deviseId)->first();
            return response()->json([
                'success'       => false,
                'avertissement' => true,
                'message'       => 'Le seuil minimal de la caisse physique sera atteint après ce retrait',
                'data'          => [
                    'solde_actuel'   => (float) $soldeCaisse->solde,
                    'montant_requis' => (float) $transaction->montant_net,
                    'solde_minimal'  => (float) $soldeCaisse->solde_minimal,
                    'solde_apres'    => round((float) $soldeCaisse->solde - $transaction->montant_net, 4),
                    'action'         => 'Renvoyer avec force: true pour confirmer',
                ],
            ], 200);
        }

        $caisseMobile->crediter((float) $transaction->montant, $deviseId);
        $caissePhys->debiter((float) $transaction->montant_net, $deviseId);

        // Mettre à jour les statuts
        $transaction->statut_transaction = 'COMPLETEE';
        $transaction->save();

        $transactionGlobale->statut = 'COMPLETEE';
        $transactionGlobale->save();

        // Mettre à jour la commission
        $transactionGlobale->mettreAJourCommission('COMPLETEE');

        // SMS de confirmation
        (new KeccelSmsService())->envoyer('RETRAIT', $transaction->numero_client, [
            'nom'       => $transaction->nom_client,
            'montant'   => $transaction->montant_net,
            'devise'    => $transactionGlobale->devise->code ?? '',
            'reference' => $transaction->reference_transaction,
        ], $transaction->reference_transaction, $transactionGlobale->id_succursale);

        return response()->json([
            'success' => true,
            'message' => 'Retrait complété avec succès',
            'data'    => $transaction->fresh()->load(['transactionGlobale.devise', 'operateur']),
        ], 200);
    }


    public function statTransaction(Request $request, $date = null)
    {
        $dateFiltre = $date ?? now()->toDateString();
        $query = Transaction_Mobile::whereDate('created_at', $dateFiltre);

        if ($request->has('type_operation')){
            $query->ParType($request->type_operation);
        }

        if ($request->has('id_operateur_mobile')){
            $query->whereHas('operateur', function ($q) use ($request) {
                $q->where('id', $request->id_operateur_mobile);
            });
        }

        if ($request->has('id_operateurMobileMoney')){
            $query->whereHas('operateur', function ($q) use ($request) {
                $q->where('service_mobile_money_id', $request->id_operateurMobileMoney);
            });
        }
    
        $transactions = $query->count();

        return response()->json([
            'sucess' => true,
            'message' => 'Nombre de transactions mobile pour la date donnée',
            'data' => [
                'date' => $date ?? now()->toDateString(),
                'nombre_transactions' => $transactions,
            ],
        ], 200);
    }
}