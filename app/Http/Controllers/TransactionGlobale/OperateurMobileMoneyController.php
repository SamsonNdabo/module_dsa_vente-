<?php

namespace App\Http\Controllers\TransactionGlobale;

use App\Http\Controllers\Controller;
use App\Models\OperateurMobileMoney;
use App\Models\Transaction_Mobile;
use App\Models\Transaction_Globale;
use App\Models\Caisses;
use App\Models\Seuil_Transactionnels;
use App\Models\Devise;
use App\Services\KelPayService;
use App\Services\KeccelSmsService;
use App\Events\TransactionExterneMiseAJour;
use Illuminate\Http\Request;
use App\Traits\Notifiable; 

class OperateurMobileMoneyController extends Controller
{
    use Notifiable;
    // ============================================================
    // CRUD
    // ============================================================

    public function index()
    {
        $operateurs = OperateurMobileMoney::all();

        return response()->json([
            'success' => true,
            'message' => 'Liste des opérateurs mobile money',
            'data'    => $operateurs,
        ], 200);
    }

    public function indexKaccelExterne(Request $request)
    {
        $user = auth()->user();
    
        $query = Transaction_Mobile::with([
            'operateurMobileMoney',
            'devise',
            'succursale',
            'caisse'
        ])
        ->whereNotNull('id_operateurMobileMoney')
        ->where('source_payement', 'EXTERNE');

        if ($user->isSuperAdmin() || $user->isChefOperation()||$user->isAuditeur()) {

        } elseif ($user->isChefAgence()) {
    
            $query->where('id_succursale', $user->succursale_id);
    
        } else {
    
            $query->whereHas('caisse', function ($q) use ($user) {
                $q->where('caissier_attribue', $user->id);
            });
    
        }
    

        if (
            $request->filled('id_succursale') &&
            ($user->isSuperAdmin() || $user->isChefOperation())
        ) {
            $query->where('id_succursale', $request->id_succursale);
        }
    
    
        if ($request->filled('statut_transaction')) {
            $query->where('statut_transaction', $request->statut_transaction);
        }
    
    
        if ($request->filled('numero_client')) {
            $query->where('numero_client', 'like', '%' . $request->numero_client . '%');
        }
        
        if ($request->filled('numero_ref')){
            $query->where('numero_ref', $request->numero_ref);
        }

        if ($request->filled('type_externe')){
            $query->where('type_externe', $request->type_externe);
        }
    
    
        $transactions = $query
            ->orderBy('created_at', 'desc')
            ->paginate(10);
    
    
        return response()->json([
            'success' => true,
            'message' => 'Liste des transactions faites à travers Kaccel',
            'data'    => $transactions,
        ], 200);
    }

