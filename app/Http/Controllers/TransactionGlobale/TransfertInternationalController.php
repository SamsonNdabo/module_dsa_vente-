<?php

namespace App\Http\Controllers\TransactionGlobale;

use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\OperationChange;
use App\Models\Seuil_Transactionnels;
use App\Models\TransfertInternational;
use App\Models\OperateursInternational;
use App\Models\transaction_globale;
use App\Models\Commission;
use App\Models\Utilisateur;
use App\Models\Caisses;
use Illuminate\Http\Request;

class TransfertInternationalController extends Controller
{
        public function index(Request $request)
    {
        $user = auth()->user();
    
        $query = TransfertInternational::with([
            'operateur',
            'succursale',
            'caissier',
            'superviseur',
        ]);
    
 /*   
    
        if ($user->isSuperAdmin() || $user->isChefOperation()) {
    
            // Accès complet
    
        } elseif ($user->isChefAgence()) {
    
            $query->where('succursale_id', $user->succursale_id);
    
        } else {
    
            $query->where('operateur_transfert_id', $user->id);
    
        }
*/
    
        /*
        ============================================================
        FILTRES
        ============================================================
        */
    
        if ($request->has('statut_transfert')) {
            $query->parStatut($request->statut_transfert);
        }
    
    
        if ($request->has('type_operation')) {
            $query->where('type_operation', $request->type_operation);
        }
    
    
        /*
        ============================================================
        FILTRE SUCCURSALE
        ============================================================
        Accessible uniquement par SUPER_ADMIN et CHEF_OPERATIONS
        ============================================================
        */
        if (
            $request->has('succursale_id') &&
            ($user->isSuperAdmin() || $user->isChefOperation())
        ) {
            $query->where('succursale_id', $request->succursale_id);
        }
    
    
        if ($request->has('operateur_transfert_id')) {
            $query->where(
                'operateur_transfert_id',
                $request->operateur_transfert_id
            );
        }
    
    
        if ($request->has('date_debut') && $request->has('date_fin')) {
            $query->whereBetween('date_creation', [
                $request->date_debut,
                $request->date_fin,
            ]);
        }
    
    
        $transferts = $query
            ->orderBy('date_creation', 'desc')
            ->paginate(20);
    
    
        return response()->json([
            'success' => true,
            'message' => 'Liste des transferts internationaux',
            'data'    => $transferts,
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'operateur_transfert_id'    => 'required|integer|exists:operateurs_international,id',
            'type_operation'            => 'required|in:' . TransfertInternational::TYPE_ENVOI . ',' . TransfertInternational::TYPE_PAIEMENT,
            'montant_principal'         => 'required|numeric|min:0',
            'devise_origine'            => 'required|string|max:30',
            'devise_destination'        => 'nullable|string|max:30',
            'devise_source_id'          => 'required|integer|exists:devise,id',
            'devise_dest_id'            => 'required|integer|exists:devise,id',
            'informations_expediteur'   => 'required|array',
            'informations_expediteur.nom'       => 'required|string',
            'informations_expediteur.telephone' => 'required|string',
            'informations_expediteur.adresse'   => 'required|string',
            'informations_destinataire'          => 'required|array',
            'informations_destinataire.nom'      => 'required|string',
            'informations_destinataire.telephone'=> 'required|string',
            'informations_destinataire.adresse'  => 'required|string',
            'piece_identite_type'       => 'required|string|max:50',
            'piece_identite_numero'     => 'required|string|max:50',
            'piece_identite_fichier'    => 'nullable|string|max:255',
            'succursale_id'             => 'required|integer|exists:succursale,id',
            'caisse_id'                 => 'required|integer|exists:caisses,id',
            'caisse_physique_id'        => 'required|integer|exists:caisses,id',
            'caissier_id'               => 'nullable|integer|exists:utilisateur,id',
            'superviseur_id'            => 'nullable|integer|exists:utilisateur,id',
            'inclure_change'            => 'nullable|boolean',
            'force'                     => 'nullable|boolean', 
            //'devise_change_id'          => 'required_if:inclure_change,true|integer|exists:devise,id',
        ]);

        // Vérifier que l'opérateur est actif
        $operateur = Operateurs_International::find($validated['operateur_transfert_id']);

        if ($operateur->statut != 'ACTIF') {
            return response()->json([
                'success' => false,
                'message' => 'Cet opérateur est inactif',
                'data'    => null
            ], 422);
        }

        // Conversion via le model
        $conversion = $operateur->convertir(
            (int) $validated['devise_source_id'],
            (int) $validated['devise_dest_id'],
            (float) $validated['montant_principal']
        );

        if ($conversion['sens'] === 'aucun_taux') {
            return response()->json([
                'success' => false,
                'message' => 'Aucun taux de change disponible pour ces devises',
                'data'    => null
            ], 422);
        }

        // Calculer les frais selon le type d'opération
        $frais = $validated['type_operation'] === 'ENVOI'
            ? $operateur->calculerFraisEnvoi((float) $validated['montant_principal'])
            : $operateur->calculerFraisReception((float) $validated['montant_principal']);

        $montantTotal = TransfertInternational::calculerTotal(
            (float) $validated['montant_principal'],
            $frais
        );

        // Détecter le seuil
        $user = auth()->user();
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

        // Générer la référence
        $reference = TransfertInternational::genererReference($operateur->code_operateur);

        // Créer le transfert
        $transfert = TransfertInternational::create([
            'reference_transfert'       => $reference,
            'operateur_transfert_id'    => $validated['operateur_transfert_id'],
            'type_operation'            => $validated['type_operation'],
            'montant_principal'         => $validated['montant_principal'],
            'devise_origine'            => $validated['devise_origine'],
            'devise_destination'        => $validated['devise_destination'] ?? null,
            'montant_converti'          => $conversion['montant_converti'],
            'taux_change'               => $conversion['taux_utilise'],
            'frais_operateur'           => $frais,
            'montant_total'             => $montantTotal,
            'code_secret'               => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(8)),
            'informations_expediteur'   => $validated['informations_expediteur'],
            'informations_destinataire' => $validated['informations_destinataire'],
            'piece_identite_type'       => $validated['piece_identite_type'],
            'piece_identite_numero'     => $validated['piece_identite_numero'],
            'piece_identite_fichier'    => $validated['piece_identite_fichier'] ?? null,
            'statut_transfert'          => 'INITIE',
            'date_envoi'                => $validated['type_operation'] === 'ENVOI' ? now() : null,
            'date_expiration'           => now()->addDays(30),
            'succursale_id'             => $validated['succursale_id'],
            'caisse_id'                 => $validated['caisse_id'] ?? null,
            'caissier_id'               => $validated['caissier_id'] ?? null,
            'superviseur_id'            => $validated['superviseur_id'] ?? null,
        ]);

