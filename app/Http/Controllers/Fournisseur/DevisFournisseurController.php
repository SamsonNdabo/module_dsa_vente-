<?php
// app/Http/Controllers/Fournisseur/DevisFournisseurController.php

namespace App\Http\Controllers\Fournisseur;

use App\Http\Controllers\Controller;
use App\Models\DevisFournisseur;
use App\Models\RfqFournisseur;
use App\Models\CommandeFournisseur;
use App\Models\DevisLigne;
use App\Models\LigneCommandeFournisseur;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\Produit;
use Illuminate\Support\Facades\Mail;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema; 

class DevisFournisseurController extends Controller
{
    //  Constantes
    private const ROLES_FULL_ACCESS = ['SUPER_ADMIN', 'AUDITEUR', 'CHEF_OPERATIONS'];
    private const STATUTS_MODIFIABLES = ['RECU', 'EN_NEGOCIATION'];
    private const STATUTS_SUPPRIMABLES = ['RECU', 'EN_NEGOCIATION', 'EXPIRE'];

    /**
     * Afficher la liste des devis - OPTIMISÉ
     * Permission: view-devis
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-devis')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les devis'
            ], 403);
        }

        $query = DevisFournisseur::select([
            'id', 'reference', 'rfq_id', 'fournisseur_id', 'date_devis',
            'date_validite', 'statut', 'frais_livraison', 'remise_globale',
            'conditions_paiement', 'delai_livraison', 'notes', 'notes_internes',
            'fichier_pdf', 'valide_par_id', 'date_validation',
            'created_at', 'updated_at'
        ])->with([
            'fournisseur' => function($q) {
                $q->select('id', 'nom', 'code_fournisseur', 'email', 'telephone');
            },
            'validePar' => function($q) {
                $q->select('id', 'nom_complet', 'email');
            },
            'rfq' => function($q) {
                $q->select('id', 'reference', 'rfq_group_uuid', 'statut', 'fournisseur_id','devise_id');
            }
        ]);


        if (!in_array($user->role?->code, self::ROLES_FULL_ACCESS) && !$user->hasPermission('manage-all-users')) {
            

            
            if (Schema::hasColumn('devis_fournisseurs', 'cree_par_id')) {
                $query->where('cree_par_id', $user->id);
            } else {
                
                $query->whereHas('rfq', function($q) use ($user) {
                    $q->where('cree_par_id', $user->id);
                });
            }
        }

        //  Filtres
        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }

        if ($request->filled('fournisseur_id')) {
            $query->where('fournisseur_id', $request->fournisseur_id);
        }

        if ($request->filled('rfq_id')) {
            $query->where('rfq_id', $request->rfq_id);
        }

        if ($request->filled('rfq_group_uuid')) {
            $rfqIds = RfqFournisseur::where('rfq_group_uuid', $request->rfq_group_uuid)->pluck('id');
            $query->whereIn('rfq_id', $rfqIds);
        }

        if ($request->filled('recherche')) {
            $search = $request->recherche;
            $query->where(function($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                  ->orWhereHas('fournisseur', function($sq) use ($search) {
                      $sq->where('nom', 'like', "%{$search}%")
                         ->orWhere('code_fournisseur', 'like', "%{$search}%");
                  });
            });
        }

        $perPage = $request->get('per_page', 20);
        $devis = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json(['success' => true, 'data' => $devis]);
    }

    /**
     * Créer un devis à partir d'une RFQ - OPTIMISÉ
     * Permission: create-devis
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('create-devis')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour créer des devis'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'rfq_id' => 'required|exists:rfq_fournisseurs,id',
            'fournisseur_id' => 'required|exists:fournisseur,id',
            'date_devis' => 'required|date',
            'date_validite' => 'nullable|date|after:date_devis',
            'frais_livraison' => 'nullable|numeric|min:0',
            'remise_globale' => 'nullable|numeric|min:0|max:100',
            'conditions_paiement' => 'nullable|string',
            'delai_livraison' => 'nullable|string',
            'notes' => 'nullable|string',
            'lignes' => 'nullable|array',
            'lignes.*.produit_id' => 'nullable|exists:produit,id',
            'lignes.*.libelle' => 'nullable|string',
            'lignes.*.quantite' => 'nullable|integer|min:1',
            'lignes.*.prix_unitaire_ht' => 'required|numeric|min:0',
            'lignes.*.remise_ligne' => 'nullable|numeric|min:0|max:100',
            'lignes.*.taux_tva' => 'nullable|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();

        try {
            $rfq = RfqFournisseur::with(['lignes.produit'])->findOrFail($request->rfq_id);

            $devis = DevisFournisseur::create([
                'rfq_id' => $request->rfq_id,
                'fournisseur_id' => $request->fournisseur_id,
                'date_devis' => $request->date_devis,
                'date_validite' => $request->date_validite,
                'frais_livraison' => $request->frais_livraison ?? 0,
                'remise_globale' => $request->remise_globale ?? 0,
                'conditions_paiement' => $request->conditions_paiement,
                'delai_livraison' => $request->delai_livraison,
                'notes' => $request->notes,
                //  Utiliser 'cree_par_id' si la colonne existe, sinon 'created_by'
                'cree_par_id' => auth()->id()
            ]);

            //  Création des lignes en BULK
            $lignesData = $this->prepareLignesData($request, $rfq, $devis->id);

            if (!empty($lignesData)) {
                DevisLigne::insert($lignesData);
            }

            //  Mettre à jour le statut de la RFQ
            if ($rfq && $rfq->statut === 'ENVOYEE') {
                $rfq->marquerReponseRecue();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Devis créé avec succès',
                'data' => $devis->load([
                    'fournisseur' => function($q) {
                        $q->select('id', 'nom', 'code_fournisseur');
                    },
                    'lignes.produit' => function($q) {
                        $q->select('id', 'nom', 'code_barre','prix_vente','prix_vente_TTC');
                    }
                ])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur création devis', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Afficher un devis - OPTIMISÉ
     */
    public function show($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-devis')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les devis'
            ], 403);
        }

        $devis = DevisFournisseur::select([
            'id', 'reference', 'rfq_id', 'fournisseur_id', 'date_devis',
            'date_validite', 'statut', 'frais_livraison', 'remise_globale',
            'conditions_paiement', 'delai_livraison', 'notes', 'notes_internes',
            'fichier_pdf', 'valide_par_id', 'date_validation',
            'metadata', 'created_at', 'updated_at'
        ])->with([
            'fournisseur' => function($q) {
                $q->select('id', 'nom', 'code_fournisseur', 'email', 'telephone', 'adresse', 'ville');
            },
            'lignes' => function($q) {
                $q->select('id', 'devis_id', 'produit_id', 'libelle', 'quantite',
                          'prix_unitaire_ht', 'remise_ligne', 'taux_tva', 'notes')
                  ->with(['produit' => function($pq) {
                      $pq->select('id', 'nom', 'code_barre', 'type','prix_vente','prix_vente_TTC');
                  }]);
            },
            'rfq' => function($q) {
                $q->select('id', 'reference', 'rfq_group_uuid', 'statut', 'fournisseur_id');
            },
            'validePar' => function($q) {
                $q->select('id', 'nom_complet', 'email');
            }
        ])->findOrFail($id);

        //  Vérifier si les attributs existent avant de les utiliser
        if (method_exists($devis, 'getHasFichierPdfAttribute')) {
            $devis->has_pdf = $devis->has_fichier_pdf;
        } else {
            $devis->has_pdf = !empty($devis->fichier_pdf);
        }

        if (method_exists($devis, 'getFichierPdfUrlAttribute')) {
            $devis->fichier_pdf_url = $devis->fichier_pdf_url;
        } else {
            $devis->fichier_pdf_url = $devis->fichier_pdf ? Storage::url($devis->fichier_pdf) : null;
        }

        return response()->json(['success' => true, 'data' => $devis]);
    }

    // ============================================================
    // MÉTHODES DE GESTION DES FICHIERS PDF
    // ============================================================

    public function uploadPdf(Request $request, $id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('create-devis')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour uploader un fichier'
            ], 403);
        }

        $devis = DevisFournisseur::findOrFail($id);

        if (in_array($devis->statut, ['REFUSE', 'EXPIRE', 'VALIDE'])) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible d\'uploader un fichier sur un devis déjà traité'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'fichier_pdf' => 'required|file|mimes:pdf|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            if ($devis->fichier_pdf && Storage::disk('public')->exists($devis->fichier_pdf)) {
                Storage::disk('public')->delete($devis->fichier_pdf);
            }

            $fileName = 'devis_' . $devis->reference . '_' . time() . '.pdf';
            $path = $request->file('fichier_pdf')->storeAs('devis_pdfs', $fileName, 'public');

            $devis->fichier_pdf = $path;

            $metadata = $devis->metadata ?? [];
            $metadata['pdf_uploaded_by'] = $user->id;
            $metadata['pdf_uploaded_by_name'] = $user->nom_complet;
            $metadata['pdf_uploaded_at'] = now()->toDateTimeString();
            $metadata['pdf_original_name'] = $request->file('fichier_pdf')->getClientOriginalName();
            $metadata['pdf_size'] = $request->file('fichier_pdf')->getSize();
            $devis->metadata = $metadata;

            $devis->save();

            return response()->json([
                'success' => true,
                'message' => 'Fichier PDF uploadé avec succès',
                'data' => [
                    'fichier_pdf_url' => Storage::url($path),
                    'fichier_pdf' => $path,
                    'metadata' => $metadata
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur upload PDF devis', [
                'devis_id' => $id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'upload: ' . $e->getMessage()
            ], 500);
        }
    }

    public function downloadPdf($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-devis')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour télécharger ce fichier'
            ], 403);
        }

        $devis = DevisFournisseur::findOrFail($id);

        if (!$devis->fichier_pdf || !Storage::disk('public')->exists($devis->fichier_pdf)) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun fichier PDF trouvé pour ce devis'
            ], 404);
        }

        $originalName = $devis->metadata['pdf_original_name'] ?? "Devis_{$devis->reference}.pdf";

        return Storage::disk('public')->download($devis->fichier_pdf, $originalName);
    }

    public function deletePdf($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('create-devis')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour supprimer ce fichier'
            ], 403);
        }

        $devis = DevisFournisseur::findOrFail($id);

        if (in_array($devis->statut, ['VALIDE', 'REFUSE', 'EXPIRE'])) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer un fichier sur un devis déjà traité'
            ], 422);
        }

        try {
            if ($devis->fichier_pdf && Storage::disk('public')->exists($devis->fichier_pdf)) {
                Storage::disk('public')->delete($devis->fichier_pdf);
            }

            $devis->fichier_pdf = null;

            $metadata = $devis->metadata ?? [];
            $metadata['pdf_deleted_at'] = now()->toDateTimeString();
            $metadata['pdf_deleted_by'] = $user->id;
            $metadata['pdf_deleted_by_name'] = $user->nom_complet;
            $devis->metadata = $metadata;

            $devis->save();

            return response()->json([
                'success' => true,
                'message' => 'Fichier PDF supprimé avec succès'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression: ' . $e->getMessage()
            ], 500);
        }
    }

    // ============================================================
    // MÉTHODES DE GESTION DES DEVIS
    // ============================================================

        public function update(Request $request, $id)
    {
        $user = auth()->user();
    
        if (!$user->hasPermission('create-devis')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour modifier des devis'
            ], 403);
        }
    
        $devis = DevisFournisseur::findOrFail($id);
    
        if (in_array($devis->statut, ['VALIDE', 'REFUSE', 'EXPIRE'])) {
            return response()->json([
                'success' => false,
                'message' => 'Ce devis ne peut plus être modifié (statut: ' . $devis->statut . ')'
            ], 422);
        }
    
        $validator = Validator::make($request->all(), [
            'date_devis' => 'sometimes|date',
            'date_validite' => 'nullable|date|after:date_devis',
            'frais_livraison' => 'nullable|numeric|min:0',
            'remise_globale' => 'nullable|numeric|min:0|max:100',
            'conditions_paiement' => 'nullable|string',
            'delai_livraison' => 'nullable|string',
            'notes' => 'nullable|string',
    
            'lignes' => 'sometimes|array|min:1',
    
            // Ligne existante
            'lignes.*.id' => 'nullable|exists:devis_lignes,id',
    
            // Produit obligatoire UNIQUEMENT pour une nouvelle ligne
            'lignes.*.produit_id' => 'required_without:lignes.*.id|exists:produit,id',
    
            'lignes.*.libelle' => 'required_with:lignes|string',
            'lignes.*.quantite' => 'required_with:lignes|integer|min:1',
            'lignes.*.prix_unitaire_ht' => 'required_with:lignes|numeric|min:0',
            'lignes.*.remise_ligne' => 'nullable|numeric|min:0|max:100',
            'lignes.*.taux_tva' => 'nullable|numeric|min:0|max:100',
            'lignes.*.notes' => 'nullable|string'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
    
        DB::beginTransaction();
    
        try {
    
            $devis->update($request->only([
                'date_devis',
                'date_validite',
                'frais_livraison',
                'remise_globale',
                'conditions_paiement',
                'delai_livraison',
                'notes'
            ]));
    
            if ($request->has('lignes')) {
                $this->updateLignes($devis, $request->lignes);
            }
    
            if ($devis->statut === 'RECU') {
                $devis->statut = 'EN_NEGOCIATION';
                $devis->save();
            }
    
            DB::commit();
    
            return response()->json([
                'success' => true,
                'message' => 'Devis mis à jour avec succès',
                'data' => $devis->load([
                    'fournisseur' => function ($q) {
                        $q->select('id', 'nom');
                    },
                    'lignes.produit' => function ($q) {
                        $q->select('id', 'nom');
                    }
                ])
            ]);
    
        } catch (\Exception $e) {
    
            DB::rollBack();
    
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('create-devis')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour supprimer des devis'
            ], 403);
        }

        $devis = DevisFournisseur::findOrFail($id);

        if (!in_array($devis->statut, self::STATUTS_SUPPRIMABLES)) {
            return response()->json([
                'success' => false,
                'message' => 'Ce devis ne peut pas être supprimé (statut: ' . $devis->statut . ')'
            ], 422);
        }

        $devis->delete();

        return response()->json([
            'success' => true,
            'message' => 'Devis supprimé avec succès'
        ]);
    }

    public function valider($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('validate-devis')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour valider des devis'
            ], 403);
        }

        $devis = DevisFournisseur::findOrFail($id);

        try {
            $devis->valider($user->id);
            return response()->json(['success' => true, 'message' => 'Devis validé avec succès']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function refuser(Request $request, $id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('validate-devis')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour refuser des devis'
            ], 403);
        }

        $devis = DevisFournisseur::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'motif' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $devis->refuser($request->motif);

        return response()->json(['success' => true, 'message' => 'Devis refusé']);
    }

    // ============================================================
    // MÉTHODES D'EXPORT PDF
    // ============================================================

    public function exporterPdf($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-devis')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour exporter les devis'
            ], 403);
        }

        $devis = DevisFournisseur::with([
            'fournisseur' => function($q) {
                $q->select('id', 'nom', 'code_fournisseur', 'email', 'telephone', 'adresse');
            },
            'lignes.produit' => function($q) {
                $q->select('id', 'nom', 'code_barre');
            },
            'rfq' => function($q) {
                $q->select('id', 'reference');
            }
        ])->findOrFail($id);

        $totaux = $this->calculerTotauxDevis($devis);

        $data = [
            'devis' => $devis,
            'fournisseur' => $devis->fournisseur,
            'lignes' => $devis->lignes,
            'rfq' => $devis->rfq,
            'totaux' => $totaux,
            'date_creation' => $devis->created_at->format('d/m/Y H:i'),
            'date_validite' => $devis->date_validite ? $devis->date_validite->format('d/m/Y') : 'Non spécifiée'
        ];

        $pdf = PDF::loadView('pdf.devis', $data);
        $pdf->setPaper('A4', 'portrait');

        return $pdf->download("Devis_{$devis->reference}.pdf");
    }

        public function previewPdf($id)
    {
        $devis = DevisFournisseur::findOrFail($id);
    
        if (!$devis->fichier_pdf || !Storage::disk('public')->exists($devis->fichier_pdf)) {
            return response()->json([
                'success' => false,
                'message' => 'Fichier PDF introuvable.'
            ], 404);
        }
    
        return response()->json([
            'success' => true,
            'data' => [
                'url' => Storage::disk('public')->url($devis->fichier_pdf),
                'filename' => basename($devis->fichier_pdf),
                'mime_type' => 'application/pdf'
            ]
        ]);
    }

    // ============================================================
    // MÉTHODES DE CRÉATION DE COMMANDE
    // ============================================================

        public function creerCommande($id)
    {
        $user = auth()->user();
    
        if (!$user->hasPermission('validate-devis')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour créer des commandes à partir de devis'
            ], 403);
        }
    
        $devis = DevisFournisseur::with([
            'fournisseur',
            'lignes' => function($q) {
                $q->select(
                    'id',
                    'devis_id',
                    'produit_id',
                    'libelle',
                    'quantite',
                    'prix_unitaire_ht',
                    'remise_ligne',
                    'taux_tva'
                );
            }
        ])->findOrFail($id);
    
    
        if ($devis->statut !== 'VALIDE') {
            return response()->json([
                'success' => false,
                'message' => 'Seul un devis validé peut générer une commande'
            ], 422);
        }
    
    
        // Empêcher les doublons
        $commandeExistante = CommandeFournisseur::where('devis_id', $devis->id)
            ->first();
    
        if ($commandeExistante) {
            return response()->json([
                'success' => false,
                'message' => 'Une commande existe déjà pour ce devis',
                'commande_id' => $commandeExistante->id
            ], 409);
        }
    
    
        DB::beginTransaction();
    
        try {
    
            // Création commande
            $commande = CommandeFournisseur::create([
                'devis_id' => $devis->id,
                'fournisseur_id' => $devis->fournisseur_id,
                'date_livraison_prevue' => $devis->delai_livraison
                    ? now()->addDays((int) $devis->delai_livraison)
                    : null,
                'conditions_paiement' => $devis->conditions_paiement,
                'cree_par_id' => auth()->id(),
                'statut' => 'EN_ATTENTE'
            ]);
    
    
            // Création des lignes
            $lignesData = [];
    
            foreach ($devis->lignes as $ligneDevis) {
    
                $lignesData[] = [
                    'commande_id' => $commande->id,
                    'produit_id' => $ligneDevis->produit_id,
                    'libelle' => $ligneDevis->libelle,
                    'quantite' => $ligneDevis->quantite,
    
                    'metadata' => json_encode([
                        'prix_unitaire_ht' => $ligneDevis->prix_unitaire_ht,
                        'taux_tva' => $ligneDevis->taux_tva,
                        'remise' => $ligneDevis->remise_ligne
                    ]),
    
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }
    
    
            if (!empty($lignesData)) {
                LigneCommandeFournisseur::insert($lignesData);
            }
    
    
            // Calcul des montants avant email
            $commande->calculerMontantsDepuisDevis();
    
            $commande->refresh();
    
    
            // Mise à jour devis
            $devis->update([
                'statut' => 'TRANSFORME'
            ]);
    
    
            // Charger toutes les données nécessaires au mail
            $commande->load([
                'fournisseur',
                'lignes.produit',
                'devis'
            ]);
    
    
            // Envoi email fournisseur
            try {
    
                $commande->envoyerEmailFournisseur();
    
            } catch (\Exception $e) {
    
                Log::error('Erreur envoi email commande fournisseur', [
                    'commande_id' => $commande->id,
                    'error' => $e->getMessage()
                ]);
    
                // On ne bloque pas la création commande
            }
    
    
            DB::commit();
    
    
            return response()->json([
                'success' => true,
                'message' => 'Commande créée à partir du devis et envoyée au fournisseur',
    
                'data' => $commande->load([
                    'fournisseur' => function($q) {
                        $q->select('id', 'nom');
                    },
    
                    'lignes.produit' => function($q) {
                        $q->select('id', 'nom');
                    },
    
                    'devis' => function($q) {
                        $q->select('id', 'reference');
                    }
                ])
    
            ], 201);
    
    
    
        } catch (\Exception $e) {
    
            DB::rollBack();
    
    
            Log::error('Erreur création commande depuis devis', [
                'devis_id' => $id,
                'error' => $e->getMessage()
            ]);
    
    
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // ============================================================
    // MÉTHODES DE COMPARAISON (simplifiées)
    // ============================================================

    public function comparerGroupe($rfqGroupUuid)
    {
        $user = auth()->user();

        if (!$user->hasPermission('compare-devis')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour comparer des devis'
            ], 403);
        }

        $rfqs = RfqFournisseur::with([
            'fournisseur' => function($q) {
                $q->select('id', 'nom');
            },
            'lignes.produit' => function($q) {
                $q->select('id', 'nom');
            }
        ])->where('rfq_group_uuid', $rfqGroupUuid)->get();

        if ($rfqs->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Groupe RFQ non trouvé'
            ], 404);
        }

        $rfqIds = $rfqs->pluck('id');
        $devisListe = DevisFournisseur::with([
            'fournisseur' => function($q) {
                $q->select('id', 'nom');
            },
            'lignes.produit' => function($q) {
                $q->select('id', 'nom');
            }
        ])->whereIn('rfq_id', $rfqIds)
          ->whereIn('statut', ['RECU', 'EN_NEGOCIATION', 'VALIDE'])
          ->get();

        if ($devisListe->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun devis reçu pour ce groupe RFQ'
            ], 404);
        }

        $comparaison = $this->calculerComparaisonComplete($rfqs, $devisListe);

        return response()->json([
            'success' => true,
            'data' => $comparaison
        ]);
    }

    public function comparerParRfq($rfqId)
    {
        $user = auth()->user();

        if (!$user->hasPermission('compare-devis')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour comparer des devis'
            ], 403);
        }

        $rfq = RfqFournisseur::with([
            'fournisseur' => function($q) {
                $q->select('id', 'nom');
            },
            'lignes.produit' => function($q) {
                $q->select('id', 'nom');
            }
        ])->findOrFail($rfqId);

        if ($rfq->rfq_group_uuid) {
            return $this->comparerGroupe($rfq->rfq_group_uuid);
        }

        $devisListe = DevisFournisseur::with([
            'fournisseur' => function($q) {
                $q->select('id', 'nom');
            },
            'lignes.produit' => function($q) {
                $q->select('id', 'nom');
            }
        ])->where('rfq_id', $rfqId)
          ->whereIn('statut', ['RECU', 'EN_NEGOCIATION', 'VALIDE'])
          ->get();

        if ($devisListe->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun devis reçu pour cette RFQ'
            ], 404);
        }

        $comparaison = $this->calculerComparaisonComplete(collect([$rfq]), $devisListe);

        return response()->json([
            'success' => true,
            'data' => $comparaison
        ]);
    }

    public function selectionnerDevis(Request $request, $devisId)
    {
        $user = auth()->user();

        if (!$user->hasPermission('validate-devis')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour sélectionner des devis'
            ], 403);
        }

        $devis = DevisFournisseur::with([
            'fournisseur' => function($q) {
                $q->select('id', 'nom');
            },
            'rfq' => function($q) {
                $q->select('id', 'rfq_group_uuid');
            }
        ])->findOrFail($devisId);

        if (!in_array($devis->statut, ['RECU', 'EN_NEGOCIATION'])) {
            return response()->json([
                'success' => false,
                'message' => 'Ce devis ne peut pas être sélectionné (statut: ' . $devis->statut . ')'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $rfqGroupUuid = $devis->rfq->rfq_group_uuid;

            if ($rfqGroupUuid) {
                $rfqIds = RfqFournisseur::where('rfq_group_uuid', $rfqGroupUuid)->pluck('id');
                $tousDevis = DevisFournisseur::whereIn('rfq_id', $rfqIds)->get();

                if ($devis->statut !== 'VALIDE') {
                    $devis->valider($user->id);
                }

                foreach ($tousDevis as $autreDevis) {
                    if ($autreDevis->id != $devis->id && !in_array($autreDevis->statut, ['REFUSE', 'VALIDE'])) {
                        $autreDevis->refuser("Non retenu après comparaison - Devis sélectionné: " . $devis->reference);
                    }
                }
            } else {
                if ($devis->statut !== 'VALIDE') {
                    $devis->valider($user->id);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Devis sélectionné avec succès',
                'data' => [
                    'devis_selectionne' => $this->calculerDetailsDevis($devis),
                    'peut_creer_commande' => true
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur sélection devis', [
                'devis_id' => $devisId,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    public function detailsComplets($devisId)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-devis')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les devis'
            ], 403);
        }

        $devis = DevisFournisseur::with([
            'fournisseur' => function($q) {
                $q->select('id', 'nom');
            },
            'lignes.produit' => function($q) {
                $q->select('id', 'nom');
            },
            'rfq' => function($q) {
                $q->select('id', 'reference');
            }
        ])->findOrFail($devisId);

        $details = $this->calculerDetailsDevis($devis);

        return response()->json([
            'success' => true,
            'data' => $details
        ]);
    }

    public function meilleurDevisGroupe($rfqGroupUuid)
    {
        $user = auth()->user();

        if (!$user->hasPermission('compare-devis')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour accéder à cette information'
            ], 403);
        }

        $rfqs = RfqFournisseur::where('rfq_group_uuid', $rfqGroupUuid)->get();

        if ($rfqs->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Groupe RFQ non trouvé'
            ], 404);
        }

        $rfqIds = $rfqs->pluck('id');
        $devisListe = DevisFournisseur::with(['fournisseur', 'lignes'])
            ->whereIn('rfq_id', $rfqIds)
            ->whereIn('statut', ['RECU', 'EN_NEGOCIATION', 'VALIDE'])
            ->get();

        if ($devisListe->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun devis reçu pour ce groupe'
            ], 404);
        }

        $meilleurDevis = null;
        $meilleurTotal = PHP_FLOAT_MAX;

        foreach ($devisListe as $devis) {
            $total = $this->calculerTotalTTC($devis);
            if ($total < $meilleurTotal) {
                $meilleurTotal = $total;
                $meilleurDevis = $devis;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'meilleur_devis' => $this->calculerDetailsDevis($meilleurDevis),
                'total_ttc' => $meilleurTotal,
                'nombre_devis_analyses' => $devisListe->count()
            ]
        ]);
    }

    // ============================================================
    // MÉTHODES PRIVÉES
    // ============================================================

    private function prepareLignesData($request, $rfq, $devisId): array
    {
        $lignesData = [];

        if ($request->has('lignes') && !empty($request->lignes)) {
            foreach ($request->lignes as $ligneData) {
                $produit = null;
                $libelle = $ligneData['libelle'] ?? null;

                if (isset($ligneData['produit_id']) && $ligneData['produit_id']) {
                    $produit = Produit::select(['id', 'nom'])->find($ligneData['produit_id']);
                    $libelle = $produit?->nom ?? $libelle;
                }

                $lignesData[] = [
                    'devis_id' => $devisId,
                    'produit_id' => $produit?->id ?? $ligneData['produit_id'] ?? null,
                    'libelle' => $libelle ?? $ligneData['libelle'] ?? 'Produit',
                    'quantite' => $ligneData['quantite'],
                    'prix_unitaire_ht' => $ligneData['prix_unitaire_ht'],
                    'remise_ligne' => $ligneData['remise_ligne'] ?? 0,
                    'taux_tva' => $ligneData['taux_tva'] ?? 16,
                    'notes' => $ligneData['notes'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }
        } else {
            foreach ($rfq->lignes as $rfqLigne) {
                $lignesData[] = [
                    'devis_id' => $devisId,
                    'produit_id' => $rfqLigne->produit_id,
                    'libelle' => $rfqLigne->libelle,
                    'quantite' => $rfqLigne->quantite,
                    'prix_unitaire_ht' => 0,
                    'remise_ligne' => 0,
                    'taux_tva' => 16,
                    'notes' => $rfqLigne->notes,
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }
        }

        return $lignesData;
    }

        private function updateLignes(DevisFournisseur $devis, array $lignes): void
    {
        $existingLigneIds = [];
    
        foreach ($lignes as $ligneData) {
    
            /**
             * =====================================================
             * MISE À JOUR D'UNE LIGNE EXISTANTE
             * =====================================================
             */
            if (!empty($ligneData['id'])) {
    
                $ligne = DevisLigne::where('devis_id', $devis->id)
                    ->where('id', $ligneData['id'])
                    ->first();
    
                if (!$ligne) {
                    continue;
                }
    
                $ligne->update([
                    // ⚠️ On ne change jamais le produit d'une ligne existante
                    'libelle'           => $ligneData['libelle'],
                    'quantite'          => $ligneData['quantite'],
                    'prix_unitaire_ht'  => $ligneData['prix_unitaire_ht'],
                    'remise_ligne'      => $ligneData['remise_ligne'] ?? 0,
                    'taux_tva'          => $ligneData['taux_tva'] ?? 16,
                    'notes'             => $ligneData['notes'] ?? null,
                ]);
    
                $existingLigneIds[] = $ligne->id;
            }
    
            /**
             * =====================================================
             * CRÉATION D'UNE NOUVELLE LIGNE
             * =====================================================
             */
            else {
    
                $newLigne = $devis->lignes()->create([
                    'produit_id'        => $ligneData['produit_id'],
                    'libelle'           => $ligneData['libelle'],
                    'quantite'          => $ligneData['quantite'],
                    'prix_unitaire_ht'  => $ligneData['prix_unitaire_ht'],
                    'remise_ligne'      => $ligneData['remise_ligne'] ?? 0,
                    'taux_tva'          => $ligneData['taux_tva'] ?? 16,
                    'notes'             => $ligneData['notes'] ?? null,
                ]);
    
                $existingLigneIds[] = $newLigne->id;
            }
        }
    
        /**
         * =====================================================
         * SUPPRIMER LES LIGNES ABSENTES DE LA REQUÊTE
         * =====================================================
         */
        $devis->lignes()
            ->whereNotIn('id', $existingLigneIds)
            ->delete();
    }

    private function calculerTotauxDevis($devis): array
    {
        $totalHT = 0;
        $totalTVA = 0;

        foreach ($devis->lignes as $ligne) {
            $prixApresRemise = $ligne->prix_unitaire_ht * (1 - ($ligne->remise_ligne / 100));
            $valeurHT = $prixApresRemise * $ligne->quantite;
            $tva = $valeurHT * ($ligne->taux_tva / 100);

            $totalHT += $valeurHT;
            $totalTVA += $tva;
        }

        if ($devis->remise_globale > 0) {
            $totalHT = $totalHT * (1 - $devis->remise_globale / 100);
        }

        $totalTTC = $totalHT + $totalTVA + ($devis->frais_livraison ?? 0);

        return [
            'total_ht' => round($totalHT, 2),
            'total_tva' => round($totalTVA, 2),
            'total_ttc' => round($totalTTC, 2),
            'frais_livraison' => round($devis->frais_livraison ?? 0, 2),
            'remise_globale' => $devis->remise_globale ?? 0
        ];
    }

    private function calculerTotalTTC($devis): float
    {
        $totalHT = 0;
        $totalTVA = 0;

        foreach ($devis->lignes as $ligne) {
            $prixApresRemise = $ligne->prix_unitaire_ht * (1 - ($ligne->remise_ligne / 100));
            $totalLigneHT = $prixApresRemise * $ligne->quantite;
            $totalHT += $totalLigneHT;
            $totalTVA += $totalLigneHT * ($ligne->taux_tva / 100);
        }

        if ($devis->remise_globale > 0) {
            $totalHT *= (1 - $devis->remise_globale / 100);
        }

        return $totalHT + $totalTVA + ($devis->frais_livraison ?? 0);
    }

    private function calculerDetailsDevis($devis): array
    {
        $totalHT = 0;
        $totalTVA = 0;
        $detailsLignes = [];

        foreach ($devis->lignes as $ligne) {
            $prixApresRemiseLigne = $ligne->prix_unitaire_ht * (1 - ($ligne->remise_ligne / 100));
            $totalLigneHT = $prixApresRemiseLigne * $ligne->quantite;
            $tvaLigne = $totalLigneHT * ($ligne->taux_tva / 100);

            $totalHT += $totalLigneHT;
            $totalTVA += $tvaLigne;

            $detailsLignes[] = [
                'produit_id' => $ligne->produit_id,
                'produit_nom' => $ligne->produit?->nom ?? $ligne->libelle,
                'libelle' => $ligne->libelle,
                'quantite' => $ligne->quantite,
                'prix_unitaire_ht' => $ligne->prix_unitaire_ht,
                'remise_ligne' => $ligne->remise_ligne,
                'prix_apres_remise' => $prixApresRemiseLigne,
                'total_ligne_ht' => $totalLigneHT,
                'taux_tva' => $ligne->taux_tva,
                'tva_ligne' => $tvaLigne,
                'total_ligne_ttc' => $totalLigneHT + $tvaLigne
            ];
        }

        $remiseGlobaleMontant = 0;
        if ($devis->remise_globale > 0) {
            $remiseGlobaleMontant = $totalHT * ($devis->remise_globale / 100);
            $totalHT -= $remiseGlobaleMontant;
        }

        $totalTTC = $totalHT + $totalTVA + ($devis->frais_livraison ?? 0);

        return [
            'devis_id' => $devis->id,
            'reference' => $devis->reference,
            'fournisseur_id' => $devis->fournisseur_id,
            'fournisseur_nom' => $devis->fournisseur?->nom,
            'date_devis' => $devis->date_devis?->format('Y-m-d'),
            'date_validite' => $devis->date_validite?->format('Y-m-d'),
            'statut' => $devis->statut,
            'montant_ht' => round($totalHT, 2),
            'tva' => round($totalTVA, 2),
            'montant_ttc' => round($totalTTC, 2),
            'frais_livraison' => round($devis->frais_livraison ?? 0, 2),
            'remise_globale' => $devis->remise_globale ?? 0,
            'remise_globale_montant' => round($remiseGlobaleMontant, 2),
            'delai_livraison' => $devis->delai_livraison,
            'conditions_paiement' => $devis->conditions_paiement,
            'notes' => $devis->notes,
            'lignes' => $detailsLignes,
            'rfq_id' => $devis->rfq_id,
            'fichier_pdf_url' => $devis->fichier_pdf ? Storage::url($devis->fichier_pdf) : null
        ];
    }

    private function comparerProduits($rfqs, $devisListe): array
    {
        $produits = [];

        foreach ($rfqs as $rfq) {
            foreach ($rfq->lignes as $ligne) {
                $produitKey = $ligne->produit_id ?? $ligne->libelle;
                if (!isset($produits[$produitKey])) {
                    $produits[$produitKey] = [
                        'produit_id' => $ligne->produit_id,
                        'libelle' => $ligne->libelle,
                        'quantite_demandee' => $ligne->quantite,
                        'fournisseurs' => []
                    ];
                }
            }
        }

        foreach ($produits as $key => &$produit) {
            foreach ($devisListe as $devis) {
                $ligneDevis = $devis->lignes->first(function($ligne) use ($produit) {
                    if ($produit['produit_id']) {
                        return $ligne->produit_id == $produit['produit_id'];
                    }
                    return $ligne->libelle == $produit['libelle'];
                });

                if ($ligneDevis) {
                    $prixApresRemise = $ligneDevis->prix_unitaire_ht * (1 - ($ligneDevis->remise_ligne / 100));
                    $totalLigne = $prixApresRemise * $ligneDevis->quantite;

                    $produit['fournisseurs'][] = [
                        'devis_id' => $devis->id,
                        'fournisseur_id' => $devis->fournisseur_id,
                        'fournisseur_nom' => $devis->fournisseur?->nom,
                        'quantite_proposee' => $ligneDevis->quantite,
                        'prix_unitaire_ht' => $ligneDevis->prix_unitaire_ht,
                        'remise_ligne' => $ligneDevis->remise_ligne,
                        'prix_apres_remise' => $prixApresRemise,
                        'total_ligne_ht' => $totalLigne,
                        'taux_tva' => $ligneDevis->taux_tva,
                        'est_meilleur_prix' => false
                    ];
                }
            }

            if (!empty($produit['fournisseurs'])) {
                $meilleurPrix = min(array_column($produit['fournisseurs'], 'prix_apres_remise'));
                foreach ($produit['fournisseurs'] as &$offre) {
                    $offre['est_meilleur_prix'] = ($offre['prix_apres_remise'] == $meilleurPrix);
                    $offre['meilleur_prix'] = $meilleurPrix;
                    $offre['economie_potentielle'] = round(($offre['prix_apres_remise'] - $meilleurPrix) * $produit['quantite_demandee'], 2);
                }
            }
        }

        return array_values($produits);
    }

    private function classerDevis($devisDetails): array
    {
        usort($devisDetails, function($a, $b) {
            return $a['montant_ttc'] <=> $b['montant_ttc'];
        });

        $classement = [];
        foreach ($devisDetails as $index => $devis) {
            $classement[] = [
                'rang' => $index + 1,
                'devis_id' => $devis['devis_id'],
                'reference' => $devis['reference'],
                'fournisseur_id' => $devis['fournisseur_id'],
                'fournisseur_nom' => $devis['fournisseur_nom'],
                'total_ttc' => $devis['montant_ttc'],
                'delai_livraison' => $devis['delai_livraison'],
                'statut' => $devis['statut'],
                'date_validite' => $devis['date_validite']
            ];
        }

        return $classement;
    }

    private function calculerComparaisonComplete($rfqs, $devisListe): array
    {
        $devisDetails = [];
        foreach ($devisListe as $devis) {
            $devisDetails[] = $this->calculerDetailsDevis($devis);
        }

        $comparaisonProduits = $this->comparerProduits($rfqs, $devisListe);
        $classementGlobal = $this->classerDevis($devisDetails);
        $meilleurDevisGlobal = !empty($classementGlobal) ? $classementGlobal[0] : null;

        return [
            'tous_devis' => $devisDetails,
            'comparaison_produits' => $comparaisonProduits,
            'classement_global' => $classementGlobal,
            'meilleur_devis_global' => $meilleurDevisGlobal,
            'rfqs' => $rfqs,
            'nb_devis_recus' => $devisListe->count(),
            'nb_rfq_envoyees' => $rfqs->count()
        ];
    }
}