        public function indexKaccel(Request $request)
    {
        // $user = auth()->user();
    
        $query = Transaction_Mobile::with([
            'operateurMobileMoney',
            'devise',
            'succursale',
            'caisse'
        ])
        ->whereNotNull('id_operateurMobileMoney');
    
    
        /*
        ============================================================
        GESTION DES ACCÈS
        ============================================================
        */
        // if ($user->isSuperAdmin() || $user->isChefOperation()||$user->isAuditeur()) {

        // } elseif ($user->isChefAgence()) {
    
        //     $query->where('id_succursale', $user->succursale_id);
    
        // } else {
    
        //     $query->whereHas('caisse', function ($q) use ($user) {
        //         $q->where('caissier_attribue', $user->id);
        //     });
    
        // }
    
    
        /*
        ============================================================
        FILTRE SUCCURSALE
        ============================================================
        */
        if (
            $request->filled('id_succursale') &&
            ($user->isSuperAdmin() || $user->isChefOperation())
        ) {
            $query->where('id_succursale', $request->id_succursale);
        }
    
    
        if ($request->filled('statut_transaction')) {
            $query->where('statut_transaction', $request->statut_transaction);
        }
    
    
        if ($request->filled('numero_client')) {
            $query->where('numero_client', 'like', '%' . $request->numero_client . '%');
        }
    
    
        $transactions = $query
            ->orderBy('created_at', 'desc')
            ->paginate(10);
    
    
        return response()->json([
            'success' => true,
            'message' => 'Liste des transactions faites à travers Kaccel',
            'data'    => $transactions,
        ], 200);
    }
    
    
    public function store(Request $request)
    {
        $validated = $request->validate([
            'code'              => 'required|string|max:20|unique:operateurs_mobile_money,code',
            'nom'               => 'required|string|max:100',
            'type_gateway'      => 'required|in:MOBILE_MONEY,CARTE_BANCAIRE,MIXTE',
            'merchant_code'     => 'nullable|string|max:100',
            'merchant_token'    => 'nullable|string|max:255',
            'base_url'          => 'nullable|string|max:255',
            'callback_url'      => 'nullable|string|max:255',
            'commission_defaut' => 'required|numeric|min:0',
            'supporte_mobile'   => 'required|boolean',
            'supporte_carte'    => 'required|boolean',
            'mode_operation'    => 'required|in:API,MANUEL',
            'statut'            => 'required|in:ACTIF,INACTIF,MAINTENANCE',
        ]);

        $operateur = OperateurMobileMoney::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Opérateur créé avec succès',
            'data'    => $operateur,
        ], 201);
    }

    public function show($id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null,
            ], 400);
        }

        $operateur = OperateurMobileMoney::find($id);

        if (!$operateur) {
            return response()->json([
                'success' => false,
                'message' => 'Opérateur introuvable',
                'data'    => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Détails de l\'opérateur',
            'data'    => $operateur,
        ], 200);
    }

    public function update(Request $request, $id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null,
            ], 400);
        }

        $operateur = OperateurMobileMoney::find($id);

        if (!$operateur) {
            return response()->json([
                'success' => false,
                'message' => 'Opérateur introuvable',
                'data'    => null,
            ], 404);
        }

        $validated = $request->validate([
            'nom'               => 'sometimes|string|max:100',
            'type_gateway'      => 'sometimes|in:MOBILE_MONEY,CARTE_BANCAIRE,MIXTE',
            'merchant_code'     => 'nullable|string|max:100',
            'merchant_token'    => 'nullable|string|max:255',
            'base_url'          => 'nullable|string|max:255',
            'callback_url'      => 'nullable|string|max:255',
            'commission_defaut' => 'sometimes|numeric|min:0',
            'supporte_mobile'   => 'sometimes|boolean',
            'supporte_carte'    => 'sometimes|boolean',
            'mode_operation'    => 'sometimes|in:API,MANUEL',
            'statut'            => 'sometimes|in:ACTIF,INACTIF,MAINTENANCE',
        ]);

        $operateur->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Opérateur mis à jour avec succès',
            'data'    => array_intersect_key($operateur->fresh()->toArray(), $validated),
        ], 200);
    }

    public function destroy($id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null,
            ], 400);
        }

        $operateur = OperateurMobileMoney::find($id);

        if (!$operateur) {
            return response()->json([
                'success' => false,
                'message' => 'Opérateur introuvable',
                'data'    => null,
            ], 404);
        }

        $operateur->delete();

        return response()->json([
            'success' => true,
            'message' => 'Opérateur supprimé avec succès',
            'data'    => ['id' => $operateur->id, 'code' => $operateur->code],
        ], 200);
    }

    // ============================================================
    // KelPay — Initier un paiement
    // ============================================================

    public function initierPaiement(Request $request)
    {
        $user = auth()->user();
        $maxMontant = ($request->devise === 'USD') ? 400 : OperateurMobileMoney::limiteTransfert();
        
        // Valider le type en avance
        $request->merge([
            'type_operation' => 'PAYIN', 

        ]);

        $validated = $request->validate([
            'id_operateur_mobile_money'=> 'required|integer|exists:operateurs_mobile_money,id',
            'nom_client'               => 'required|string|max:250',
            'numero_mobile'            => 'required|string|max:20',
            'type_operation'           => 'required|string|max:10',
            'devise'                   => 'required|in:USD,CDF',
            'montant'                  => 'required|numeric|min:0|max:' . $maxMontant,
            'id_succursale'            => 'required|integer|exists:succursales,id',
            'caisse_principale_id'     => 'required|integer|exists:caisses,id', 
            'description'              => 'required|string|max:255',
            'nom'                      => 'nullable|string|max:15', // Pour les API externes
            'numero_ref'               => 'required_with:nom|integer', // Si nom est présent, id_proformat doit être fourni pour tracer la source'
            'type'                     => 'require_with:nom|string|max:100',
            'id_commande'              => 'nullable|integer|exists:commandes,id', // Optionnel, pour tracer la commande liée si disponible
            'id_facture'               => 'nullable|integer|exists:factures,id', // Optionnel, pour tracer la facture liée si disponible
        ],
        [
            // PERSONNALISATION EN FRANÇAIS
            'montant.max'      => "Le montant maximum autorisé pour cette opération est de $maxMontant {$request->devise}.",
        ]);

        // --- Déterminer automatiquement les caisses secondaires ---
        $principale = Caisses::find($validated['caisse_principale_id']);
        if (!$principale || $principale->statut != 'OUVERTE') {
            return response()->json(['success' => false, 'message' => 'La caisse principale doit exister et être ouverte.'], 422);
        }

        // Caisse physique (pour les mouvements d’espèces)
        $caissePhysique = $principale->secondairePhysique();
        if (!$caissePhysique) {
            return response()->json(['success' => false, 'message' => 'Aucune caisse secondaire physique trouvée.'], 422);
        }

        // Opérateur et caisse mobile money correspondante
        $operateur = OperateurMobileMoney::find($validated['id_operateur_mobile_money']);
        if (!$operateur) {
            return response()->json(['success' => false, 'message' => 'Opérateur invalide.'], 422);
        }

        $caisseMobile = $principale->secondaireMobileMoney($operateur->service_mobile_money_id);
        if (!$caisseMobile || $caisseMobile->statut != 'OUVERTE') {
            return response()->json(['success' => false, 'message' => 'Caisse mobile money introuvable ou fermée.'], 422);
        }

        // Vérifier que la caisse mobile est du bon type
        if ($caisseMobile->type_caisse !== 'MOBILE_MONEY' && $caisseMobile->type_caisse !== 'BANCAIRE') {
            return response()->json(['success' => false, 'message' => 'La caisse mobile doit être de type MOBILE_MONEY ou BANCAIRE.'], 422);
        }

        // Détecter le seuil
        $roleId = $user->role_id;
        $deviseId = Devise::getIdByCode($validated['devise']);

        $seuil = Seuil_Transactionnels::detecter(
            'MOBILE_MONEY',
            (float) $validated['montant'],
            $deviseId,
            $roleId
        );

        if (!$seuil) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun seuil configuré pour ce type de transaction',
                'data'    => null,
            ], 422);
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

        // Recuperer l'ID de la devise

        //$deviseId = $validated['devise'] === 'USD' ? 2 : 1;
        $deviseId = Devise::getIdByCode($validated['devise']);

        // Vérifier que l'opérateur KelPay est actif
        $kelpay = new KelPayService();
 
        if (!$kelpay->estDisponible()) {
            return response()->json([
                'success' => false,
                'message' => 'Le service KelPay est indisponible ou inactif',
                'data'    => null,
            ], 503);
        }

        // Générer la référence automatiquement
        $reference = 'KLP-IN-' . now()->format('YmdHis') . '-' . strtoupper(\Illuminate\Support\Str::random(6));

        // Appel KelPay payin
        $kelpay   = new KelPayService();
        $response = $kelpay->payer(
            $reference,
            $validated['numero_mobile'],
            (float) $validated['montant'],
            $validated['devise'],
            $validated['description']
        );


        if (!KelPayService::estSucces($response)) {
            return response()->json([
                'success' => false,
                'message' => 'Paiement refusé par KelPay : ' . ($response['description'] ?? 'Erreur inconnue'),
                'data'    => $response,
            ], 422);
        }

        // Enregistrer le paiement dans paiements_kelpay
        $transactionGlobale = Transaction_Globale::create([
            'reference'             => $reference,
            'type'                  => 'MOBILE_MONEY',
            'numero_mobile'         => $validated['numero_mobile'],
            'montant_local'         => $validated['montant'],
            'transactionid'         => $response['transactionid'] ?? null,
            'devise_id'             => $deviseId,
            'date_transaction'      => now()->format('Y-m-d'),
            'commission_starehe'    => 0,
            'id_succursale'         => $validated['id_succursale'],
            'id_creator'            => auth()->id(),
            'caisse_id'             => $validated['caisse_principale_id'],
            'caisse_mobile_id'      => $caisseMobile->id,
            'id_seuil_transaction'  => $seuil->id,
            'description'           => $validated['description'],
            'statut'                => 'INITIE',
            'source_payement'       => !empty($validated['nom']) ? 'EXTERNE' : 'INTERNE'
        ]);

        // Récupérer l'opérateur pour avoir la commission payin
        $operateurGateway = OperateurMobileMoney::find($validated['id_operateur_mobile_money']);
        $calcul = Transaction_Mobile::calculer(
            (float) $validated['montant'],
            (float) ($operateurGateway->commission_payin ?? 0)
        );

        // Créer la transaction mobile
        $transaction = Transaction_Mobile::create([
            'id_transaction_global'     => $transactionGlobale->id,
            'id_operateurMobileMoney'   => $validated['id_operateur_mobile_money'],
            'transactionid'             => $response['transactionid'] ?? null,
            'reference_transaction'     => $reference,
            'numero_client'             => $validated['numero_mobile'],
            'nom_client'                => $validated['nom_client'],
            'type_operation'            => $validated['type_operation'],
            'montant'                   => $validated['montant'],
            'devise_id'                 => $deviseId,
            'caisse_id'                 => $caisseMobile->id,
            'commission_operateur'      => $calcul['commission_operateur'], 
            'montant_net'               => $calcul['montant_net'],         
            'statut_transaction'        => 'EN_ATTENTE',
            'description'               => $validated['description'],
            'source_payement'           => !empty($validated['nom']) ? 'EXTERNE' : 'INTERNE',
            'numero_ref'                => $validated['numero_ref'] ?? null,
            'id_commande'               => $validated['id_commande'] ?? null,
            'id_facture'                => $validated['id_facture'] ?? null,
            'type_externe'              => $validated['type'] ?? null,
            'id_creator'                => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Paiement initié avec succès',
            'data'    => [
                'reference'           => $response['reference'],
                'transaction'         => $transaction->load(['transactionGlobale.devise', 'operateur']),
                'transaction_globale' => $transactionGlobale->load(['operationChange']),
                'transactionid'       => $response['transactionid'],
                'description'         => $response['description'],
                'niveau_alerte'       => $seuil->niveau_alerte,
            ],
        ], 200);
    }
    // ============================================================
    // KelPay — Confirmer un paiement (vérification statut + MAJ)
    // ============================================================
 
    public function confirmerPaiement($id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null,
            ], 400);
        }

        // Vérifier que le paiement existe
 
        $paiement = Transaction_Mobile::where("id", $id)->first();

        if (!$paiement) {
            return response()->json([
                'success' => false,
                'message' => 'Paiement introuvable',
                'data'    => null,
            ], 404);
        }

        // Vérifier que la caisse existe

        $caisse = Caisses::where("id", $paiement->caisse_id)->first();

        if (!$caisse) {
            return response()->json([
                'success' => false,
                'message' => 'Caisse introuvable',
                'data'    => null,
            ], 404);
        }
 
        // Bloquer si déjà traité
        if (\in_array($paiement->statut_transaction, ['PAYE', 'ECHOUE'])) {
            return response()->json([
                'success' => false,
                'message' => 'Ce paiement est déjà ' . $paiement->statut_transaction,
                'data'    => ['statut' => $paiement->statut_transaction],
            ], 422);
        }
 
        // Vérifier que l'opérateur KelPay est actif
        $kelpay = new KelPayService();
 
        if (!$kelpay->estDisponible()) {
            return response()->json([
                'success' => false,
                'message' => 'Le service KelPay est indisponible ou inactif',
                'data'    => null,
            ], 503);
        }
 
        // Appel vérification statut auprès de KelPay
        $response = $kelpay->verifierStatut($paiement->transactionid);
 
        $estSucces = KelPayService::estSucces($response);
 
        // Mettre à jour le statut du paiement
        $paiement->statut_transaction = $estSucces ? 'COMPLETEE' : 'ECHOUE';
        $paiement->save();
        // Émettre l'événement pour notifier les clients en temps réel
        event(new TransactionExterneMiseAJour($paiement));
 
        // Si succès → mettre à jour la transaction globale et créditer la caisse
        if ($estSucces) {
            $transactionGlobale = $paiement->transactionGlobale;
            $transactionGlobale->statut = 'COMPLETEE';
            $transactionGlobale->save();
            // Émettre l'événement pour notifier les clients en temps réel
            event(new TransactionExterneMiseAJour($transactionGlobale));


            $transactionGlobale->mettreAJourCommission('COMPLETEE');

            // SMS de confirmation
            (new KeccelSmsService())->envoyer('PAYIN', $paiement->numero_client, [
                'nom'       => $paiement->nom_client,
                'montant'   => $paiement->montant,
                'devise'    => $transactionGlobale->devise->code ?? '',
                'reference' => $paiement->reference_transaction,
            ], $paiement->reference_transaction, $transactionGlobale->id_succursale);

            $deviseId = $transactionGlobale->devise_id;
            $caisse->crediter((float) $paiement->montant_net, $deviseId);
        }

        return response()->json([
            'success' => true,
            'message' => $estSucces
                ? 'Paiement confirmé avec succès'
                : 'Paiement échoué selon KelPay',
            'data'    => [
                'paiement_id'    => $paiement->id,
                'reference'      => $paiement->reference_transaction,
                'transactionid'  => $paiement->transactionid,
                'statut'         => $paiement->statut_transaction,
                'kelpay_reponse' => $response,
            ],
        ], 200);
    }

    // ============================================================
    // KelPay — Initier un payout (décaissement vers client)
    // ============================================================

    public function initierPayout(Request $request)
    {
        $user = auth()->user();
        $request->merge([
            'type_operation' => 'DEPOT',
        ]);

        $validated = $request->validate([
            'id_operateur_mobile_money' => 'required|integer|exists:operateurs_mobile_money,id',
            'nom_client'                => 'required|string|max:250',
            'numero_mobile'             => 'required|string|max:20',
            'account_type'              => 'required|string|in:mobile,paypal',
            'type_operation'            => 'required|string|max:10',
            'montant'                   => 'required|numeric|min:0',
            'devise'                    => 'required|in:USD,CDF',
            'id_succursale'             => 'required|integer|exists:succursales,id',
            'caisse_principale_id'      => 'required|integer|exists:caisses,id',
            'description'               => 'required|string|max:255',
            'force'                     => 'sometimes|boolean',
        ]);

        $principale = Caisses::find($validated['caisse_principale_id']);
        if (!$principale || $principale->statut != 'OUVERTE') {
            return response()->json(['success' => false, 'message' => 'La caisse principale doit exister et être ouverte.'], 422);
        }

        // Opérateur et caisse mobile associée
        $operateur = OperateurMobileMoney::find($validated['id_operateur_mobile_money']);
        if (!$operateur) {
            return response()->json(['success' => false, 'message' => 'Opérateur invalide ou sans service associé.'], 422);
        }

        $caisseMobile = $principale->secondaireMobileMoney($operateur->service_mobile_money_id);
        if (!$caisseMobile || $caisseMobile->statut != 'OUVERTE') {
            return response()->json(['success' => false, 'message' => 'Caisse mobile money introuvable ou fermée.'], 422);
        }

        // Détecter le seuil
        $roleId = $user->role_id;
        $deviseId = Devise::getIdByCode($validated['devise']);

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

        $reference = 'KLP-OUT-' . now()->format('YmdHis') . '-' . strtoupper(\Illuminate\Support\Str::random(6));

        $response = $kelpay->payout(
            $reference,
            $validated['numero_mobile'],
            $validated['account_type'],
            (float) $validated['montant'],
            $validated['devise'],
            $validated['description']
        );

        if (!KelPayService::estSucces($response)) {
            return response()->json([
                'success' => false,
                'message' => 'Payout refusé par KelPay : ' . ($response['description'] ?? 'Erreur inconnue'),
                'data'    => $response,
            ], 422);
        }

        // Transaction globale
        $transactionGlobale = Transaction_Globale::create([
            'reference'            => Transaction_Globale::genererReference(),
            'type'                 => 'MOBILE_MONEY_RETRAIT',
            'numero_mobile'        => $validated['numero_mobile'],
            'montant_local'        => $validated['montant'],
            'transactionid'        => $response['transactionid'] ?? null,
            'devise_id'            => $deviseId,
            'date_transaction'     => now()->format('Y-m-d'),
            'commission_starehe'   => 0,
            'id_succursale'        => $validated['id_succursale'],
            'id_creator'           => auth()->id(),
            'caisse_id'            => $caisseMobile->id,       // on référence la caisse mobile, pas la physique
            'caisse_mobile_id'     => $caisseMobile->id,
            'id_seuil_transaction' => $seuil->id,
            'description'          => $validated['description'],
            'statut'               => 'EN_ATTENTE',
        ]);

        $calcul = Transaction_Mobile::calculerPayout(
            (float) $validated['montant'],
            (float) ($operateur->commission_payout ?? 0)
        );

        $transaction = Transaction_Mobile::create([
            'id_transaction_global' => $transactionGlobale->id,
            'id_operateur_mobile'   => $validated['id_operateur_mobile_money'],
            'transactionid'         => $response['transactionid'] ?? null,
            'reference_transaction' => $reference,
            'numero_client'         => $validated['numero_mobile'],
            'nom_client'            => $validated['nom_client'],
            'type_operation'        => $validated['type_operation'],
            'montant'               => $validated['montant'],
            'devise_id'             => $deviseId,
            'caisse_id'             => $caisseMobile->id,
            'commission_operateur'  => $calcul['commission_operateur'],
            'montant_net'           => $calcul['montant_net'],
            'statut_transaction'    => 'EN_ATTENTE',
            'description'           => $validated['description'],
            'id_creator'            => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payout initié avec succès',
            'data'    => [
                'reference'           => $reference,
                'transaction'         => $transaction->load(['transactionGlobale.devise', 'operateur']),
                'transaction_globale' => $transactionGlobale,
                'transactionid'       => $response['transactionid'] ?? null,
                'niveau_alerte'       => $seuil->niveau_alerte,
            ],
        ], 200);
    }

    // ============================================================
    // KelPay — Confirmer un payout (vérification statut + MAJ)
    // ============================================================

    public function confirmerPayout(Request $request, $id)
    {
        $force = (bool) ($request->input('force', false));

        if (!ctype_digit(\strval($id))) {
            return response()->json(['success' => false, 'message' => 'ID invalide', 'data' => null], 400);
        }

        $transaction = Transaction_Mobile::find($id);
        if (!$transaction) {
            return response()->json(['success' => false, 'message' => 'Transaction introuvable', 'data' => null], 404);
        }

        if ($transaction->type_operation !== 'DEPOT' && $transaction->type_operation !== 'RETRAIT') {
            return response()->json(['success' => false, 'message' => 'Type de transaction non compatible avec le payout'], 422);
        }

        if (in_array($transaction->statut_transaction, ['COMPLETEE', 'ECHOUEE'])) {
            return response()->json(['success' => false, 'message' => 'Ce payout est déjà ' . $transaction->statut_transaction], 422);
        }

        if (!$transaction->transactionid) {
            return response()->json(['success' => false, 'message' => 'Aucun transactionid KelPay associé'], 422);
        }

        // Récupérer la caisse mobile (celle référencée dans la transaction)
        $caisseMobile = Caisses::find($transaction->caisse_id);
        if (!$caisseMobile) {
            return response()->json(['success' => false, 'message' => 'Caisse mobile introuvable'], 404);
        }

        // Vérifier que KelPay est disponible
        $kelpay = new KelPayService();
        if (!$kelpay->estDisponible()) {
            return response()->json(['success' => false, 'message' => 'Le service KelPay est indisponible'], 503);
        }

        // Vérifier le statut auprès de KelPay
        $response = $kelpay->verifierStatut($transaction->transactionid);
        $estSucces = KelPayService::estSuccesPayout($response);

        // Mettre à jour les statuts
        $transaction->statut_transaction = $estSucces ? 'COMPLETEE' : 'ECHOUEE';
        $transaction->save();
            // Émettre l'événement pour notifier les clients en temps réel
        event(new TransactionExterneMiseAJour($transaction));

        $transactionGlobale = $transaction->transactionGlobale;
        $transactionGlobale->statut = $estSucces ? 'COMPLETEE' : 'ECHOUEE';
        $transactionGlobale->save();
            // Émettre l'événement pour notifier les clients en temps réel
        event(new TransactionExterneMiseAJour($transactionGlobale));


        if ($estSucces) {
            // Débiter la caisse mobile du montant total sorti (on utilise le montant de la transaction)
            $montantDebit = (float) $transaction->montant;
            $deviseId = $transaction->devise_id;

            // Vérifier le solde de la caisse mobile
            $statutSolde = $caisseMobile->verifierSolde($montantDebit, $deviseId);

            if ($statutSolde === 'solde_insuffisant') {
                return response()->json([
                    'success' => false,
                    'message' => 'Solde insuffisant dans la caisse mobile pour débiter ce payout.',
                ], 422);
            }

            if ($statutSolde === 'seuil_minimal_atteint' && !$force) {
                $soldeCaisse = \App\Models\SoldeCaisse::where('caisse_id', $caisseMobile->id)
                    ->where('devise_id', $deviseId)->first();
                return response()->json([
                    'success'       => false,
                    'avertissement' => true,
                    'message'       => 'Le seuil minimal de la caisse mobile sera atteint après ce débit.',
                    'data'          => [
                        'solde_actuel'   => (float) $soldeCaisse->solde,
                        'montant_requis' => $montantDebit,
                        'solde_minimal'  => (float) $soldeCaisse->solde_minimal,
                        'solde_apres'    => round((float) $soldeCaisse->solde - $montantDebit, 4),
                        'action'         => 'Renvoyer avec force: true pour confirmer',
                    ],
                ], 200);
            }

            // Débiter la caisse mobile
            if (!$caisseMobile->debiter($montantDebit, $deviseId)) {
                return response()->json(['success' => false, 'message' => 'Échec du débit de la caisse mobile.'], 422);
            }

            // Mise à jour commission
            $transactionGlobale->mettreAJourCommission('COMPLETEE');

            // SMS de confirmation
            (new KeccelSmsService())->envoyer('PAYOUT', $transaction->numero_client, [
                'nom'       => $transaction->nom_client,
                'montant'   => $transaction->montant_net,
                'devise'    => $transactionGlobale->devise->code ?? '',
                'numero'    => $transaction->numero_client,
                'reference' => $transaction->reference_transaction,
            ], $transaction->reference_transaction, $transactionGlobale->id_succursale);
        }

        return response()->json([
            'success' => true,
            'message' => $estSucces ? 'Payout confirmé avec succès' : 'Payout échoué selon KelPay',
            'data'    => [
                'transaction'    => $transaction->fresh()->load(['transactionGlobale.devise', 'operateur']),
                'transactionid'  => $transaction->transactionid,
                'statut'         => $transaction->statut_transaction,
                'kelpay_reponse' => $response,
            ],
        ], 200);
    }

    // ============================================================
    // KelPay — Vérifier le statut d'une transaction
    // ============================================================

    public function verifierStatut(Request $request)
    {
        $validated = $request->validate([
            'transactionid' => 'required|string',
        ]);

        $kelpay   = new KelPayService();
        $response = $kelpay->verifierStatut($validated['transactionid']);

        return response()->json([
            'success' => true,
            'message' => 'Statut de la transaction',
            'data'    => $response,
        ], 200);
    }

    // ============================================================
    // KelPay — Vérifier le solde
    // ============================================================

    public function getBalance(Request $request)
    {
        $validated = $request->validate([
            'devise' => 'required|in:USD,CDF',
        ]);

        $kelpay   = new KelPayService();
        $response = $kelpay->getBalance($validated['devise']);

        return response()->json([
            'success' => true,
            'message' => 'Solde du compte KelPay',
            'data'    => $response,
        ], 200);
    }

    // ============================================================
    // KelPay — Callback (réception confirmation)
    // ============================================================

    public function callback(Request $request)
    {
        $data = $request->all();

        // --- Payout callback : KelPay renvoie transactionstatus ---
        if (isset($data['transactionstatus'])) {
            $estSucces = $data['transactionstatus'] === 'Delivered';

            $transaction = Transaction_Mobile::where(
                'reference_transaction', $data['reference'] ?? null
            )->first();

            if ($transaction && $transaction->statut_transaction === 'EN_ATTENTE') {
                $transaction->statut_transaction = $estSucces ? 'COMPLETEE' : 'ECHOUEE';
                $transaction->save();

                $transactionGlobale = $transaction->transactionGlobale;
                $transactionGlobale->statut = $estSucces ? 'COMPLETEE' : 'ECHOUEE';
                $transactionGlobale->save();

                if ($estSucces) {
                    $transactionGlobale->mettreAJourCommission('COMPLETEE');
                }
            }

            return response()->json(['status' => 'OK'], 200);
        }

        // --- Payin callback : KelPay renvoie code 0 ---
        if (!isset($data['code']) || $data['code'] !== '0') {
            return response()->json(['status' => 'OK'], 200);
        }

        $transaction = Transaction_Mobile::where(
            'reference_transaction', $data['reference'] ?? null
        )->first();

        if ($transaction && $transaction->statut_transaction === 'EN_ATTENTE') {
            $transaction->statut_transaction = 'COMPLETEE';
            $transaction->save();
            // Émettre l'événement pour notifier les clients en temps réel
            event(new TransactionExterneMiseAJour($transaction));

            $transactionGlobale = $transaction->transactionGlobale;
            $transactionGlobale->statut = 'COMPLETEE';
            $transactionGlobale->save();
            // Émettre l'événement pour notifier les clients en temps réel
            event(new TransactionExterneMiseAJour($transactionGlobale));


            $transactionGlobale->mettreAJourCommission('COMPLETEE');
        }

        return response()->json(['status' => 'OK'], 200);
    }
    
    
    private function notifierSeuilDepasse(
        string $typeOperation,
        float $montant,
        string $devise,
        int $succursaleId,
        string $reference,
        array $details = []
    ): void {
        try {
            // Récupérer le seuil qui a été dépassé
            $seuil = Seuil_Transactionnels::where('type_transaction', 'MOBILE_MONEY')
                ->where('montant_min', '<=', $montant)
                ->where(function ($q) use ($montant) {
                    $q->where('montant_max', '>=', $montant)
                      ->orWhereNull('montant_max');
                })
                ->first();

            if (!$seuil) {
                Log::warning('Seuil non trouvé pour la notification', [
                    'montant' => $montant,
                    'type' => $typeOperation,
                    'devise' => $devise
                ]);
                return;
            }

            // Déterminer le libellé de l'opération
            $operationLabels = [
                'PAYIN' => 'dépôt (payin)',
                'PAYOUT' => 'retrait (payout)',
                'DEPOT' => 'dépôt',
                'RETRAIT' => 'retrait',
            ];
            $operationLabel = $operationLabels[$typeOperation] ?? $typeOperation;

            // Construire le message
            $message = sprintf(
                "⚠️ ALERTE SEUIL - Transaction %s\n\n" .
                "Montant: %s %s\n" .
                "Référence: %s\n" .
                "Seuil: %s - %s %s\n" .
                "Niveau d'alerte: %s\n" .
                "%s",
                $operationLabel,
                number_format($montant, 2),
                $devise,
                $reference,
                number_format($seuil->montant_min ?? 0, 2),
                $devise,
                $seuil->montant_max ? " - " . number_format($seuil->montant_max, 2) . " " . $devise : "",
                $seuil->niveau_alerte ?? 'INCONNU',
                isset($details['client']) ? "Client: " . $details['client'] : ""
            );

            // Notifier les chefs d'agence
            $this->notifierDestinatairesSpecifiques([
                'destinataires' => [
                    'type' => 'role',
                    'code' => 'CHEF_AGENCE'
                ],
                'type' => 'ALERTE_SEUIL_TRANSACTION',
                'titre' => "⚠️ Alerte seuil - Transaction " . $operationLabel,
                'message' => $message,
                'donnees' => [
                    'type_operation' => $typeOperation,
                    'montant' => $montant,
                    'devise' => $devise,
                    'reference' => $reference,
                    'seuil_id' => $seuil->id,
                    'niveau_alerte' => $seuil->niveau_alerte,
                    'succursale_id' => $succursaleId,
                    'details' => $details,
                    'date_transaction' => now()->toDateTimeString()
                ],
                'succursale_id' => $succursaleId,
                'priorite' => 'HAUTE'
            ]);

            // Notifier aussi le Super Admin
            $this->notifierDestinatairesSpecifiques([
                'destinataires' => [
                    'type' => 'role',
                    'code' => 'SUPER_ADMIN'
                ],
                'type' => 'ALERTE_SEUIL_TRANSACTION',
                'titre' => "⚠️ ALERTE - Seuil dépassé",
                'message' => $message,
                'donnees' => [
                    'type_operation' => $typeOperation,
                    'montant' => $montant,
                    'devise' => $devise,
                    'reference' => $reference,
                    'seuil_id' => $seuil->id,
                    'niveau_alerte' => $seuil->niveau_alerte,
                    'succursale_id' => $succursaleId
                ],
                'succursale_id' => $succursaleId,
                'priorite' => 'HAUTE'
            ]);

            Log::info('Notification seuil dépassé envoyée', [
                'type_operation' => $typeOperation,
                'montant' => $montant,
                'devise' => $devise,
                'reference' => $reference,
                'seuil_id' => $seuil->id,
                'niveau_alerte' => $seuil->niveau_alerte
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la notification de seuil', [
                'error' => $e->getMessage(),
                'type_operation' => $typeOperation,
                'montant' => $montant,
                'devise' => $devise,
                'reference' => $reference
            ]);
        }
    }

}