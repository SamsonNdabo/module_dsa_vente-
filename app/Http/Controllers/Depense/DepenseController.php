<?php

namespace App\Http\Controllers\Depense;

use App\Http\Controllers\Controller;
use App\Models\Depense;
use App\Models\CategorieDepense;
use App\Models\Caisses;
use App\Models\Utilisateur;
use App\Models\ExchangeRate;
use App\Services\DepenseService;
use App\Services\EcritureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\auth;
use App\Traits\Notifiable;

class DepenseController extends Controller
{
    use Notifiable;

    public const PEUT_DEPENSER = 'DEPENSER';

    /**
     * Lister les dépenses avec filtres
     */
    public function index(Request $request)
    {
        $user = auth::user();
    
        $query = Depense::with(['caisse', 'categorie', 'devise', 'createur']);

        $query->when($request->filled('succursale_id'), function($q) use ($request) {
            return $q->where('succursale_id', $request->succursale_id);
        });
        $query->when($request->filled('statut'), function($q) use ($request) {
            return $q->where('statut', $request->statut);
        });
        $query->when($request->filled('date_debut'), function($q) use ($request) {
            return $q->whereDate('date_depense', '>=', $request->date_debut);
        });
        $query->when($request->filled('date_fin'), function($q) use ($request) {
            return $q->whereDate('date_depense', '<=', $request->date_fin);
        });
        $query->when($request->filled('devise_id'), function($q) use ($request) {
            return $q->where('devise_id', $request->devise_id);
        });
        $query->when($request->filled('categorie_id'), function ($q) use ($request) {
            $q->whereHas('categorie', function ($subQuery) use ($request) {
                $subQuery->where('code', $request->categorie_id); 
            });
        });

        $depenses = $query->orderBy('created_at', 'desc')
                          ->paginate($request->input('per_page', 20));

        $userIds = collect()
            ->merge($depenses->pluck('created_by'))
            ->filter(function($id) {
                return !is_null($id) && is_numeric($id);
            })
            ->unique()
            ->values()
            ->toArray();

        $utilisateurs = empty($userIds) ? collect() : Utilisateur::getUtilisateursByIds($userIds);

        $depenses->getCollection()->transform(function ($depense) use ($utilisateurs) {
            $depense->createur_info = $depense->created_by ? $utilisateurs->get((int) $depense->created_by) : null;
            return $depense;
        });

        return response()->json(['success' => true, 'data' => $depenses]);
    }

    /**
     * Créer une nouvelle dépense (en attente)
     */
    public function store(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Non authentifié.'], 401);
        }
    
        $validated = $request->validate([
            'caisse_id'            => 'required|integer|exists:caisses,id',
            'categorie_depense_id' => 'required|integer|exists:categorie_depense,id',
            'devise_id'            => 'required|integer|exists:devise,id',
            'montant'              => 'required|numeric|min:0.01',
            'motif'                => 'required|string|max:255',
            'reference'            => 'nullable|string|max:100',
            'date_depense'         => 'required|date_format:Y-m-d',
        ]);
    
        $caisse = Caisses::find($validated['caisse_id']);
        if (!$caisse || $caisse->succursale_id != $user->succursale_id) {
            return response()->json(['success' => false, 'message' => 'Caisse non autorisée pour cette succursale.'], 403);
        }
    
        if (!in_array($caisse->type_caisse, ['PHYSIQUE', 'MOBILE_MONEY', 'BANCAIRE'])) {
            return response()->json(['success' => false, 'message' => 'La caisse doit être de type PHYSIQUE, MOBILE_MONEY ou BANCAIRE.'], 422);
        }
    
        $principale = $caisse->parent ? $caisse->parent : $caisse;
        if (!$principale->peut(self::PEUT_DEPENSER)) {
            return response()->json(['success' => false, 'message' => 'Cette caisse n\'est pas autorisée à effectuer des dépenses.'], 403);
        }
    
        $depenseService = new DepenseService();
    
