<?php
// app/Http/Controllers/Approvisionnement/LogistiqueController.php

namespace App\Http\Controllers\Approvisionnement;

use App\Http\Controllers\Controller;
use App\Models\Approvisionnement;
use App\Models\StockSuccursale;
use App\Models\SeuilLogistique;
use App\Models\Devise;
use App\Models\Succursale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Traits\Notifiable;
use App\Services\Logistique\ValidationLogistiqueService;

class LogistiqueController extends Controller
{
    use Notifiable;

    protected ValidationLogistiqueService $validationService;

    public function __construct(ValidationLogistiqueService $validationService)
    {
        $this->validationService = $validationService;
    }

    /**
     * Dashboard Logistique - OPTIMISÉ
     */
    public function dashboard()
    {
        $user = auth()->user();

        if (!$user->hasPermission('ops-logistique')) {
            return response()->json([
                'success' => false,
                'message' => 'Accès réservé au Directeur Logistique'
            ], 403);
        }

        //  1. Récupérer les statistiques en UNE SEULE requête groupée
        $stats = $this->getDashboardStats();

        //  2. Récupérer les demandes avec les relations nécessaires uniquement
        $enAttenteLogistique = Approvisionnement::enAttenteLogistique()
            ->with([
                'destinationSuccursale:id,nom',
                'sourceDecidee:id,nom',
                'demandeur:id,nom_complet',
                'validateurOps:id,nom_complet',
                //  Ne charger que l'ID et le nom des produits pour réduire la taille
                'lignes' => function($query) {
                    $query->select('id', 'approvisionnement_id', 'produit_id', 'quantite_demandee')
                        ->with(['produit:id,nom']);
                }
            ])
            ->select([
                'id', 'reference', 'type_demande', 'destination_succursale_id',
                'source_decidee_id', 'quantite_demandee', 'date_demande',
                'date_validation_ops', 'statut_operation', 'statut_demande',
                'demandeur_id', 'validateur_ops_id', 'created_at'
            ])
            ->orderBy('date_validation_ops', 'asc')
            ->limit(100) //  Limiter pour éviter le surcharge
            ->get();

        return response()->json([
            'success' => true,
            'stats' => $stats,
            'en_attente_validation' => $enAttenteLogistique
        ]);
    }