        // Créer la transaction globale associée
        $transactionGlobale = transaction_globale::create([
            'reference'            => transaction_globale::genererReference(),
            'type'                 => 'TRANSFERT_INTERNATIONAL',
            'montant_local'        => $validated['montant_principal'],
            'devise_id'            => $validated['devise_source_id'],
            'date_transaction'     => now()->format('Y-m-d'),
            'statut'               => 'INITIE',
            'commission_starehe'   => 0,
            'id_succursale'        => $validated['succursale_id'],
            'id_creator'           => auth()->id(),
            'caisse_id'            => $validated['caisse_id'] ?? null,
            'id_seuil_transaction' => $seuil ? $seuil->id : 1,
            'montant_devise_pivot' => $conversion['montant_converti'],
            'transfert_international_id'  => $transfert->id, 
        ]);

        // Débiter/Créditer la caisse
        if ($validated['caisse_id'] ?? null) {
            $caisseBancaire = Caisses::find($validated['caisse_id']);
            $caissePhysique = Caisses::find($validated['caisse_physique_id']);

            // Vérifications types
            if ($caisseBancaire->type_caisse !== 'BANCAIRE') {
                return response()->json([
                    'success' => false,
                    'message' => 'La caisse principale doit être de type BANCAIRE',
                    'data'    => null,
                ], 422);
            }

            if ($caissePhysique->type_caisse !== 'PHYSIQUE') {
                return response()->json([
                    'success' => false,
                    'message' => 'La caisse physique doit être de type PHYSIQUE',
                    'data'    => null,
                ], 422);
            }

            if ($validated['type_operation'] === 'ENVOI') {
                // Client donne argent → caisse PHYSIQUE créditée
                $caissePhysique->crediter(
                    (float) $transfert->montant_total,
                    $validated['devise_source_id']
                );

                // Argent envoyé → caisse BANCAIRE débitée
                $statutSolde = $caisseBancaire->verifierSolde((float) $transfert->montant_total, $validated['devise_source_id']);

                if ($statutSolde === 'solde_insuffisant') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Solde insuffisant dans la caisse bancaire',
                        'data'    => null,
                    ], 422);
                }

                if ($statutSolde === 'seuil_minimal_atteint' && !($validated['force'] ?? false)) {
                    $soldeCaisse = \App\Models\SoldeCaisse::where('caisse_id', $caisseBancaire->id)
                        ->where('devise_id', $validated['devise_source_id'])->first();
                    return response()->json([
                        'success'       => false,
                        'avertissement' => true,
                        'message'       => 'Le seuil minimal de la caisse bancaire sera atteint après cet envoi',
                        'data'          => [
                            'solde_actuel'   => (float) $soldeCaisse->solde,
                            'montant_requis' => (float) $transfert->montant_total,
                            'solde_minimal'  => (float) $soldeCaisse->solde_minimal,
                            'solde_apres'    => round((float) $soldeCaisse->solde - $transfert->montant_total, 4),
                            'action'         => 'Renvoyer avec force: true pour confirmer',
                        ],
                    ], 200);
                }

                $caissePhysique->crediter((float) $transfert->montant_total, $validated['devise_source_id']);
                $caisseBancaire->debiter((float) $transfert->montant_total, $validated['devise_source_id']);

            } else {
                // RECEPTION
                // Argent reçu → caisse BANCAIRE créditée
                $montantReception = (float) $transfert->montant_principal - (float) $transfert->frais_operateur;
                $statutSolde = $caissePhysique->verifierSolde($montantReception, $validated['devise_source_id']);

                if ($statutSolde === 'solde_insuffisant') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Solde insuffisant dans la caisse physique pour payer le client',
                        'data'    => null,
                    ], 422);
                }

                if ($statutSolde === 'seuil_minimal_atteint' && !($validated['force'] ?? false)) {
                    $soldeCaisse = \App\Models\SoldeCaisse::where('caisse_id', $caissePhysique->id)
                        ->where('devise_id', $validated['devise_source_id'])->first();
                    return response()->json([
                        'success'       => false,
                        'avertissement' => true,
                        'message'       => 'Le seuil minimal de la caisse physique sera atteint après cette réception',
                        'data'          => [
                            'solde_actuel'   => (float) $soldeCaisse->solde,
                            'montant_requis' => $montantReception,
                            'solde_minimal'  => (float) $soldeCaisse->solde_minimal,
                            'solde_apres'    => round((float) $soldeCaisse->solde - $montantReception, 4),
                            'action'         => 'Renvoyer avec force: true pour confirmer',
                        ],
                    ], 200);
                }

                $caisseBancaire->crediter($montantReception, $validated['devise_source_id']);
                $caissePhysique->debiter($montantReception, $validated['devise_source_id']);
            }
        }

        // Change inclus si demandé
        if ($validated['inclure_change'] ?? false) {
            $change = OperationChange::effectuer(
                $validated['succursale_id'],
                $validated['devise_source_id'],
                $validated['devise_dest_id'],
                (float) $validated['montant_principal'],
                $validated['caisse_id'] ?? null,
                $validated['caissier_id'] ?? null,
                True
            );

            if (!$change) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun taux de change disponible',
                    'data'    => null,
                ], 422);
            }

            $transactionGlobale->operation_change_id  = $change['operation']->id;
            $transactionGlobale->montant_devise_pivot = $change['operation']->montant_cible;
            $transactionGlobale->save();
        }

        //Créer la commission
        $commission = Commission::creerPourTransaction($transactionGlobale, 'PAYEE');

        return response()->json([
            'success' => true,
            'message' => 'Transfert international initié avec succès',
            'data'    => [
                'transfert'          => $transfert->load(['operateur', 'succursale', 'caissier']),
                
                'transaction_globale'=> $transactionGlobale->load(['operationChange']),
                'conversion'         => $conversion,
                'niveau_alerte'      => $seuil?->niveau_alerte,
            ],
        ], 201);
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

        $transfert = TransfertInternational::with([
            'operateur',
            'succursale',
            'caisse',
            'caissier',
            'superviseur',
        ])->find($id);

        if (!$transfert) {
            return response()->json([
                'success' => false,
                'message' => 'Transfert introuvable',
                'data'    => null
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Détails du transfert',
            'data'    => $transfert,
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

        $transfert = TransfertInternational::find($id);

        if (!$transfert) {
            return response()->json([
                'success' => false,
                'message' => 'Transfert introuvable',
                'data'    => null
            ], 404);
        }

        // Bloquer si le transfert est terminé
        if (\in_array($transfert->statut_transfert, ['RECU', 'ANNULE', 'REMBOURSE'])) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de modifier un transfert terminé',
                'data'    => null
            ], 422);
        }

        $validated = $request->validate([
            'piece_identite_fichier' => 'nullable|string|max:255',
            'superviseur_id'         => 'nullable|integer|exists:utilisateur,id',
        ]);

        $transfert->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Transfert mis à jour avec succès',
            'data'    => \array_intersect_key(
                $transfert->fresh()->toArray(),
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

        $transfert = TransfertInternational::find($id);

        if (!$transfert) {
            return response()->json([
                'success' => false,
                'message' => 'Transfert introuvable',
                'data'    => null
            ], 404);
        }

        if ($transfert->statut_transfert != 'INITIE') {
            return response()->json([
                'success' => false,
                'message' => 'Seul un transfert INITIE peut être supprimé',
                'data'    => null
            ], 422);
        }

        $transfert->delete();

        return response()->json([
            'success' => true,
            'message' => 'Transfert supprimé avec succès',
            'data'    => [
                'id'                  => $transfert->id,
                'reference_transfert' => $transfert->reference_transfert,
            ]
        ], 200);
    }

    // ============================================================
    // Cycle de vie du transfert
    // ============================================================

    public function payer(Request $request, $id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null
            ], 400);
        }

        $transfert = TransfertInternational::find($id);
        $transfertGlobal = transaction_globale::where ('transfert_international_id','=',$id)->first();

        if (!$transfert) {
            return response()->json([
                'success' => false,
                'message' => 'Transfert introuvable',
                'data'    => null
            ], 404);
        }

        if (!$transfert->peutPasser('PAYE')) {
            return response()->json([
                'success' => false,
                'message' => 'Ce transfert ne peut pas passer à l\'état PAYE — statut actuel : ' . $transfert->statut_transfert,
                'data'    => null
            ], 422);
        }

        $transfert->statut_transfert = 'PAYE';
        $transfert->save();

        $transfertGlobal->statut = 'PAYE';
        $transfertGlobal->save();
        

        return response()->json([
            'success' => true,
            'message' => 'Transfert marqué comme payé',
            'data'    => $transfert->fresh(),
        ], 200);
    }

    public function disponible(Request $request, $id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null
            ], 400);
        }

        $transfert = TransfertInternational::find($id);

        if (!$transfert) {
            return response()->json([
                'success' => false,
                'message' => 'Transfert introuvable',
                'data'    => null
            ], 404);
        }

        if (!$transfert->peutPasser('DISPONIBLE')) {
            return response()->json([
                'success' => false,
                'message' => 'Ce transfert ne peut pas passer à l\'état DISPONIBLE — statut actuel : ' . $transfert->statut_transfert,
                'data'    => null
            ], 422);
        }

        $transfert->statut_transfert = 'DISPONIBLE';
        $transfert->save();

        return response()->json([
            'success' => true,
            'message' => 'Transfert marqué comme disponible',
            'data'    => $transfert->fresh(),
        ], 200);
    }

    public function recevoir(Request $request, $id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null
            ], 400);
        }

        $transfert = TransfertInternational::find($id);

        if (!$transfert) {
            return response()->json([
                'success' => false,
                'message' => 'Transfert introuvable',
                'data'    => null
            ], 404);
        }

        if (!$transfert->peutPasser('RECU')) {
            return response()->json([
                'success' => false,
                'message' => 'Ce transfert ne peut pas passer à l\'état RECU — statut actuel : ' . $transfert->statut_transfert,
                'data'    => null
            ], 422);
        }

        if ($transfert->estExpire()) {
            return response()->json([
                'success' => false,
                'message' => 'Ce transfert a expiré le ' . $transfert->date_expiration,
                'data'    => null
            ], 422);
        }

        $validated = $request->validate([
            'code_secret'        => 'required|string',
            'caisse_physique_id' => 'required|integer|exists:caisses,id',
            'force'              => 'nullable|boolean',
        ]);

        // Vérifier le code secret
        if ($transfert->code_secret != $validated['code_secret']) {
            return response()->json([
                'success' => false,
                'message' => 'Code secret incorrect',
                'data'    => null
            ], 422);
        }

        // Vérifier la caisse physique
        $caissePhysique = Caisses::find($validated['caisse_physique_id']);

        if ($caissePhysique->type_caisse !== 'PHYSIQUE') {
            return response()->json([
                'success' => false,
                'message' => 'La caisse doit être de type PHYSIQUE',
                'data'    => null,
            ], 422);
        }

        if ($caissePhysique->statut != 'OUVERTE') {
            return response()->json([
                'success' => false,
                'message' => 'La caisse physique doit être ouverte',
                'data'    => null,
            ], 422);
        }

        // Récupérer la devise de destination
        $devise = DB::table('devise')
            ->where('code', $transfert->devise_destination)
            ->first();

        if (!$devise) {
            return response()->json([
                'success' => false,
                'message' => 'Devise de destination introuvable',
                'data'    => null,
            ], 422);
        }

        // Débiter la caisse physique du montant converti
        $statutSolde = $caissePhysique->verifierSolde((float) $transfert->montant_converti, $devise->id);

        if ($statutSolde === 'solde_insuffisant') {
            return response()->json([
                'success' => false,
                'message' => 'Solde insuffisant dans la caisse physique pour payer le bénéficiaire',
                'data'    => null,
            ], 422);
        }

        if ($statutSolde === 'seuil_minimal_atteint' && !($validated['force'] ?? false)) {
            $soldeCaisse = \App\Models\SoldeCaisse::where('caisse_id', $caissePhysique->id)
                ->where('devise_id', $devise->id)->first();
            return response()->json([
                'success'       => false,
                'avertissement' => true,
                'message'       => 'Le seuil minimal de la caisse physique sera atteint après ce paiement',
                'data'          => [
                    'solde_actuel'   => (float) $soldeCaisse->solde,
                    'montant_requis' => (float) $transfert->montant_converti,
                    'solde_minimal'  => (float) $soldeCaisse->solde_minimal,
                    'solde_apres'    => round((float) $soldeCaisse->solde - $transfert->montant_converti, 4),
                    'action'         => 'Renvoyer avec force: true pour confirmer',
                ],
            ], 200);
        }

        $caissePhysique->debiter((float) $transfert->montant_converti, $devise->id);

        $transfert->statut_transfert = 'RECU';
        $transfert->date_reception   = now()->toDateTimeString();
        $transfert->save();

        return response()->json([
            'success' => true,
            'message' => 'Transfert reçu avec succès',
            'data'    => $transfert->fresh()->load(['operateur', 'succursale']),
        ], 200);
    }

    public function recevoirExterne(Request $request)
    {
        $validated = $request->validate([
            'operateur_transfert_id'             => 'required|integer|exists:operateurs_international,id',
            'type_operation'                     => 'required|in:ENVOI,RECEPTION',
            'reference_operateur'                => 'required|string|max:150',
            'code_secret'                        => 'required|string',
            'montant_principal'                  => 'required|numeric|min:0',
            'devise_origine'                     => 'required|string|max:30',
            'devise_destination'                 => 'nullable|string|max:30',
            'devise_source_id'                   => 'required|integer|exists:devise,id',
            'devise_dest_id'                     => 'required|integer|exists:devise,id',
            'motif_transfert'                    => 'nullable|string|max:255',
            'informations_expediteur'            => 'required|array',
            'informations_expediteur.nom'        => 'required|string',
            'informations_expediteur.telephone'  => 'nullable|string',
            'informations_expediteur.adresse'    => 'nullable|string',
            'informations_destinataire'          => 'required|array',
            'informations_destinataire.nom'      => 'required|string',
            'informations_destinataire.telephone'=> 'required|string',
            'informations_destinataire.adresse'  => 'required|string',
            'piece_identite_type'                => 'required|string|max:50',
            'piece_identite_numero'              => 'required|string|max:50',
            'succursale_id'                      => 'required|integer|exists:succursale,id',
            'caisse_physique_id'                 => 'required|integer|exists:caisses,id',
            'caissier_id'                        => 'nullable|integer|exists:utilisateur,id',
            'inclure_change'                     => 'nullable|boolean',
            'force'                              => 'nullable|boolean', // ← ajout
        ]);

        // Vérifier que l'opérateur est actif
        $operateur = OperateursInternational::find($validated['operateur_transfert_id']);

        if ($operateur->statut != 'ACTIF') {
            return response()->json([
                'success' => false,
                'message' => 'Cet opérateur est inactif',
                'data'    => null,
            ], 422);
        }

        // Vérifier que la référence opérateur n'existe pas déjà
        $existe = TransfertInternational::where('reference_operateur', $validated['reference_operateur'])->exists();

        if ($existe) {
            return response()->json([
                'success' => false,
                'message' => 'Cette référence opérateur a déjà été traitée',
                'data'    => null,
            ], 422);
        }

        // Vérifier la caisse physique
        $caissePhysique = Caisses::find($validated['caisse_physique_id']);

        if ($caissePhysique->type_caisse !== 'PHYSIQUE') {
            return response()->json([
                'success' => false,
                'message' => 'La caisse doit être de type PHYSIQUE',
                'data'    => null,
            ], 422);
        }

        if ($caissePhysique->statut != 'OUVERTE') {
            return response()->json([
                'success' => false,
                'message' => 'La caisse physique doit être ouverte',
                'data'    => null,
            ], 422);
        }

        // Conversion via le model
        $conversion = $operateur->convertir(
            (int) $validated['devise_source_id'],
            (int) $validated['devise_dest_id'],
            (float) $validated['montant_principal']
        );

        if ($conversion['sens'] === 'aucun_taux') {
            return response()->json([
                'success' => false,
                'message' => 'Aucun taux de change disponible pour ces devises',
                'data'    => null,
            ], 422);
        }

        // Récupérer la devise de destination
        $devise = DB::table('devise')
            ->where('code', $validated['devise_destination'])
            ->first();

        if (!$devise) {
            return response()->json([
                'success' => false,
                'message' => 'Devise de destination introuvable',
                'data'    => null,
            ], 422);
        }

        // ── Vérifier solde avant débit ────────────────────────────
        $statutSolde = $caissePhysique->verifierSolde(
            (float) $validated['montant_principal'],
            $devise->id
        );

        if ($statutSolde === 'solde_insuffisant') {
            return response()->json([
                'success' => false,
                'message' => 'Solde insuffisant dans la caisse physique pour payer le bénéficiaire',
                'data'    => null,
            ], 422);
        }

        if ($statutSolde === 'seuil_minimal_atteint' && !($validated['force'] ?? false)) {
            $soldeCaisse = \App\Models\SoldeCaisse::where('caisse_id', $caissePhysique->id)
                ->where('devise_id', $devise->id)
                ->first();

            return response()->json([
                'success'       => false,
                'avertissement' => true,
                'message'       => 'Le seuil minimal de la caisse physique sera atteint après ce paiement',
                'data'          => [
                    'solde_actuel'   => (float) $soldeCaisse->solde,
                    'montant_requis' => (float) $validated['montant_principal'],
                    'solde_minimal'  => (float) $soldeCaisse->solde_minimal,
                    'solde_apres'    => round((float) $soldeCaisse->solde - $validated['montant_principal'], 4),
                    'action'         => 'Renvoyer avec force: true pour confirmer',
                ],
            ], 200);
        }

        // Débiter la caisse physique
        $caissePhysique->debiter((float) $validated['montant_principal'], $devise->id);

        // Calculer les frais
        $frais = $operateur->calculerFraisReception((float) $validated['montant_principal']);

        $montantTotal = TransfertInternational::calculerTotal(
            (float) $validated['montant_principal'],
            $frais
        );

        // Détecter le seuil
        $user = auth()->user();
        $roleId = $user->role_id;
        $deviseId = $validated['devise_destination'];

        $seuil = Seuil_Transactionnels::detecter(
            'CHANGE',
            (float) $validated['montant_principal'],
            $deviseId,
            $roleId
        );


        if ($seuil->niveau_alerte === 'CRITIQUE') {
            return response()->json([
                'success' => false,
                'message' => 'Le montant dépasse le seuil  de transaction mobile autorisé.',
                'data'    => [
                    'seuil' => $seuil,
                ],
            ], 422);
        }

        // Créer la transaction globale
        $transactionGlobale = transaction_globale::create([
            'reference'            => transaction_globale::genererReference(),
            'type'                 => $validated['type_operation'] === 'ENVOI'
                                        ? 'TRANSFERT_INTERNATIONAL'
                                        : 'TRANSFERT_INTERNATIONAL_RECEPTION',
            'montant_local'        => $validated['montant_principal'],
            'devise_id'            => $validated['devise_source_id'],
            'date_transaction'     => now()->format('Y-m-d'),
            'statut'               => 'COMPLETEE',
            'commission_starehe'   => 0,
            'id_succursale'        => $validated['succursale_id'],
            'id_creator'           => auth()->id(),
            'caisse_id'            => $validated['caisse_physique_id'],
            'id_seuil_transaction' => $seuil ? $seuil->id : 1,
            'montant_devise_pivot' => $conversion['montant_converti'],
        ]);

        // Créer le transfert
        $transfert = TransfertInternational::create([
            'reference_transfert'       => TransfertInternational::genererReference($operateur->code_operateur),
            'reference_operateur'       => $validated['reference_operateur'],
            'operateur_transfert_id'    => $validated['operateur_transfert_id'],
            'type_operation'            => 'RECEPTION',
            'montant_principal'         => $validated['montant_principal'],
            'devise_origine'            => $validated['devise_origine'],
            'devise_destination'        => $validated['devise_destination'] ?? null,
            'montant_converti'          => $conversion['montant_converti'],
            'taux_change'               => $conversion['taux_utilise'],
            'frais_operateur'           => $frais,
            'montant_total'             => $montantTotal,
            'code_secret'               => $validated['code_secret'],
            'informations_expediteur'   => $validated['informations_expediteur'],
            'informations_destinataire' => $validated['informations_destinataire'],
            'piece_identite_type'       => $validated['piece_identite_type'],
            'piece_identite_numero'     => $validated['piece_identite_numero'],
            'statut_transfert'          => 'RECU',
            'date_reception'            => now()->toDateTimeString(),
            'date_expiration'           => now()->addDays(30),
            'succursale_id'             => $validated['succursale_id'],
            'caisse_id'                 => $validated['caisse_physique_id'],
            'caissier_id'               => $validated['caissier_id'] ?? null,
        ]);

        // Change inclus si demandé
        if ($validated['inclure_change'] ?? false) {
            $change = OperationChange::effectuer(
                $validated['succursale_id'],
                $validated['devise_source_id'],
                $validated['devise_dest_id'],
                (float) $validated['montant_principal'],
                $validated['caisse_physique_id'],
                $validated['caissier_id'] ?? null,
                true
            );

            if (!$change || isset($change['erreur'])) {
                return response()->json([
                    'success' => false,
                    'message' => $change['erreur'] ?? 'Aucun taux de change disponible',
                    'data'    => null,
                ], 422);
            }

            // Avertissement seuil dans le change inclus
            if (isset($change['avertissement'])) {
                return response()->json([
                    'success'       => false,
                    'avertissement' => true,
                    'message'       => $change['message'],
                    'data'          => array_merge($change, [
                        'action' => 'Renvoyer avec force: true pour confirmer',
                    ]),
                ], 200);
            }

            $transactionGlobale->operation_change_id  = $change['operation']->id;
            $transactionGlobale->montant_devise_pivot = $change['operation']->montant_cible;
            $transactionGlobale->save();
        }

        // Créer la commission
        $commission = Commission::creerPourTransaction($transactionGlobale, 'PAYEE');

        return response()->json([
            'success' => true,
            'message' => 'Réception externe enregistrée avec succès',
            'data'    => [
                'transfert'           => $transfert->load(['operateur', 'succursale']),
                'conversion'          => $conversion,
                'transaction_globale' => $transactionGlobale,
                'commission'          => $commission,
                'niveau_alerte'       => $seuil?->niveau_alerte,
            ],
        ], 201);
    }
}