        // Vérifier les plafonds
        $depenseService->verifierPlafondDepense($validated['montant'], $validated['devise_id'], $user->succursale_id);
        $depenseService->verifierPlafondJournalier($user->succursale_id, $validated['montant'], $validated['devise_id']);
    
        // Calcul du montant en USD
        $montantUsd = $depenseService->convertirEnUsd($validated['montant'], $validated['devise_id'], $user->succursale_id);
    
        // Récupérer la catégorie pour la notification
        $categorie = CategorieDepense::find($validated['categorie_depense_id']);
    
        // Création de la dépense dans une transaction (UNIQUE ET COMPLÈTE)
        $depense = DB::transaction(function () use ($validated, $user, $caisse, $montantUsd) {
            return Depense::create([
                'succursale_id'        => $user->succursale_id,
                'caisse_id'            => $validated['caisse_id'],
                'id_creator'           => $user->id,
                'categorie_depense_id' => $validated['categorie_depense_id'],
                'devise_id'            => $validated['devise_id'],
                'montant'              => $validated['montant'],
                'montant_usd'          => $montantUsd, // ✅ ajout
                'motif'                => $validated['motif'],
                'reference'            => $validated['reference'] ?? null,
                'date_depense'         => $validated['date_depense'],
                'statut'               => 'EN_ATTENTE',
                'created_by'           => $user->id, 
            ]);
        });
    
        // ✅ NOTIFICATION SELON LE RÔLE DU DEMANDEUR
        $this->notifierNouvelleDepense($depense, $user, $caisse, $categorie);
    