    /**
     * Récupère toutes les statistiques en UNE SEULE requête
     */
    private function getDashboardStats(): array
    {
        //  Une seule requête avec des sous-requêtes optimisées
        $stats = Approvisionnement::selectRaw("
            COUNT(CASE WHEN statut_operation = 'EN_ATTENTE_LOGISTIQUE' THEN 1 END) as en_attente_logistique,
            COUNT(CASE WHEN statut_operation = 'EN_ATTENTE_APPROBATION_LOGISTIQUE' THEN 1 END) as en_attente_approbation,
            COUNT(CASE WHEN statut_operation = 'VALIDE' THEN 1 END) as valides,
            COUNT(CASE WHEN statut_demande = 'In Transit' THEN 1 END) as en_transit,
            COUNT(CASE WHEN statut_operation = 'EXPEDIE' THEN 1 END) as expedies,
            COUNT(CASE WHEN statut_demande = 'Received' THEN 1 END) as recus,
            COUNT(CASE WHEN statut_demande = 'Partially Received' THEN 1 END) as avec_ecart
        ")->first();

        return [
            'en_attente_logistique' => (int) ($stats->en_attente_logistique ?? 0),
            'en_attente_approbation' => (int) ($stats->en_attente_approbation ?? 0),
            'valides' => (int) ($stats->valides ?? 0),
            'en_transit' => (int) ($stats->en_transit ?? 0),
            'expedies' => (int) ($stats->expedies ?? 0),
            'recus' => (int) ($stats->recus ?? 0),
            'avec_ecart' => (int) ($stats->avec_ecart ?? 0)
        ];
    }

    /**
     * Afficher une demande pour validation logistique - OPTIMISÉ
     */
    public function show($id)
    {
        $user = auth()->user();
    
        if (!$user->hasPermission('ops-logistique')) {
            return response()->json([
                'success' => false,
                'message' => 'Accès réservé au Directeur Logistique'
            ], 403);
        }
    
        //  Chargement optimisé : sélectionner uniquement les colonnes nécessaires
        $demande = Approvisionnement::with([
            'destinationSuccursale:id,nom,adresse,telephone,email',
            'sourceDecidee:id,nom,adresse,telephone,email',
            'intermediaireSuccursale:id,nom,adresse,telephone,email',
            'demandeur:id,nom_complet,email',
            'validateurOps:id,nom_complet,email',
            'devise:id,code,nom,symbole',
            'lignes' => function($query) {
                $query->select('id', 'approvisionnement_id', 'produit_id', 'quantite_demandee', 'quantite_envoyee', 'quantite_recue')
                    ->with(['produit:id,nom']);
            }
        ])
        ->select([
            'id', 'reference', 'type_demande', 'destination_succursale_id',
            'source_decidee_id', 'intermediaire_succursale_id',
            'quantite_demandee', 'quantite_envoyee', 'quantite_recue',
            'date_demande', 'date_validation_ops', 'statut_operation',
            'statut_demande', 'demandeur_id', 'validateur_ops_id',
            'devise_id', 'mode_transport', 'type_parcours',
            'cout_total', 'poids_total', 'volume_total',
            'avec_intermediaire', 'notes', 'created_at'
        ])
        ->findOrFail($id);
    
        if ($demande->statut_operation !== 'EN_ATTENTE_LOGISTIQUE') {
            return response()->json([
                'success' => false,
                'message' => 'Cette demande n\'est pas en attente de validation logistique. Statut actuel: ' . $demande->statut_operation
            ], 422);
        }
    
        //  Récupérer toutes les succursales actives
        $succursales = Succursale::where('est_active', true)
            ->select('id', 'nom', 'code', 'ville', 'adresse')
            ->orderBy('nom')
            ->get();
    
        //  Récupérer les devises
        $devises = Devise::orderBy('code')
            ->select('id', 'code', 'nom', 'symbole')
            ->get();
    
        //  Récupérer les succursales intermédiaires possibles (toutes sauf la source et la destination)
        $succursalesIntermediaires = Succursale::where('est_active', true)
            ->where('id', '!=', $demande->destination_succursale_id)
            ->when($demande->source_decidee_id, function($query) use ($demande) {
                return $query->where('id', '!=', $demande->source_decidee_id);
            })
            ->select('id', 'nom', 'code', 'ville')
            ->orderBy('nom')
            ->get();
    
        return response()->json([
            'success' => true,
            'data' => $demande,
            'succursales' => $succursales,
            'succursales_intermediaires' => $succursalesIntermediaires, //  Nouvelle clé pour les intermédiaires
            'devises' => $devises
        ]);
    }

    /**
     * Valider la partie logistique d'une demande - OPTIMISÉ
     */
    public function valider($id, Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermission('valider-logistique')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour valider la partie logistique'
            ], 403);
        }

        //  Sélectionner uniquement les colonnes nécessaires
        $demande = Approvisionnement::lockForUpdate()
            ->with([
                'lignes' => function($query) {
                    $query->select('id', 'approvisionnement_id', 'produit_id', 'quantite_demandee', 'quantite_envoyee')
                        ->with(['produit:id,nom']);
                },
                'destinationSuccursale:id,nom',
                'sourceDecidee:id,nom',
                'demandeur:id,nom_complet'
            ])
            ->select([
                'id', 'reference', 'type_demande', 'destination_succursale_id',
                'source_decidee_id', 'quantite_demandee', 'quantite_envoyee',
                'statut_operation', 'statut_demande', 'notes', 'demandeur_id',
                'date_validation_ops'
            ])
            ->findOrFail($id);

        if ($demande->statut_operation !== 'EN_ATTENTE_LOGISTIQUE') {
            return response()->json([
                'success' => false,
                'message' => 'Cette demande n\'est pas en attente de validation logistique. Statut actuel: ' . $demande->statut_operation
            ], 422);
        }

        // Validation des données
        $validator = $this->validateDonneesLogistique($request);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        //  Vérification du stock optimisée en UNE SEULE requête
        $erreursStock = $this->verifierStockSourceOptimise($demande, $request->source_decidee_id);

        if (!empty($erreursStock)) {
            $this->notifierStockInsuffisantLogistique($demande, $erreursStock, $user, $request->source_decidee_id);
            return response()->json([
                'success' => false,
                'message' => 'Stock insuffisant à la source sélectionnée pour organiser le transport',
                'erreurs' => $erreursStock
            ], 422);
        }

        DB::beginTransaction();

        try {
            $devise = Devise::select('id', 'code')->find($request->devise_id);
            $sourceSuccursale = Succursale::select('id', 'nom')->find($request->source_decidee_id);
            $coutTotal = $request->cout_total ?? 0;

            // Vérifier les seuils
            $verificationSeuil = $this->validationService->verifierNiveauApprobation(
                $demande,
                $coutTotal,
                $request->devise_id
            );

            // Préparer les données
            $updateData = $this->preparerDonneesLogistique($request, $user, $devise, $sourceSuccursale, $coutTotal);

            if ($verificationSeuil['necessite_approbation']) {
                $resultat = $this->traiterAvecApprobationSuperieure(
                    $demande,
                    $updateData,
                    $verificationSeuil,
                    $devise,
                    $sourceSuccursale,
                    $user
                );
            } else {
                $resultat = $this->traiterValidationDirecte(
                    $demande,
                    $updateData,
                    $sourceSuccursale,
                    $user,
                    $request->type_demande
                );
            }

            DB::commit();

            return response()->json($resultat);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    // ============================================================
    // MÉTHODES PRIVÉES OPTIMISÉES
    // ============================================================

    /**
     * Valide les données de la requête logistique
     */
    private function validateDonneesLogistique(Request $request)
    {
        $rules = [
            'source_decidee_id' => 'required|exists:succursales,id|different:destination_succursale_id',
            'type_demande' => 'required|in:TRANSFERT,APPROVISIONNEMENT',
            'type_parcours' => 'required|in:DIRECT,AVEC_INTERMEDIAIRE',
            'mode_transport' => 'required|in:AERIEN,TERRESTRE,MARITIME,AUTRE',
            'devise_id' => 'required|exists:devise,id',
            'poids_total' => 'nullable|numeric|min:0',
            'volume_total' => 'nullable|numeric|min:0',
            'valeur_totale' => 'nullable|numeric|min:0',
            'cout_transport' => 'nullable|numeric|min:0',
            'cout_assurance' => 'nullable|numeric|min:0',
            'cout_emballage' => 'nullable|numeric|min:0',
            'cout_total' => 'nullable|numeric|min:0',
            'notes_logistique' => 'nullable|string'
        ];

        if ($request->type_parcours === 'AVEC_INTERMEDIAIRE') {
            $rules['intermediaire_id'] = 'required|exists:succursales,id|different:source_decidee_id|different:destination_succursale_id';
        }

        return Validator::make($request->all(), $rules);
    }

    /**
     * Vérifie le stock à la source choisie - OPTIMISÉ avec UNE SEULE requête
     */
    private function verifierStockSourceOptimise(Approvisionnement $demande, int $sourceId): array
    {
        //  Récupérer tous les stocks en UNE SEULE requête
        $produitIds = $demande->lignes->pluck('produit_id')->toArray();

        $stocks = StockSuccursale::where('succursale_id', $sourceId)
            ->whereIn('produit_id', $produitIds)
            ->select('produit_id', 'quantite_disponible', 'quantite_reserve')
            ->get()
            ->keyBy('produit_id');

        $erreurs = [];

        foreach ($demande->lignes as $ligne) {
            $stock = $stocks->get($ligne->produit_id);
            $stockDisponible = $stock ? ($stock->quantite_disponible - $stock->quantite_reserve) : 0;

            if ($stockDisponible < $ligne->quantite_demandee) {
                $erreurs[] = [
                    'produit' => $ligne->produit->nom ?? 'Produit inconnu',
                    'disponible' => $stockDisponible,
                    'demande' => $ligne->quantite_demandee
                ];
            }
        }

        return $erreurs;
    }

    /**
     * Prépare les données de mise à jour logistique
     */
    private function preparerDonneesLogistique(Request $request, $user, $devise, $sourceSuccursale, float $coutTotal): array
    {
        $typesDemande = [
            'TRANSFERT' => 'Transfert interne',
            'APPROVISIONNEMENT' => 'Approvisionnement entreprise'
        ];

        $baseNotes = "VALIDATION LOGISTIQUE par {$user->nom_complet}\n" .
            "Source choisie: " . ($sourceSuccursale->nom ?? 'N/A') . "\n" .
            "Type de demande: " . ($typesDemande[$request->type_demande] ?? $request->type_demande) . "\n" .
            "Transport: " . (Approvisionnement::MODE_TRANSPORT[$request->mode_transport] ?? $request->mode_transport) . "\n" .
            "Parcours: " . ($request->type_parcours === 'AVEC_INTERMEDIAIRE' ? 'Avec escale' : 'Direct') . "\n" .
            "Devise: " . ($devise ? $devise->code : 'Non spécifiée') . "\n" .
            "Coût total: " . number_format($coutTotal, 2) . " " . ($devise ? $devise->code : '') . "\n" .
            ($request->notes_logistique ?? '');

        return [
            'source_decidee_id' => $request->source_decidee_id,
            'type_demande_logistique' => $request->type_demande,
            'type_parcours' => $request->type_parcours,
            'mode_transport' => $request->mode_transport,
            'devise_id' => $request->devise_id,
            'avec_intermediaire' => $request->type_parcours === 'AVEC_INTERMEDIAIRE',
            'intermediaire_succursale_id' => $request->intermediaire_id ?? null,
            'poids_total' => $request->poids_total,
            'volume_total' => $request->volume_total,
            'valeur_totale' => $request->valeur_totale,
            'cout_transport' => $request->cout_transport,
            'cout_assurance' => $request->cout_assurance,
            'cout_emballage' => $request->cout_emballage,
            'cout_total' => $coutTotal,
            'responsable_logistique_id' => $user->id,
            'date_validation_logistique' => now(),
            'notes_base' => $baseNotes,
            'types_demande' => $typesDemande
        ];
    }

    /**
     * Traite une validation nécessitant une approbation supérieure
     */
    private function traiterAvecApprobationSuperieure(
        Approvisionnement $demande,
        array $data,
        array $verificationSeuil,
        $devise,
        $sourceSuccursale,
        $user
    ): array {
        $updateData = [
            'source_decidee_id' => $data['source_decidee_id'],
            'type_demande_logistique' => $data['type_demande_logistique'],
            'type_parcours' => $data['type_parcours'],
            'mode_transport' => $data['mode_transport'],
            'devise_id' => $data['devise_id'],
            'avec_intermediaire' => $data['avec_intermediaire'],
            'intermediaire_succursale_id' => $data['intermediaire_succursale_id'],
            'poids_total' => $data['poids_total'],
            'volume_total' => $data['volume_total'],
            'valeur_totale' => $data['valeur_totale'],
            'cout_transport' => $data['cout_transport'],
            'cout_assurance' => $data['cout_assurance'],
            'cout_emballage' => $data['cout_emballage'],
            'cout_total' => $data['cout_total'],
            'responsable_logistique_id' => $user->id,
            'date_validation_logistique' => now(),
            'statut_operation' => 'EN_ATTENTE_APPROBATION_LOGISTIQUE',
            'statut_approbation_logistique' => 'EN_ATTENTE',
            'notes' => ($demande->notes ? $demande->notes . "\n" : '') .
                $data['notes_base'] . "\n" .
                "⚠️ EN ATTENTE APPROBATION SUPERIEURE\n" .
                "Seuil dépassé: " . number_format($verificationSeuil['seuil_approbation'], 2) . " " . ($devise ? $devise->code : '')
        ];

        //  Mise à jour optimisée (sans recharger tout le modèle)
        $demande->update($updateData);

        $this->notifierApprobationSuperieureRequise($demande, $user, $verificationSeuil, $sourceSuccursale);

        //  Recharger uniquement les relations nécessaires
        $demande->load([
            'sourceDecidee:id,nom',
            'destinationSuccursale:id,nom',
            'intermediaireSuccursale:id,nom',
            'devise:id,code'
        ]);

        return [
            'success' => true,
            'message' => 'Validation logistique enregistrée. En attente d\'approbation supérieure.',
            'statut' => 'EN_ATTENTE_APPROBATION',
            'seuil_info' => $verificationSeuil,
            'data' => $demande
        ];
    }

    /**
     * Traite une validation directe (sans approbation supérieure)
     */
    private function traiterValidationDirecte(
        Approvisionnement $demande,
        array $data,
        $sourceSuccursale,
        $user,
        string $typeDemande
    ): array {
        $updateData = [
            'source_decidee_id' => $data['source_decidee_id'],
            'type_demande_logistique' => $data['type_demande_logistique'],
            'type_parcours' => $data['type_parcours'],
            'mode_transport' => $data['mode_transport'],
            'devise_id' => $data['devise_id'],
            'avec_intermediaire' => $data['avec_intermediaire'],
            'intermediaire_succursale_id' => $data['intermediaire_succursale_id'],
            'poids_total' => $data['poids_total'],
            'volume_total' => $data['volume_total'],
            'valeur_totale' => $data['valeur_totale'],
            'cout_transport' => $data['cout_transport'],
            'cout_assurance' => $data['cout_assurance'],
            'cout_emballage' => $data['cout_emballage'],
            'cout_total' => $data['cout_total'],
            'responsable_logistique_id' => $user->id,
            'date_validation_logistique' => now(),
            'statut_operation' => 'VALIDE',
            'statut_approbation_logistique' => null,
            'notes' => ($demande->notes ? $demande->notes . "\n" : '') . $data['notes_base']
        ];

        $demande->update($updateData);

        // Réserver le stock
        $this->reserverStockSource($demande, $data['source_decidee_id']);

        $this->notifierValidationLogistique($demande, $user, $typeDemande, $sourceSuccursale);

        //  Recharger uniquement les relations nécessaires
        $demande->load([
            'sourceDecidee:id,nom',
            'destinationSuccursale:id,nom',
            'intermediaireSuccursale:id,nom',
            'devise:id,code'
        ]);

        return [
            'success' => true,
            'message' => 'Validation logistique effectuée avec succès. La demande peut maintenant être préparée.',
            'statut' => 'VALIDE',
            'data' => $demande
        ];
    }

    /**
     * Réserver le stock à la source choisie - OPTIMISÉ
     */
    private function reserverStockSource(Approvisionnement $demande, int $sourceId): void
    {
        //  Mise à jour en BULK pour éviter N+1
        $produitIds = $demande->lignes->pluck('produit_id')->toArray();
        $quantites = [];

        foreach ($demande->lignes as $ligne) {
            $quantites[$ligne->produit_id] = $ligne->quantite_demandee;
        }

        // Récupérer tous les stocks en UNE SEULE requête
        $stocks = StockSuccursale::where('succursale_id', $sourceId)
            ->whereIn('produit_id', $produitIds)
            ->get();

        // Mise à jour en boucle mais avec des requêtes optimisées
        foreach ($stocks as $stock) {
            if (isset($quantites[$stock->produit_id])) {
                $stock->quantite_reserve += $quantites[$stock->produit_id];
                $stock->save();
            }
        }
    }

    // ============================================================
    // NOTIFICATIONS - Structurées et optimisées
    // ============================================================

    private function notifierValidationLogistique($demande, $user, $typeDemande, $sourceSuccursale): void
    {
        $typesDemande = [
            'TRANSFERT' => 'Transfert interne',
            'APPROVISIONNEMENT' => 'Approvisionnement entreprise'
        ];

        $typeDemandeTexte = $typesDemande[$typeDemande] ?? $typeDemande;
        $devise = $demande->devise;

        $messageTransport = "Source: " . ($sourceSuccursale->nom ?? 'N/A') . " | Type: {$typeDemandeTexte} | Transport: " . (Approvisionnement::MODE_TRANSPORT[$demande->mode_transport] ?? $demande->mode_transport);

        if ($demande->avec_intermediaire && $demande->intermediaireSuccursale) {
            $messageTransport .= " avec passage par " . $demande->intermediaireSuccursale->nom;
        }

        if ($demande->cout_total > 0 && $devise) {
            $messageTransport .= " | Coût total: " . number_format($demande->cout_total, 2) . " " . $devise->code;
        }

        if ($demande->demandeur_id) {
            $this->notifierDestinatairesSpecifiques([
                'destinataires' => ['type' => 'utilisateur', 'id' => $demande->demandeur_id],
                'type' => 'APPROVISIONNEMENT_VALIDE',
                'titre' => "🚚 Organisation du transport finalisée",
                'message' => "Demande #{$demande->reference} - {$messageTransport}",
                'donnees' => [
                    'demande_id' => $demande->id,
                    'reference' => $demande->reference,
                    'source_decidee_id' => $demande->source_decidee_id,
                    'source_nom' => $sourceSuccursale->nom ?? 'N/A',
                    'type_demande' => $typeDemande,
                    'mode_transport' => $demande->mode_transport,
                    'avec_intermediaire' => $demande->avec_intermediaire,
                    'cout_total' => $demande->cout_total,
                    'devise' => $devise ? $devise->code : null
                ],
                'succursale_id' => $demande->destination_succursale_id,
                'priorite' => 'HAUTE'
            ]);
        }

        if ($demande->source_decidee_id) {
            $this->notifierDestinatairesSpecifiques([
                'destinataires' => ['type' => 'responsable_succursale', 'succursale_id' => $demande->source_decidee_id],
                'type' => 'DEMANDE_APPROVISIONNEMENT',
                'titre' => "📋 Demande prête à être préparée",
                'message' => "Demande #{$demande->reference} ({$typeDemandeTexte}) - La validation logistique est terminée.",
                'donnees' => [
                    'demande_id' => $demande->id,
                    'reference' => $demande->reference,
                    'type_demande' => $typeDemande,
                    'mode_transport' => $demande->mode_transport,
                    'quantite_totale' => $demande->quantite_demandee
                ],
                'succursale_id' => $demande->source_decidee_id,
                'priorite' => 'URGENTE'
            ]);
        }

        $this->notifierDestinatairesSpecifiques([
            'destinataires' => ['type' => 'role', 'code' => 'CHEF_AGENCE'],
            'type' => 'APPROVISIONNEMENT_VALIDE',
            'titre' => " Validation logistique terminée",
            'message' => "Demande #{$demande->reference} - Source: " . ($sourceSuccursale->nom ?? 'N/A') . " - {$typeDemandeTexte}",
            'donnees' => [
                'demande_id' => $demande->id,
                'reference' => $demande->reference,
                'source_decidee_id' => $demande->source_decidee_id,
                'source_nom' => $sourceSuccursale->nom ?? 'N/A',
                'type_demande' => $typeDemande,
                'mode_transport' => $demande->mode_transport
            ],
            'priorite' => 'NORMALE'
        ]);
    }

    private function notifierStockInsuffisantLogistique($demande, array $erreursStock, $user, ?int $sourceTenteeId = null): void
    {
        $messageErreurs = '';
        foreach ($erreursStock as $erreur) {
            $messageErreurs .= "- {$erreur['produit']}: besoin {$erreur['demande']}, disponible {$erreur['disponible']}\n";
        }

        $sourceNom = null;
        if ($sourceTenteeId) {
            $source = Succursale::select('id', 'nom')->find($sourceTenteeId);
            $sourceNom = $source ? $source->nom : 'Source inconnue';
        }

        $this->notifierDestinatairesSpecifiques([
            'destinataires' => ['type' => 'role', 'code' => 'SUPER_ADMIN'],
            'type' => 'ALERTE_STOCK',
            'titre' => "⚠️ Stock insuffisant - Organisation logistique impossible",
            'message' => "Demande #{$demande->reference} (Validation Logistique):\n" . $messageErreurs,
            'donnees' => [
                'demande_id' => $demande->id,
                'reference' => $demande->reference,
                'type_demande' => $demande->type_demande_logistique ?? 'Non spécifié',
                'source_tentee' => $sourceNom,
                'destination' => $demande->destinationSuccursale->nom ?? 'Destination inconnue',
                'erreurs' => $erreursStock
            ],
            'priorite' => 'URGENTE'
        ]);
    }

    private function notifierApprobationSuperieureRequise($demande, $user, array $seuilInfo, $sourceSuccursale): void
    {
        $devise = $demande->devise;

        $this->notifierDestinatairesSpecifiques([
            'destinataires' => ['type' => 'role', 'code' => $seuilInfo['role_approbateur'] ?? 'DIRECTEUR_FINANCIER'],
            'type' => 'DEMANDE_APPROVISIONNEMENT',
            'titre' => "⚠️ Validation supérieure requise - Logistique",
            'message' => "Demande #{$demande->reference}\n" .
                "Source: " . ($sourceSuccursale->nom ?? 'N/A') . "\n" .
                "Destination: " . ($demande->destinationSuccursale->nom ?? 'N/A') . "\n" .
                "Coût total: " . number_format($demande->cout_total, 2) . " " . ($devise ? $devise->code : 'N/A') . "\n" .
                "Seuil d'approbation: " . number_format($seuilInfo['seuil_approbation'], 2) . " " . ($devise ? $devise->code : 'N/A'),
            'donnees' => [
                'demande_id' => $demande->id,
                'reference' => $demande->reference,
                'source_id' => $demande->source_decidee_id,
                'source_nom' => $sourceSuccursale->nom ?? 'N/A',
                'destination_nom' => $demande->destinationSuccursale->nom ?? 'N/A',
                'cout_total' => $demande->cout_total,
                'devise' => $devise ? $devise->code : 'N/A',
                'seuil_approbation' => $seuilInfo['seuil_approbation'],
                'valide_par_logistique' => $user->nom_complet
            ],
            'priorite' => 'URGENTE'
        ]);

        $this->notifierDestinatairesSpecifiques([
            'destinataires' => ['type' => 'utilisateur', 'id' => $user->id],
            'type' => 'APPROVISIONNEMENT_VALIDE',
            'titre' => "⏳ Demande en attente d'approbation supérieure",
            'message' => "Demande #{$demande->reference} - En attente de validation par le supérieur hiérarchique",
            'donnees' => [
                'demande_id' => $demande->id,
                'reference' => $demande->reference
            ],
            'priorite' => 'HAUTE'
        ]);
    }
}