<?php

// app/Http/Controllers/Approvisionnement/OperationController.php

namespace App\Http\Controllers\Approvisionnement;

use App\Http\Controllers\Controller;
use App\Models\Approvisionnement;
use App\Models\DecisionOperation;
use App\Models\StockSuccursale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Traits\Notifiable;

class OperationController extends Controller
{
    use Notifiable;

    /**
     * Dashboard OPS
     */
    public function dashboard()
    {
        $user = auth()->user();

        if (!$user->hasPermission('ops-approvisionnement')) {
            return response()->json([
                'success' => false,
                'message' => 'Accès réservé au Responsable des Opérations'
            ], 403);
        }

        $stats = [
            'en_attente' => Approvisionnement::enAttenteOps()->count(),
            'en_attente_logistique' => Approvisionnement::enAttenteLogistique()->count(),
            'valides' => Approvisionnement::valides()->count(),
            'en_transit' => Approvisionnement::enTransit()->count(),
            'recus' => Approvisionnement::where('statut_demande', 'Received')->count(),
            'rejetes' => Approvisionnement::where('statut_operation', 'REJETE')->count(),
            'avec_ecart' => Approvisionnement::where('statut_demande', 'Partially Received')->count()
        ];

        $enAttente = Approvisionnement::enAttenteOps()
            ->with([
                'destinationSuccursale',
                'sourceSuccursale',
                'lignes.produit',
                'demandeur'
            ])
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'stats' => $stats,
            'en_attente_validation' => $enAttente
        ]);
    }

    /**
     * Afficher une demande pour décision OPS
     * OPS n'a plus le choix de la source - il valide ou rejète uniquement
     */
    public function show($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('ops-approvisionnement')) {
            return response()->json([
                'success' => false,
                'message' => 'Accès réservé au Responsable des Opérations'
            ], 403);
        }

        $demande = Approvisionnement::with([
            'destinationSuccursale',
            'sourceSuccursale',
            'lignes.produit',
            'demandeur',
            'decisionsOps.user'
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $demande
        ]);
    }

    /**
     * Prendre une décision OPS (APPROUVE / REJETE)
     * OPS ne fait que valider ou rejeter - PLUS DE CHOIX DE SUCCURSALE
     * Le choix de la succursale et du type de demande est fait par le Directeur Logistique
     */
    public function decision(Request $request, $id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('ops-approvisionnement')) {
            return response()->json([
                'success' => false,
                'message' => 'Accès réservé au Responsable des Opérations'
            ], 403);
        }

        $demande = Approvisionnement::lockForUpdate()
            ->with(['lignes.produit', 'destinationSuccursale', 'demandeur'])
            ->findOrFail($id);

        if ($demande->statut_operation !== 'EN_ATTENTE_OPERATION') {
            return response()->json([
                'success' => false,
                'message' => 'Cette demande a déjà été traitée'
            ], 422);
        }

        $rules = [
            'decision' => 'required|in:APPROUVE,REJETE',
            'date_livraison_souhaitee' => 'nullable|date|after_or_equal:today',

            'modifier_quantites' => 'sometimes|boolean',

            'lignes' => 'nullable|array',

            'lignes.*.ligne_id' => 'required|exists:lignes_approvisionnements,id',

            'lignes.*.produit_id' => 'required|exists:produit,id',

            'lignes.*.quantite_demandee' => 'required|integer|min:1',

            'lignes_supprimees' => 'nullable|array',

            'lignes_supprimees.*' => 'exists:lignes_approvisionnements,id'
        ];

        if ($request->decision === 'REJETE') {
            $rules['motif_rejet'] = 'required|string|min:3';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $detailsModifications = [];
            $modificationsEffectuees = false;

            /**
             * ============================================
             * SUPPRESSION DES LIGNES
             * ============================================
             */
            if ($request->filled('lignes_supprimees')) {
                $lignesASupprimer = DB::table('lignes_approvisionnements')
                    ->whereIn('id', $request->lignes_supprimees)
                    ->where('approvisionnement_id', $demande->id)
                    ->get();

                foreach ($lignesASupprimer as $ligne) {
                    $detailsModifications[] = [
                        'type' => 'suppression',
                        'ligne_id' => $ligne->id,
                        'ancienne_quantite' => $ligne->quantite_demandee
                    ];
                }

                DB::table('lignes_approvisionnements')
                    ->whereIn('id', $request->lignes_supprimees)
                    ->where('approvisionnement_id', $demande->id)
                    ->delete();

                $modificationsEffectuees = true;
            }

            /**
             * ============================================
             * AJOUT / MODIFICATION
             * ============================================
             */

        if ($request->filled('lignes')) {
        
            foreach ($request->lignes as $ligneData) {
        
                if (empty($ligneData['ligne_id'])) {
                    throw new \Exception(
                        "ligne_id obligatoire pour modifier une ligne."
                    );
                }
        
                $ligneExistante = DB::table('lignes_approvisionnements')
                    ->where('id', $ligneData['ligne_id'])
                    ->where('approvisionnement_id', $demande->id)
                    ->first();
        
                if (!$ligneExistante) {
                    throw new \Exception(
                        "La ligne {$ligneData['ligne_id']} est introuvable."
                    );
                }
        
                $ancienneQuantite = (int) $ligneExistante->quantite_demandee;
                $nouvelleQuantite = (int) $ligneData['quantite_demandee'];
        
                if ($ancienneQuantite != $nouvelleQuantite) {
        
                    DB::table('lignes_approvisionnements')
                        ->where('id', $ligneExistante->id)
                        ->update([
                            'quantite_demandee' => $nouvelleQuantite,
                            'updated_at' => now()
                        ]);
        
                    $detailsModifications[] = [
                        'type' => 'modification',
                        'ligne_id' => $ligneExistante->id,
                        'ancienne' => $ancienneQuantite,
                        'nouvelle' => $nouvelleQuantite,
                        'difference' => $nouvelleQuantite - $ancienneQuantite
                    ];
        
                    $modificationsEffectuees = true;
                }
            }
        }

            /**
             * ============================================
             * RECALCUL TOTAL
             * ============================================
             */
            $nouveauTotal = DB::table('lignes_approvisionnements')
                ->where('approvisionnement_id', $demande->id)
                ->sum('quantite_demandee');

            /**
             * ============================================
             * HISTORIQUE
             * ============================================
             */
            DecisionOperation::create([
                'approvisionnement_id' => $demande->id,
                'user_id' => $user->id,
                'decision' => $request->decision,
                'source_decidee_id' => null,
                'motif_rejet' => $request->motif_rejet ?? null,
                'commentaire' => $request->commentaire ?? null
            ]);

            /**
             * ============================================
             * APPROBATION
             * ============================================
             */
            if ($request->decision === 'APPROUVE') {
                $notes = $demande->notes ?? '';

                $notes .= "\n[" . now()->format('d/m/Y H:i') . "]";
                $notes .= " APPROUVÉ PAR OPS ({$user->nom_complet})";

                if ($modificationsEffectuees) {
                    $notes .= "\nModifications des lignes effectuées";
                }

                $demande->update([
                    'quantite_demandee' => $nouveauTotal,
                    'date_livraison_souhaitee' => $request->date_livraison_souhaitee,
                    'statut_demande' => 'Approved',
                    'statut_operation' => 'EN_ATTENTE_LOGISTIQUE',
                    'validateur_ops_id' => $user->id,
                    'date_validation_ops' => now(),
                    'notes' => $notes
                ]);
            } else {
                /**
                 * ========================================
                 * REJET
                 * ========================================
                 */
                $demande->update([
                    'statut_demande' => 'Rejected',
                    'statut_operation' => 'REJETE',
                    'validateur_ops_id' => $user->id,
                    'date_validation_ops' => now(),
                    'notes' => $request->motif_rejet
                ]);
            }

            DB::commit();

            $demande->load([
                'lignes.produit',
                'destinationSuccursale',
                'sourceSuccursale'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Décision enregistrée avec succès',
                'data' => $demande,
                'modifications' => $detailsModifications
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function rejeter($id, Request $request)
    {
        $user = auth()->user();
        
        $demande = Approvisionnement::findOrFail($id);
        
        if ($demande->statut_approbation_logistique !== 'EN_ATTENTE') {
            return response()->json([
                'success' => false,
                'message' => 'Cette demande n\'est pas en attente d\'approbation'
            ], 422);
        }
        
        $validator = Validator::make($request->all(), [
            'motif' => 'required|string|min:3'
        ]);
        
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        
        try {
            $result = $this->validationService->rejeterParSuperieur(
                $demande,
                $user,
                $request->motif
            );
            
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // ============================================================
    // MÉTHODES PRIVÉES DE NOTIFICATION (OPS)
    // ============================================================

    private function notifierDemandeApprouvee($demande, $user, $modificationsEffectuees = false, $detailsModifications = [])
    {
        $produitsListe = $demande->lignes->map(function($ligne) {
            return $ligne->quantite_demandee . 'x ' . ($ligne->produit->nom ?? 'Produit inconnu');
        })->implode(', ');
        
        $messageModification = '';
        if ($modificationsEffectuees && !empty($detailsModifications)) {
            $messageModification = "\n\n📝 MODIFICATIONS EFFECTUÉES PAR OPS:\n";
            foreach ($detailsModifications as $modif) {
                $signe = $modif['difference'] > 0 ? '+' : '';
                $messageModification .= "- {$modif['produit']}: {$modif['ancienne']} → {$modif['nouvelle']} ({$signe}{$modif['difference']})\n";
            }
        }

        if ($demande->demandeur_id) {
            $this->notifierDestinatairesSpecifiques([
                'destinataires' => [
                    'type' => 'utilisateur',
                    'id' => $demande->demandeur_id
                ],
                'type' => 'APPROVISIONNEMENT_VALIDE',
                'titre' => $modificationsEffectuees ? "✅ Demande approuvée avec modifications OPS" : "✅ Votre demande a été approuvée par OPS !",
                'message' => "Demande #{$demande->reference} approuvée par {$user->nom_complet}. En attente de validation logistique (choix de la source et du type de demande)." . $messageModification,
                'donnees' => [
                    'demande_id' => $demande->id,
                    'reference' => $demande->reference,
                    'date_livraison_souhaitee' => $demande->date_livraison_souhaitee,
                    'produits' => $produitsListe,
                    'quantites_modifiees' => $modificationsEffectuees,
                    'details_modifications' => $detailsModifications
                ],
                'succursale_id' => $demande->destination_succursale_id,
                'priorite' => 'HAUTE'
            ]);
        }
    }

    private function notifierDemandeEnAttenteLogistique($demande, $user, $modificationsEffectuees = false, $detailsModifications = [])
    {
        $produitsListe = $demande->lignes->map(function($ligne) {
            return $ligne->quantite_demandee . 'x ' . ($ligne->produit->nom ?? 'Produit inconnu');
        })->implode(', ');
        
        $messageModification = '';
        if ($modificationsEffectuees && !empty($detailsModifications)) {
            $messageModification = "\n\n📝 Quantités modifiées par OPS:\n";
            foreach ($detailsModifications as $modif) {
                $signe = $modif['difference'] > 0 ? '+' : '';
                $messageModification .= "- {$modif['produit']}: {$modif['ancienne']} → {$modif['nouvelle']} ({$signe}{$modif['difference']})\n";
            }
        }

        // Notifier le directeur logistique
        $this->notifierDestinatairesSpecifiques([
            'destinataires' => [
                'type' => 'role',
                'code' => 'CHEF_OPERATIONS'
            ],
            'type' => 'DEMANDE_APPROVISIONNEMENT',
            'titre' => "🚚 Validation logistique requise" . ($modificationsEffectuees ? " - Quantités modifiées" : ""),
            'message' => "Demande #{$demande->reference} - Destination: {$demande->destinationSuccursale->nom} - À vous de choisir la source et le type de demande" . $messageModification,
            'donnees' => [
                'demande_id' => $demande->id,
                'reference' => $demande->reference,
                'destination_nom' => $demande->destinationSuccursale->nom,
                'produits' => $produitsListe,
                'quantite_totale' => $demande->quantite_demandee,
                'quantites_modifiees' => $modificationsEffectuees
            ],
            'priorite' => 'HAUTE'
        ]);

        // Notifier aussi les chefs d'agence
        $this->notifierDestinatairesSpecifiques([
            'destinataires' => [
                'type' => 'role',
                'code' => 'CHEF_AGENCE'
            ],
            'type' => 'APPROVISIONNEMENT_VALIDE',
            'titre' => "📦 Demande approuvée - En attente logistique" . ($modificationsEffectuees ? " (quantités ajustées)" : ""),
            'message' => "Demande #{$demande->reference} approuvée par OPS. En attente d'organisation du transport (choix source et type de demande par logistique)." . $messageModification,
            'donnees' => [
                'demande_id' => $demande->id,
                'reference' => $demande->reference
            ],
            'priorite' => 'NORMALE'
        ]);
    }

    private function notifierDemandeRejetee($demande, $user, $motif)
    {
        if ($demande->demandeur_id) {
            $this->notifierDestinatairesSpecifiques([
                'destinataires' => [
                    'type' => 'utilisateur',
                    'id' => $demande->demandeur_id
                ],
                'type' => 'DEMANDE_APPROVISIONNEMENT',
                'titre' => "❌ Votre demande a été rejetée",
                'message' => "Demande #{$demande->reference} rejetée par OPS. Motif: {$motif}",
                'donnees' => [
                    'demande_id' => $demande->id,
                    'reference' => $demande->reference,
                    'motif' => $motif
                ],
                'succursale_id' => $demande->destination_succursale_id,
                'priorite' => 'HAUTE'
            ]);
        }
    }
}