        return response()->json([
            'success' => true,
            'message' => 'Dépense créée, en attente de validation.',
            'data'    => $depense->load(['caisse', 'categorie', 'devise', 'createur']),
        ], 201);
    }

    /**
     * ✅ NOUVELLE MÉTHODE : Notifier selon le rôle du demandeur
     */
    protected function notifierNouvelleDepense($depense, $user, $caisse, $categorie)
    {
        try {
            // Vérifier si le demandeur est un CHEF_AGENCE
            $estChefAgence = $user->role && $user->role->code === 'CHEF_AGENCE';
            
            // Récupérer le nom du demandeur de manière sécurisée
            $nomDemandeur = $user->nom_complet;
            if (empty($nomDemandeur)) {
                $nomDemandeur = $user->email;
                if (empty($nomDemandeur)) {
                    $nomDemandeur = 'Inconnu';
                }
            }
            
            // Récupérer le rôle du demandeur
            $roleDemandeur = $user->role ? $user->role->code : 'Aucun rôle';
            
            // Récupérer la succursale
            $succursaleNom = $user->succursale ? $user->succursale->nom : 'N/A';
            
            // Récupérer la référence
            $reference = $depense->reference ? $depense->reference : 'N/A';
            
            // Récupérer la catégorie
            $categorieNom = $categorie ? $categorie->nom : 'N/A';
            
            // Récupérer la devise
            $deviseCode = $depense->devise ? $depense->devise->code : 'N/A';
            
            // Récupérer le nom de la caisse
            $caisseNom = $caisse->nom ? $caisse->nom : 'N/A';
            
            // Formater le montant
            $montantFormate = number_format($depense->montant, 2, ',', ' ');
            
            // Formater la date
            $dateFormatee = $depense->date_depense->format('d/m/Y');
            
            // Construire le message
            $message = "💳 Nouvelle dépense en attente de validation\n";
            $message .= "----------------------------------------\n";
            $message .= "Référence: {$reference}\n";
            $message .= "Catégorie: {$categorieNom}\n";
            $message .= "Montant: {$montantFormate} {$deviseCode}\n";
            $message .= "Caisse: {$caisseNom}\n";
            $message .= "Motif: {$depense->motif}\n";
            $message .= "Date: {$dateFormatee}\n";
            $message .= "Demandeur: {$nomDemandeur} ({$roleDemandeur})\n";
            $message .= "Succursale: {$succursaleNom}";

            $donnees = [
                'depense_id' => $depense->id,
                'reference' => $depense->reference,
                'caisse_id' => $caisse->id,
                'caisse_nom' => $caisseNom,
                'categorie_id' => $categorie ? $categorie->id : null,
                'categorie_nom' => $categorieNom,
                'montant' => $depense->montant,
                'devise' => $deviseCode,
                'motif' => $depense->motif,
                'date_depense' => $depense->date_depense->toISOString(),
                'demandeur_id' => $user->id,
                'demandeur_nom' => $nomDemandeur,
                'demandeur_role' => $roleDemandeur,
                'succursale_id' => $user->succursale_id,
                'succursale_nom' => $succursaleNom,
                'statut' => $depense->statut
            ];

            // 🔥 SI LE DEMANDEUR EST CHEF_AGENCE → NOTIFIER SUPER_ADMIN
            if ($estChefAgence) {
                $this->notifierDestinatairesSpecifiques([
                    'destinataires' => [
                        'type' => 'role',
                        'code' => 'SUPER_ADMIN'
                    ],
                    'type' => 'NOUVELLE_DEPENSE',
                    'titre' => "⚠️ Dépense demandée par un Chef d'Agence - Validation requise",
                    'message' => $message . "\n\n⚠️ Cette dépense a été demandée par un CHEF_AGENCE. Une validation par un SUPER_ADMIN est requise.",
                    'donnees' => $donnees,
                    'succursale_id' => $user->succursale_id,
                    'priorite' => 'URGENTE'
                ]);

                \Illuminate\Support\Facades\Log::info('Notification de dépense par CHEF_AGENCE envoyée aux SUPER_ADMIN', [
                    'depense_id' => $depense->id,
                    'demandeur' => $user->id,
                    'montant' => $depense->montant
                ]);

            // ✅ SI LE DEMANDEUR N'EST PAS CHEF_AGENCE → NOTIFIER CHEF_AGENCE
            } else {
                $this->notifierDestinatairesSpecifiques([
                    'destinataires' => [
                        'type' => 'role',
                        'code' => 'CHEF_AGENCE'
                    ],
                    'type' => 'NOUVELLE_DEPENSE',
                    'titre' => "💳 Nouvelle dépense en attente - {$categorieNom}",
                    'message' => $message,
                    'donnees' => $donnees,
                    'succursale_id' => $user->succursale_id,
                    'priorite' => 'HAUTE'
                ]);

                \Illuminate\Support\Facades\Log::info('Notification de nouvelle dépense envoyée aux CHEF_AGENCE', [
                    'depense_id' => $depense->id,
                    'demandeur' => $user->id,
                    'montant' => $depense->montant
                ]);

                // 2. Notifier le responsable de la caisse si différent
                if ($caisse->responsable_id && $caisse->responsable_id != $user->id) {
                    $responsable = Utilisateur::find($caisse->responsable_id);
                    if ($responsable && $responsable->role && $responsable->role->code !== 'CHEF_AGENCE') {
                        $this->notifierDestinatairesSpecifiques([
                            'destinataires' => [
                                'type' => 'utilisateur',
                                'id' => $caisse->responsable_id
                            ],
                            'type' => 'NOUVELLE_DEPENSE',
                            'titre' => "💳 Nouvelle dépense sur votre caisse",
                            'message' => "Une nouvelle dépense a été créée sur la caisse {$caisseNom}.\n\n" .
                                         "Montant: {$montantFormate} {$deviseCode}\n" .
                                         "Motif: {$depense->motif}\n" .
                                         "Demandeur: {$nomDemandeur}\n\n" .
                                         "Veuillez valider ou rejeter cette demande.",
                            'donnees' => $donnees,
                            'priorite' => 'NORMALE'
                        ]);
                    }
                }
            }

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Erreur lors de la notification de nouvelle dépense', [
                'error' => $e->getMessage(),
                'depense_id' => $depense->id ? $depense->id : null
            ]);
        }
    }

    /**
     * Afficher une dépense
     */
    public function show($id)
    {
        $user = auth::user();

        $depense = Depense::with(['caisse', 'categorie', 'devise'])->find($id);

        if (!$depense) {
            return response()->json(['success' => false, 'message' => 'Dépense introuvable.'], 404);
        }

        if ($user->succursale_id && $depense->succursale_id != $user->succursale_id) {
            return response()->json(['success' => false, 'message' => 'Accès non autorisé.'], 403);
        }

        $userIds = collect([$depense->created_by, $depense->valide_par])
            ->filter(function($id) {
                return !is_null($id) && is_numeric($id);
            })
            ->unique()
            ->values()
            ->toArray();

        $utilisateurs = empty($userIds) ? collect() : Utilisateur::getUtilisateursByIds($userIds);

        $depense->createur_info = $depense->created_by ? $utilisateurs->get((int) $depense->created_by) : null;
        $depense->validateur_info = $depense->valide_par ? $utilisateurs->get((int) $depense->valide_par) : null;

        return response()->json(['success' => true, 'data' => $depense]);
    }

    /**
     * Mettre à jour une dépense en attente (seulement si non validée)
     */
    public function update(Request $request, $id)
    {
        $depense = Depense::find($id);
        if (!$depense) {
            return response()->json(['success' => false, 'message' => 'Dépense introuvable.'], 404);
        }

        if ($depense->statut !== 'EN_ATTENTE') {
            return response()->json(['success' => false, 'message' => 'Seule une dépense en attente peut être modifiée.'], 422);
        }

        $validated = $request->validate([
            'categorie_depense_id' => 'sometimes|integer|exists:categorie_depense,id',
            'montant'              => 'sometimes|numeric|min:0.01',
            'motif'                => 'sometimes|string|max:255',
            'reference'            => 'nullable|string|max:100',
            'date_depense'         => 'sometimes|date',
        ]);

        $depense->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Dépense mise à jour.',
            'data'    => $depense->fresh(['caisse', 'categorie', 'devise']),
        ]);
    }

    /**
     * Supprimer une dépense en attente
     */
    public function destroy($id)
    {
        $depense = Depense::find($id);
        if (!$depense) {
            return response()->json(['success' => false, 'message' => 'Dépense introuvable.'], 404);
        }

        if ($depense->statut !== 'EN_ATTENTE') {
            return response()->json(['success' => false, 'message' => 'Seule une dépense en attente peut être supprimée.'], 422);
        }

        $depense->delete();

        return response()->json(['success' => true, 'message' => 'Dépense supprimée.']);
    }

    /**
     * Valider une dépense (superviseur) – débite la caisse
     */

    public function valider(Request $request, $id)
    {
        $user = auth::user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Non authentifié.'], 401);
        }
    
        $depense = Depense::find($id);
        if (!$depense) {
            return response()->json(['success' => false, 'message' => 'Dépense introuvable.'], 404);
        }
    
        if ($depense->statut !== 'EN_ATTENTE') {
            return response()->json(['success' => false, 'message' => 'Seule une dépense en attente peut être validée.'], 422);
        }
    
        $validated = $request->validate([
            'notes_validation' => 'nullable|string',
        ]);
        
        
        
        
    
        Log::info('=======================================================================================');
        Log::info('=======================================================================================');
        Log::info('=======================================================================================');
        Log::info('=======================================================================================');
        Log::info($depense->montant .'===='. $depense->devise_id .'===='. $depense->caisse_id .'===='. $depense->succursale_id);


    
        try {
            // Démarre une transaction
            DB::transaction(function () use ($depense, $user, $validated) {
                // 1. Validation de la dépense
                $depense->valider($user->id, $validated['notes_validation'] ?? null);
    
                // 2. Conversion en USD pour l'écriture comptable

                $caisseid = $depense->caisse_id;
                $montantUsd = ExchangeRate::convertirEnUsd(
                    $depense->montant,
                    $depense->devise_id,
                    $depense->caisse_id,
                    $user->succursale_id
                );
                if ($montantUsd === null) {
                    throw new \RuntimeException("Impossible de convertir le montant en USD pour la devise ID {$depense->devise_id}.");
                }
                
                $codeDepense = CategorieDepense::codeById($depense->categorie_depense_id);
    
                // 3. Écriture comptable
                $ecriture = new EcritureService();
                $ecriture->creerEcritureSimple([
                    'montant'           => $montantUsd,
                    'code'              => 'FIT',
                    'entite_id'         => 2,
                    'operation_libelle' => 'DEPENSE ' . strtoupper($codeDepense),
                    'libelle'           => 'Motif dépense ' . strtoupper($codeDepense) . ': ' . $depense->motif,
                    'date_ecriture'     => now()->format('Y-m-d'),
                    'reference'         => $depense->reference
                ]);
    
                Log::info('Écriture Dépense créée avec succès', ['reference' => $depense->reference]);
            });
    
            // En dehors de la transaction : notification (après commit réussi)
            $this->notifierDepenseValidee($depense->fresh(), $user);
    
            return response()->json([
                'success' => true,
                'message' => 'Dépense validée et débitée de la caisse.',
                'data'    => $depense->fresh(['caisse', 'categorie', 'devise']),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Rejeter une dépense
     */
    public function rejeter(Request $request, $id)
    {
        $user = auth::user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Non authentifié.'], 401);
        }

        $depense = Depense::find($id);
        if (!$depense) {
            return response()->json(['success' => false, 'message' => 'Dépense introuvable.'], 404);
        }

        if ($depense->statut !== 'EN_ATTENTE') {
            return response()->json(['success' => false, 'message' => 'Seule une dépense en attente peut être rejetée.'], 422);
        }

        $validated = $request->validate([
            'notes_validation' => 'required|string',
        ]);

        $depense->rejeter($user->id, $validated['notes_validation']);

        $this->notifierDepenseRejetee($depense, $user);

        return response()->json([
            'success' => true,
            'message' => 'Dépense rejetée.',
            'data'    => $depense->fresh(['caisse', 'categorie', 'devise']),
        ]);
    }

    /**
     * ✅ Notifier le demandeur que sa dépense a été validée
     */
    protected function notifierDepenseValidee($depense, $validateur)
    {
        try {
            if ($depense->created_by) {
                $validateurNom = $validateur->nom_complet ? $validateur->nom_complet : 'Validateur';
                $reference = $depense->reference ? $depense->reference : 'N/A';
                $deviseCode = $depense->devise ? $depense->devise->code : '';
                $montantFormate = number_format($depense->montant, 2, ',', ' ');
                $dateFormatee = $depense->date_depense->format('d/m/Y');
                
                $this->notifierDestinatairesSpecifiques([
                    'destinataires' => [
                        'type' => 'utilisateur',
                        'id' => $depense->created_by
                    ],
                    'type' => 'NOUVELLE_DEPENSE',
                    'titre' => "✅ Dépense validée",
                    'message' => "Votre dépense a été validée par {$validateurNom}.\n\n" .
                                 "Référence: {$reference}\n" .
                                 "Montant: {$montantFormate} {$deviseCode}\n" .
                                 "Motif: {$depense->motif}\n" .
                                 "Date: {$dateFormatee}",
                    'donnees' => [
                        'depense_id' => $depense->id,
                        'reference' => $reference,
                        'montant' => $depense->montant,
                        'valide_par' => $validateur->id,
                        'valide_par_nom' => $validateurNom
                    ],
                    'priorite' => 'HAUTE'
                ]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Erreur lors de la notification de validation', [
                'error' => $e->getMessage(),
                'depense_id' => $depense->id
            ]);
        }
    }

    /**
     * ✅ Notifier le demandeur que sa dépense a été rejetée
     */
    protected function notifierDepenseRejetee($depense, $rejeteur)
    {
        try {
            if ($depense->created_by) {
                $rejeteurNom = $rejeteur->nom_complet ? $rejeteur->nom_complet : 'Rejeteur';
                $reference = $depense->reference ? $depense->reference : 'N/A';
                $deviseCode = $depense->devise ? $depense->devise->code : '';
                $montantFormate = number_format($depense->montant, 2, ',', ' ');
                $motifRejet = $depense->notes_validation ? $depense->notes_validation : 'Non spécifié';
                
                $this->notifierDestinatairesSpecifiques([
                    'destinataires' => [
                        'type' => 'utilisateur',
                        'id' => $depense->created_by
                    ],
                    'type' => 'NOUVELLE_DEPENSE',
                    'titre' => "❌ Dépense rejetée",
                    'message' => "Votre dépense a été rejetée par {$rejeteurNom}.\n\n" .
                                 "Référence: {$reference}\n" .
                                 "Montant: {$montantFormate} {$deviseCode}\n" .
                                 "Motif: {$depense->motif}\n" .
                                 "Motif du rejet: {$motifRejet}\n\n" .
                                 "Veuillez contacter votre superviseur pour plus d'informations.",
                    'donnees' => [
                        'depense_id' => $depense->id,
                        'reference' => $reference,
                        'montant' => $depense->montant,
                        'rejete_par' => $rejeteur->id,
                        'rejete_par_nom' => $rejeteurNom,
                        'motif_rejet' => $motifRejet
                    ],
                    'priorite' => 'HAUTE'
                ]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Erreur lors de la notification de rejet', [
                'error' => $e->getMessage(),
                'depense_id' => $depense->id
            ]);
        }
    }

    public function creerCategorie(Request $request)
    {
        $validated = $request->validate([
            'nom'           => 'required|string|max:100|unique:categorie_depense,nom',
            'code'          => 'required|string|max:50|unique:categorie_depense,code',
            'description'   => 'required|string|max:255',
            'actif'         => 'required|boolean',
        ]);

        $categorie = CategorieDepense::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Catégorie de dépense créée avec succès.',
            'data'    => $categorie,
        ], 201);
    }

    /**
     * Récupérer les catégories de dépenses actives
     */
    public function categories()
    {
        $categories = CategorieDepense::where('actif', true)
            ->orderBy('nom')
            ->get();

        return response()->json(['success' => true, 'data' => $categories]);
    }

    /**
     * Mettre à jour une catégorie de dépense.
     */
    public function updateCategorie(Request $request, $id)
    {
        $categorie = CategorieDepense::find($id);

        if (!$categorie) {
            return response()->json([
                'success' => false,
                'message' => 'Catégorie introuvable.',
            ], 404);
        }

        $validated = $request->validate([
            'code'        => 'prohibited|sometimes|string',
            'nom'         => 'sometimes|string|max:100|unique:categorie_depense,nom,' . $id,
            'description' => 'nullable|string',
            'actif'       => 'sometimes|boolean',
        ]);

        unset($validated['code']);

        $categorie->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Catégorie mise à jour avec succès.',
            'data'    => $categorie->fresh(),
        ]);
    }

    /**
     * Supprimer une catégorie de dépense.
     */
    public function destroyCategorie($id)
    {
        $categorie = CategorieDepense::find($id);

        if (!$categorie) {
            return response()->json([
                'success' => false,
                'message' => 'Catégorie introuvable.',
            ], 404);
        }

        if ($categorie->depenses()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer cette catégorie car elle est utilisée par des dépenses.',
                'data'    => [
                    'nb_depenses' => $categorie->depenses()->count(),
                ],
            ], 422);
        }

        $categorie->delete();

        return response()->json([
            'success' => true,
            'message' => 'Catégorie supprimée avec succès.',
            'data'    => [
                'id'   => $categorie->id,
                'code' => $categorie->code,
                'nom'  => $categorie->nom,
            ],
        ]);
    }

    /**
     * Récupérer les caisses éligibles pour une dépense (dans la succursale de l'utilisateur)
     */
    public function caissesEligibles()
    {
        $user = auth()->user();
        if (!$user || !$user->succursale_id) {
            return response()->json(['success' => false, 'message' => 'Succursale non définie.'], 422);
        }

        $caisses = Caisses::where('succursale_id', $user->succursale_id)
            ->where('type_caisse', 'PHYSIQUE')
            ->where('categorie', 'COFFRE')
            ->where('inactif', 0)
            ->where('statut', 'OUVERTE')
            ->with('soldes.devise')
            ->get();

        return response()->json(['success' => true, 'data' => $caisses]);
    }
}