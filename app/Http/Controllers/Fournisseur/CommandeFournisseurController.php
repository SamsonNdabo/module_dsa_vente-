<?php
// app/Http/Controllers/Fournisseur/CommandeFournisseurController.php

namespace App\Http\Controllers\Fournisseur;

use App\Http\Controllers\Controller;
use App\Models\CommandeFournisseur;
use App\Models\LigneCommandeFournisseur;
use App\Models\Fournisseur;
use App\Models\Produit;
use App\Models\DevisFournisseur;
use App\Models\LigneDevisFournisseur;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\StockSuccursale;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Mail\CommandeFournisseurAnnulationMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class CommandeFournisseurController extends Controller
{
    
    private const ROLES_FULL_ACCESS = ['SUPER_ADMIN', 'AUDITEUR', 'CHEF_OPERATIONS','CHEF_AGENCE'];
    private const STATUTS_MODIFIABLES = ['BROUILLON', 'EN_ATTENTE'];
    private const STATUTS_SUPPRIMABLES = ['VALIDEE', 'EN_ATTENTE', 'ANNULEE'];

    /**
     * Afficher la liste des commandes fournisseurs - OPTIMISÉ
     * Permission: view-commandes
     */
        public function index(Request $request)
        {
            $user = auth()->user();
        
            if (!$user->hasPermission('view-commandes')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous n\'avez pas les droits pour voir les commandes fournisseurs'
                ], 403);
            }
        
        
            $query = CommandeFournisseur::select([
                'id',
                'reference',
                'fournisseur_id',
                'devis_id',
                'depot_destination_id',
                'date_commande',
                'date_livraison_prevue',
                'date_livraison_reelle',
                'statut',
                'statut_paiement',
                'conditions_paiement',
                'mode_livraison',
                'transporteur',
                'numero_suivi',
                'montant_total_ht',
                'montant_total_ttc',
                'devise_id',
                'cree_par_id',
                'valide_par_id',
                'date_validation',
                'notes',
                'created_at',
                'updated_at'
            ])
            ->with([
        
        
                /*
                |--------------------------------------------------------------------------
                | Fournisseur
                |--------------------------------------------------------------------------
                */
                'fournisseur' => function ($q) {
        
                    $q->select([
                        'id',
                        'nom',
                        'code_fournisseur',
                        'email',
                        'telephone'
                    ]);
        
                },
        
        
                /*
                |--------------------------------------------------------------------------
                | Devis lié
                |--------------------------------------------------------------------------
                */
                'devis' => function ($q) {
        
        
                    $q->select([
                        'id',
                        'reference',
                        'statut',
                        'fournisseur_id',
                        'conditions_paiement',
                        'delai_livraison',
                        'frais_livraison',
                        'remise_globale',
                        'created_at'
                    ])
                    ->with([
        
        
                        'fournisseur:id,nom,code_fournisseur,email,telephone',
        
        
                        'lignes' => function ($l) {
        
        
                            $l->select([
                                'id',
                                'devis_id',
                                'produit_id',
                                'libelle',
                                'quantite',
                                'prix_unitaire_ht',
                                'remise_ligne',
                                'taux_tva'
                            ])
                            ->with([
        
                                'produit:id,nom,code_barre,type'
        
                            ]);
        
                        }
        
                    ]);
        
                },
        
        
                /*
                |--------------------------------------------------------------------------
                | Dépôt destination
                |--------------------------------------------------------------------------
                */
                'depotDestination' => function ($q) {
        
        
                    $q->select([
                        'id',
                        'succursale_id',
                        'is_depot'
                    ])
                    ->with([
        
                        'succursale' => function ($sq) {
        
                            $sq->select([
                                'id',
                                'nom',
                                'code'
                            ]);
        
                        }
        
                    ]);
        
                },
        
        
                /*
                |--------------------------------------------------------------------------
                | Créateur
                |--------------------------------------------------------------------------
                */
                'creePar' => function ($q) {
        
                    $q->select([
                        'id',
                        'nom_complet',
                        'email'
                    ]);
        
                }
        
        
            ]);
        
        
            /*
            |--------------------------------------------------------------------------
            | Filtrage succursale
            |--------------------------------------------------------------------------
            */
        
            $this->applySuccursaleFilter($query, $user);
        
        
        
            /*
            |--------------------------------------------------------------------------
            | Filtres
            |--------------------------------------------------------------------------
            */
        
            if ($request->filled('statut')) {
        
                $query->where(
                    'statut',
                    $request->statut
                );
        
            }
        
        
            if ($request->filled('fournisseur_id')) {
        
                $query->where(
                    'fournisseur_id',
                    $request->fournisseur_id
                );
        
            }
        
        
            if ($request->filled('date_debut')) {
        
                $query->whereDate(
                    'date_commande',
                    '>=',
                    $request->date_debut
                );
        
            }
        
        
            if ($request->filled('date_fin')) {
        
                $query->whereDate(
                    'date_commande',
                    '<=',
                    $request->date_fin
                );
        
            }
        
        
        
            if ($request->filled('recherche')) {
        
        
                $search = $request->recherche;
        
        
                $query->where(function ($q) use ($search) {
        
        
                    $q->where(
                        'reference',
                        'like',
                        "%{$search}%"
                    )
        
        
                    ->orWhereHas(
                        'fournisseur',
                        function ($sq) use ($search) {
        
        
                            $sq->where(
                                'nom',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'code_fournisseur',
                                'like',
                                "%{$search}%"
                            );
        
        
                        }
                    );
        
        
                });
        
        
            }
        
        
        
            /*
            |--------------------------------------------------------------------------
            | Pagination commandes
            |--------------------------------------------------------------------------
            */
        
            $perPage = $request->get('per_page', 20);
        
        
            $commandes = $query
                ->orderBy(
                    'created_at',
                    'desc'
                )
                ->paginate($perPage);
        
        
        
            /*
            |--------------------------------------------------------------------------
            | Devis disponibles pour création commande
            |--------------------------------------------------------------------------
            */
        
            $devisDisponibles = DevisFournisseur::with([
        
        
                'fournisseur:id,nom,code_fournisseur,email,telephone',
        
        
                'lignes' => function ($q) {
        
        
                    $q->select([
                        'id',
                        'devis_id',
                        'produit_id',
                        'libelle',
                        'quantite',
                        'prix_unitaire_ht',
                        'remise_ligne',
                        'taux_tva'
                    ])
                    ->with([
        
                        'produit:id,nom,code_barre,type'
        
                    ]);
        
        
                }
        
        
            ])
            ->where(
                'statut',
                'VALIDE'
            )
            ->whereNotIn(
                'id',
                CommandeFournisseur::whereNotNull('devis_id')
                    ->pluck('devis_id')
            )
            ->select([
        
                'id',
                'reference',
                'fournisseur_id',
                'statut',
                'conditions_paiement',
                'delai_livraison',
                'frais_livraison',
                'remise_globale',
                'created_at'
        
            ])
            ->orderBy(
                'created_at',
                'desc'
            )
            ->get();
        
        
        
            return response()->json([
        
                'success' => true,
        
                'data' => $commandes,
        
                'devis_disponibles' => $devisDisponibles
        
            ]);
        }

    /**
     * Créer une commande fournisseur - OPTIMISÉ
     * Permission: create-commandes
     */
    public function store(Request $request)
{
    $user = auth()->user();

    if (!$user->hasPermission('create-commandes')) {
        return response()->json([
            'success' => false,
            'message' => 'Vous n\'avez pas les droits pour créer des commandes fournisseurs'
        ], 403);
    }

    // Validation
    $validator = Validator::make($request->all(), [
        'devis_id' => 'required|exists:devis_fournisseurs,id',
        'depot_destination_id' => 'required|exists:stock_succursale,id',
        'date_livraison_prevue' => 'nullable|date|after:today',
        'conditions_paiement' => 'nullable|string',
        'mode_livraison' => 'nullable|string',
        'transporteur' => 'nullable|string',
        'notes' => 'nullable|string',
        'devise_id' => 'nullable|exists:devise,id',
        'lignes' => 'required|array|min:1',
        'lignes.*.produit_id' => 'required|exists:produit,id',
        'lignes.*.quantite' => 'required|integer|min:1',
        'lignes.*.date_livraison_prevue' => 'nullable|date',
        'lignes.*.notes' => 'nullable|string'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    // Récupération du devis
    $devis = DevisFournisseur::with(['fournisseur', 'lignes.produit'])
        ->findOrFail($request->devis_id);

    // Vérification statut du devis
    if ($devis->statut !== 'VALIDE') {
        return response()->json([
            'success' => false,
            'message' => 'Seul un devis VALIDE peut être transformé en commande'
        ], 422);
    }

    // Vérification qu'une commande n'existe pas déjà
    $commandeExistante = CommandeFournisseur::where('devis_id', $devis->id)->first();
    if ($commandeExistante) {
        return response()->json([
            'success' => false,
            'message' => 'Une commande a déjà été créée à partir de ce devis',
            'data' => $commandeExistante
        ], 409);
    }

    // Vérification fournisseur
    $fournisseur = $devis->fournisseur;
    if (!$fournisseur) {
        return response()->json([
            'success' => false,
            'message' => 'Le devis ne possède aucun fournisseur.'
        ], 422);
    }

    if (in_array($fournisseur->statut, ['SUSPENDU', 'INACTIF'])) {
        return response()->json([
            'success' => false,
            'message' => 'Ce fournisseur est ' . strtolower($fournisseur->statut)
        ], 403);
    }

    // Vérification dépôt
    $depot = StockSuccursale::select(['id', 'succursale_id', 'is_depot'])->find($request->depot_destination_id);
    if (!$depot || !$depot->is_depot) {
        return response()->json([
            'success' => false,
            'message' => 'Le stock de destination doit être un DÉPÔT (is_depot = true)'
        ], 422);
    }

    if (!$this->canAccessSuccursale($user, $depot->succursale_id)) {
        return response()->json([
            'success' => false,
            'message' => 'Vous ne pouvez créer des commandes que pour votre succursale'
        ], 422);
    }

    // Vérification des quantités par rapport au devis
    $lignesDevis = $devis->lignes->keyBy('produit_id');
    foreach ($request->lignes as $index => $ligneData) {
        $ligneDevis = $lignesDevis->get($ligneData['produit_id']);
        if (!$ligneDevis) {
            return response()->json([
                'success' => false,
                'message' => "Produit #{$ligneData['produit_id']} absent du devis"
            ], 422);
        }
        if ($ligneData['quantite'] > $ligneDevis->quantite) {
            $produit = Produit::find($ligneData['produit_id']);
            return response()->json([
                'success' => false,
                'message' => "La quantité commandée pour \"{$produit->nom}\" ({$ligneData['quantite']}) dépasse celle du devis ({$ligneDevis->quantite})"
            ], 422);
        }
    }

    DB::beginTransaction();

    try {
        // Création de la commande
        $commande = CommandeFournisseur::create([
            'fournisseur_id' => $devis->fournisseur_id,
            'devis_id' => $devis->id,
            'depot_destination_id' => $request->depot_destination_id,
            'date_livraison_prevue' => $request->date_livraison_prevue,
            'conditions_paiement' => $request->conditions_paiement,
            'mode_livraison' => $request->mode_livraison,
            'transporteur' => $request->transporteur,
            'notes' => $request->notes,
            'devise_id' => $request->devise_id ?? $devis->devise_id ?? 1,
            'cree_par_id' => auth()->id(),
            'statut' => 'EN_ATTENTE',
            // Initialisation des montants à 0
            'montant_total_ht' => 0,
            'montant_total_tva' => 0,
            'montant_total_ttc' => 0,
            'montant_paye' => 0,
            'montant_restant' => 0,
            'statut_paiement' => 'NON_PAYE'
        ]);

        // Création des lignes avec calculs
        $totalHT = 0;
        $totalTVA = 0;
        $totalTTC = 0;
        $lignesData = [];

        foreach ($request->lignes as $ligneData) {
            $ligneDevis = $lignesDevis->get($ligneData['produit_id']);
            
            $quantite = $ligneData['quantite'];
            $prixUnitaireHT = $ligneDevis->prix_unitaire_ht;
            $remiseLigne = $ligneDevis->remise_ligne ?? 0;
            $tauxTVA = $ligneDevis->taux_tva ?? 16;
            
            $montantBrutHT = $quantite * $prixUnitaireHT;
            $montantRemise = $montantBrutHT * ($remiseLigne / 100);
            $montantHT = $montantBrutHT - $montantRemise;
            $montantTVA = $montantHT * ($tauxTVA / 100);
            $montantTTC = $montantHT + $montantTVA;
            
            $totalHT += $montantHT;
            $totalTVA += $montantTVA;
            $totalTTC += $montantTTC;
            
            $produit = Produit::select(['id', 'nom'])->find($ligneData['produit_id']);
            
            //  Création avec les champs existants (SANS metadata)
            LigneCommandeFournisseur::create([
                'commande_id' => $commande->id,
                'produit_id' => $ligneData['produit_id'],
                'libelle' => $produit->nom,
                'quantite' => $quantite,
                'quantite_recue' => 0,
                'prix_unitaire_ht' => $prixUnitaireHT,
                'remise_ligne' => $remiseLigne,
                'taux_tva' => $tauxTVA,
                'montant_ht' => round($montantHT, 2),
                'montant_tva' => round($montantTVA, 2),
                'montant_ttc' => round($montantTTC, 2),
                'date_livraison_prevue' => $ligneData['date_livraison_prevue'] ?? null,
                'notes' => $ligneData['notes'] ?? null,
            ]);
        }

        // Insertion en BULK des lignes
        if (!empty($lignesData)) {
            LigneCommandeFournisseur::insert($lignesData);
        }

        //  Mise à jour des montants de la commande
        $commande->montant_total_ht = round($totalHT, 2);
        $commande->montant_total_tva = round($totalTVA, 2);
        $commande->montant_total_ttc = round($totalTTC, 2);
        $commande->montant_paye = 0;
        $commande->montant_restant = round($totalTTC, 2);
        $commande->statut_paiement = 'NON_PAYE';
        $commande->save();

        // Mise à jour du statut du devis
        $devis->update(['statut' => 'COMMANDE_CREEE']);

        DB::commit();

        // Envoi email en arrière-plan
        try {
            $commande->envoyerEmailFournisseur();
        } catch (\Exception $e) {
            Log::error('Erreur envoi email commande', [
                'commande_id' => $commande->id,
                'error' => $e->getMessage()
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Commande créée avec succès. Un email a été envoyé au fournisseur.',
            'data' => [
                'commande' => $commande->load([
                    'fournisseur' => function($q) {
                        $q->select('id', 'nom', 'code_fournisseur');
                    },
                    'devis' => function($q) {
                        $q->select('id', 'reference', 'statut')
                          ->with(['fournisseur:id,nom', 'lignes.produit:id,nom']);
                    },
                    'lignes.produit' => function($q) {
                        $q->select('id', 'nom', 'code_barre');
                    },
                    'depotDestination.succursale' => function($q) {
                        $q->select('id', 'nom', 'code');
                    }
                ]),
                'devis_id' => $commande->devis_id,
                'fournisseur_id' => $commande->fournisseur_id,
                'reference' => $commande->reference,
                'statut' => $commande->statut,
                'statut_paiement' => $commande->statut_paiement,
                'montant_total_ht' => $commande->montant_total_ht,
                'montant_total_ttc' => $commande->montant_total_ttc,
                'montant_paye' => $commande->montant_paye,
                'montant_restant' => $commande->montant_restant,
                'created_at' => $commande->created_at,
                'updated_at' => $commande->updated_at,
            ]
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Erreur création commande', [
            'user_id' => $user->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return response()->json([
            'success' => false,
            'message' => 'Erreur: ' . $e->getMessage()
        ], 500);
    }
}
    /**
     * Afficher une commande - OPTIMISÉ
     * Permission: view-commandes
     */
    public function show($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-commandes')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour voir les commandes fournisseurs'
            ], 403);
        }

        $commande = CommandeFournisseur::select([
            'id', 'reference', 'fournisseur_id', 'devis_id',
            'depot_destination_id',
            'date_commande', 'date_livraison_prevue', 'date_livraison_reelle',
            'statut', 'statut_paiement', 'conditions_paiement',
            'mode_livraison', 'transporteur', 'numero_suivi',
            'montant_total_ht', 'montant_total_ttc', 'devise_id',
            'cree_par_id', 'valide_par_id', 'reception_par_id',
            'annule_par_id', 'date_validation', 'date_reception',
            'motif_annulation', 'notes', 'notes_internes',
            'documents', 'metadata', 'created_at', 'updated_at'
        ])->with([
            'fournisseur' => function($q) {
                $q->select('id', 'nom', 'code_fournisseur', 'email', 'telephone', 'adresse', 'ville');
            },
            //  CHARGEMENT COMPLET DU DEVIS AVEC SES RELATIONS
            'devis' => function($q) {
                $q->with([
                    'fournisseur:id,nom,code_fournisseur,email,telephone',
                    'lignes' => function($l) {
                        $l->with([
                            'produit:id,nom,code_barre,type',
                            'devise:id,code,symbole'
                        ]);
                    }
                ])->select('id', 'reference', 'statut', 'montant_total_ht', 'montant_total_ttc', 'fournisseur_id');
            },
            'depotDestination' => function($q) {
                $q->select('id', 'succursale_id', 'is_depot', 'emplacement')
                  ->with(['succursale' => function($sq) {
                      $sq->select('id', 'nom', 'code', 'adresse', 'telephone');
                  }]);
            },
            'lignes' => function($q) {
                $q->select('id', 'commande_id', 'produit_id', 'libelle', 'quantite', 'quantite_recue', 'date_livraison_prevue', 'notes')
                  ->with(['produit' => function($pq) {
                      $pq->select('id', 'nom', 'code_barre', 'type');
                  }]);
            },
            'receptions' => function($q) {
                $q->select('id', 'commande_id', 'reference', 'statut', 'date_reception', 'created_at')
                  ->with(['lignes' => function($lq) {
                      $lq->select('id', 'reception_id', 'produit_id', 'quantite_recue', 'quantite_casse', 'quantite_perdue');
                  }]);
            },
            'creePar' => function($q) {
                $q->select('id', 'nom_complet', 'email', 'nom_utilisateur');
            },
            'validePar' => function($q) {
                $q->select('id', 'nom_complet', 'email');
            },
            'receptionPar' => function($q) {
                $q->select('id', 'nom_complet', 'email');
            },
            'annulePar' => function($q) {
                $q->select('id', 'nom_complet', 'email');
            },
            'devise' => function($q) {
                $q->select('id', 'code', 'nom', 'symbole');
            }
        ])->findOrFail($id);

        //  Vérification accès
        if (!$this->canAccessCommande($user, $commande)) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas accès à cette commande'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $commande
        ]);
    }

    /**
     * Mettre à jour une commande - OPTIMISÉ
     * Permission: edit-commandes
     */
    public function update(Request $request, $id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('edit-commandes')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour modifier des commandes fournisseurs'
            ], 403);
        }

        $commande = CommandeFournisseur::findOrFail($id);

        if (!$this->canAccessCommande($user, $commande)) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez modifier que les commandes de votre succursale'
            ], 403);
        }

        if (!in_array($commande->statut, self::STATUTS_MODIFIABLES)) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de modifier une commande déjà traitée'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'date_livraison_prevue' => 'nullable|date',
            'conditions_paiement' => 'nullable|string',
            'mode_livraison' => 'nullable|string',
            'transporteur' => 'nullable|string',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $commande->update($request->only([
                'date_livraison_prevue',
                'conditions_paiement',
                'mode_livraison',
                'transporteur',
                'notes'
            ]));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Commande mise à jour',
                'data' => $commande->fresh()->load([
                    'devis' => function($q) {
                        $q->select('id', 'reference', 'statut');
                    }
                ])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur mise à jour commande', [
                'commande_id' => $id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Valider la commande
     * Permission: validate-commandes
     */
    public function valider($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('validate-commandes')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour valider des commandes fournisseurs'
            ], 403);
        }

        $commande = CommandeFournisseur::findOrFail($id);

        if (!$this->canAccessCommande($user, $commande)) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez valider que les commandes de votre succursale'
            ], 403);
        }

        try {
            $commande->valider(auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Commande validée avec succès',
                'data' => $commande->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Annuler la commande - OPTIMISÉ
     * Permission: cancel-commandes
     */
    public function annuler(Request $request, $id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('cancel-commandes')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour annuler des commandes fournisseurs'
            ], 403);
        }

        $commande = CommandeFournisseur::findOrFail($id);

        if (!$this->canAccessCommande($user, $commande)) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez annuler que les commandes de votre succursale'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'motif' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();

        try {
            $commande->annuler(auth()->id(), $request->motif);

            //  Si un devis est associé, restaurer son statut
            if ($commande->devis_id) {
                $devis = DevisFournisseur::find($commande->devis_id);
                if ($devis && $devis->statut === 'COMMANDE_CREEE') {
                    $devis->update(['statut' => 'VALIDE']);
                }
            }

            //  Envoi email en arrière-plan
            try {
                $commande->envoyerEmailAnnulationFournisseur();
            } catch (\Exception $e) {
                Log::error('Erreur envoi email annulation commande', [
                    'commande_id' => $commande->id,
                    'error' => $e->getMessage()
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Commande annulée avec succès. Un email a été envoyé au fournisseur.',
                'data' => $commande->fresh()
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Supprimer une commande - OPTIMISÉ
     * Permission: delete-commandes
     */
    public function destroy($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('delete-commandes')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour supprimer des commandes fournisseurs'
            ], 403);
        }

        $commande = CommandeFournisseur::findOrFail($id);

        if (!$this->canAccessCommande($user, $commande)) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez supprimer que les commandes de votre succursale'
            ], 403);
        }

        if (!in_array($commande->statut, self::STATUTS_SUPPRIMABLES)) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer une commande en cours de traitement'
            ], 422);
        }

        //  Si un devis est associé, restaurer son statut
        if ($commande->devis_id) {
            $devis = DevisFournisseur::find($commande->devis_id);
            if ($devis && $devis->statut === 'COMMANDE_CREEE') {
                $devis->update(['statut' => 'VALIDE']);
            }
        }

        $commande->delete();

        return response()->json([
            'success' => true,
            'message' => 'Commande supprimée'
        ]);
    }

    /**
     * Exporter la commande en PDF
     * Permission: view-commandes
     */
    public function downloadPdf($id)
    {
        $user = auth()->user();

        $commande = CommandeFournisseur::with([
            'fournisseur' => function($q) {
                $q->select('id', 'nom', 'code_fournisseur', 'email', 'telephone', 'adresse');
            },
            'devis' => function($q) {
                $q->select('id', 'reference');
            },
            'lignes.produit' => function($q) {
                $q->select('id', 'nom', 'code_barre');
            }
        ])->findOrFail($id);

        $pdf = PDF::loadView('pdf.bon_commande', [
            'commande' => $commande,
            'fournisseur' => $commande->fournisseur,
            'lignes' => $commande->lignes
        ]);

        return $pdf->download("BC_{$commande->reference}.pdf");
    }

    public function exporterPdf($id)
    {
        $user = auth()->user();

        if (!$user->hasPermission('view-commandes')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour exporter les commandes'
            ], 403);
        }

        $commande = CommandeFournisseur::with([
            'fournisseur' => function($q) {
                $q->select('id', 'nom', 'code_fournisseur', 'email', 'telephone');
            },
            'devis' => function($q) {
                $q->select('id', 'reference');
            },
            'lignes.produit' => function($q) {
                $q->select('id', 'nom', 'code_barre');
            }
        ])->findOrFail($id);

        $pdf = PDF::loadView('pdf.bon_commande', [
            'commande' => $commande,
            'fournisseur' => $commande->fournisseur,
            'lignes' => $commande->lignes
        ]);

        return $pdf->download("BC_{$commande->reference}.pdf");
    }

    // ============================================================
    // MÉTHODES PRIVÉES
    // ============================================================

    /**
     * Applique le filtre par succursale
     */
    private function applySuccursaleFilter(&$query, $user): void
    {
        if (in_array($user->role?->code, self::ROLES_FULL_ACCESS) ||
            $user->hasPermission('manage-all-users') ||
            $user->hasPermission('view-all-stock')) {
            return;
        }

        $query->whereHas('depotDestination.succursale', function ($q) use ($user) {
            $q->where('id', $user->succursale_id);
        });
    }

    /**
     * Vérifie si l'utilisateur peut accéder à une succursale
     */
    private function canAccessSuccursale($user, int $succursaleId): bool
    {
        if (in_array($user->role?->code, self::ROLES_FULL_ACCESS) ||
            $user->hasPermission('manage-all-users') ||
            $user->hasPermission('view-all-stock')) {
            return true;
        }

        return $user->succursale_id == $succursaleId;
    }

    /**
     * Vérifie si l'utilisateur peut accéder à une commande
     */
    private function canAccessCommande($user, $commande): bool
    {
        if (in_array($user->role?->code, self::ROLES_FULL_ACCESS) ||
            $user->hasPermission('manage-all-users') ||
            $user->hasPermission('view-all-stock')) {
            return true;
        }

        return $commande->depotDestination?->succursale_id == $user->succursale_id;
    }
}