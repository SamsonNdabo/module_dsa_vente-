<?php

namespace App\Http\Controllers\TauxChange;

use App\Http\Controllers\Controller;
use App\Models\TauxBancaire;
use App\Models\Devise;
use App\Models\AchatDevise;
use App\Models\Caisses;
use App\Models\Depense;
use App\Models\CategorieDepense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class TauxBancaireController extends Controller
{
    /**
     * Enregistre un nouveau taux bancaire unique pour une devise.
     */
    public function storeTaux(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Non authentifié.'], 401);
        }

        $validated = $request->validate([
            'devise_id' => 'required|integer|exists:devise,id',
            'taux'      => 'required|numeric|min:0',
            'date_taux' => 'required|date',
            'reference' => 'nullable|string|max:100',
            'notes'     => 'nullable|string',
        ]);

        $taux = TauxBancaire::create([
            'devise_id' => $validated['devise_id'],
            'taux'      => $validated['taux'],
            'date_taux' => $validated['date_taux'],
            'reference' => $validated['reference'],
            'notes'     => $validated['notes'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Taux bancaire enregistré.',
            'data'    => $taux->load('devise'),
        ], 201);
    }

    /**
     * Récupère le dernier taux bancaire pour une devise donnée.
     */
    public function dernierTaux($deviseId)
    {
        $taux = TauxBancaire::with('devise')
            ->dernier($deviseId)->first();

        if (!$taux) {
            return response()->json(['success' => false, 'message' => 'Aucun taux trouvé pour cette devise.'], 404);
        }

        return response()->json(['success' => true, 'data' => $taux]);
    }

    /**
     * Moyenne des taux bancaires pour une devise et une date donnée.
     */
    public function moyenneTaux()
    {
        $moyenne = TauxBancaire::moyenneTaux();
    
        return response()->json([
            'success' => true,
            'data'    => ['moyenne_taux' => round($moyenne, 4)],
        ]);
    }

    /**
     * Enregistrer un achat de devise à la banque.
     * On débite la caisse en CDF, on crédite la caisse dans la devise achetée.
     * Le taux utilisé peut être fourni ou pris du dernier taux bancaire.
     */
    public function acheterDevise(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Non authentifié.'], 401);
        }

        $validated = $request->validate([
            'devise_id'          => 'required|integer|exists:devise,id',
            'montant_achete'     => 'required|numeric|min:0.01',
            'taux_utilise'       => 'nullable|numeric|min:0',
            'date_achat'         => 'required|date',
            'reference'          => 'nullable|string|max:100',
            'motif'              => 'nullable|string|max:255',
            'caisse_id'          => 'required|integer|exists:caisses,id',
            'depense_id'         => 'nullable|integer|exists:depenses,id',
        ]);

        $deviseId = $validated['devise_id'];
        $montantAchete = (float) $validated['montant_achete'];

        // Récupérer le taux à utiliser
        if (isset($validated['taux_utilise'])) {
            $taux = (float) $validated['taux_utilise'];
            $tauxBancaireId = null;
        } else {
            $tauxBancaire = TauxBancaire::dernier($deviseId)->first();
            if (!$tauxBancaire) {
                return response()->json(['success' => false, 'message' => 'Aucun taux bancaire trouvé.'], 422);
            }
            $taux = (float) $tauxBancaire->taux;
            $tauxBancaireId = $tauxBancaire->id;
        }

        $montantCdf = round($montantAchete * $taux, 4);
        $cdfId = Devise::getIdByCode('CDF'); 

        // Vérifier la caisse
        $caisse = Caisses::find($validated['caisse_id']);
        if (!$caisse || $caisse->statut != 'OUVERTE') {
            return response()->json(['success' => false, 'message' => 'Caisse invalide ou fermée.'], 422);
        }

        try {
            DB::transaction(function () use (
                $user, $validated, $caisse, $montantCdf, $cdfId, $taux,
                $tauxBancaireId, $montantAchete, $deviseId
            ) {
                // 1. Créer l'enregistrement d'achat
                $achat = AchatDevise::create([
                    'devise_id'         => $deviseId,
                    'montant_devise'    => $montantAchete,
                    'montant_cdf'       => $montantCdf,
                    'taux_utilise'      => $taux,
                    'taux_bancaire_id'  => $tauxBancaireId,
                    'date_achat'        => $validated['date_achat'],
                    'reference'         => $validated['reference'] ?? null,
                    'motif'             => $validated['motif'] ?? null,
                    'caisse_cdf_id'     => $caisse->id,
                    'caisse_devise_id'  => $caisse->id,
                    'depense_id'        => $validated['depense_id'] ?? null,
                    'created_by'        => $user->id,
                ]);

                // 2. Débit en CDF (paiement à la banque) avec id_type = id de l'achat
                if (!$caisse->debiter(
                    $montantCdf,
                    $cdfId,
                    "Achat de devise - paiement en CDF",
                    $achat->reference ?? 'ACHAT-' . $achat->id,
                    $user->id,
                    'DEPENSE',  // type
                    $achat->id  // id_type
                )) {
                    throw new \Exception('Échec du débit de la caisse (paiement CDF).');
                }

                // 3. Crédit en devise étrangère (ajout de la devise achetée) avec id_type = id de l'achat
                if (!$caisse->crediter(
                    $montantAchete,
                    $deviseId,
                    "Achat de devise - réception de la devise",
                    $achat->reference ?? 'ACHAT-' . $achat->id,
                    $user->id,
                    'ACHAT_DEVISE', // type
                    $achat->id      // id_type
                )) {
                    throw new \Exception('Échec du crédit de la caisse (réception devise).');
                }

                // 4. Si une dépense est liée, la valider
                if ($validated['depense_id'] ?? false) {
                    $depense = Depense::find($validated['depense_id']);
                    if ($depense && $depense->statut === 'EN_ATTENTE') {
                        $depense->statut = 'VALIDE';
                        $depense->valide_par = $user->id;
                        $depense->date_validation = now();
                        $depense->save();
                    }
                } else {

                    // Créer une depense
                    $categorie = (new CategorieDepense())->idByCodeDepense('ACHAT_DEVISE');
                    Depense::create([
                        'succursale_id'        => $user->succursale_id,
                        'caisse_id'            => $validated['caisse_id'],
                        'categorie_depense_id' => $categorie,
                        'devise_id'            => $validated['devise_id'],
                        'montant'              => $montantCdf,
                        'motif'                => $validated['motif'],
                        'reference'            => $validated['reference'] ?? null,
                        'date_depense'         => now(),
                        'statut'               => 'VALIDE',
                        'created_by'           => $user->id,
                    ]);
                }

            });

            return response()->json([
                'success' => true,
                'message' => 'Achat de devise effectué avec succès.',
                'data'    => $achat ?? null,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur : ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Simuler la marge réalisée sur un achat de devise.
     * Affiche les taux bancaires (achat/vente) et les taux système (achat/vente).
     */
    public function simulerMarge(Request $request)
    {
        $validated = $request->validate([
            'devise_id'          => 'required|integer|exists:devise,id',
            'montant_achete'     => 'required|numeric|min:0.01',
            'succursale_id'      => 'nullable|integer|exists:succursales,id',
            'taux'               => 'nullable|numeric|min:0',
        ]);

        $deviseId = $validated['devise_id'];
        $montantAchete = (float) $validated['montant_achete'];
        $succursaleId = $validated['succursale_id'] ?? Auth()->user()->succursale_id;;

        // 1. Déterminer les taux bancaires
        if (isset($validated['taux'])) {
            $tauxBancaire = (float) $validated['taux'];
        } else {
            $tauxBancaire = TauxBancaire::dernier($deviseId)->first();
            if (!$tauxBancaire) {
                return response()->json(['success' => false, 'message' => 'Aucun taux bancaire trouvé pour cette devise.'], 422);
            }
            $tauxBancaire = (float) $tauxBancaire->taux;
        }

        // 2. Récupérer les taux système via getTauxApplicables
        $cdfId = Devise::getIdByCode('CDF'); 

        // Taux système d'achat : devise -> CDF (prix de vente de la devise en CDF)
        [$tauxAchatSysteme, $tauxVenteSysteme, $sens] = \App\Models\OperationChange::getTauxApplicables(
            $deviseId,
            $cdfId,
            null,
            $succursaleId,
            null,
            null
        );

        if ($tauxAchatSysteme == 0 || $tauxVenteSysteme == 0 || $sens === null) {
            return response()->json(['success' => false, 'message' => 'Taux système (devise -> CDF) introuvable.'], 422);
        }

        // Coût d'achat en CDF (en achetant à la banque au taux d'achat bancaire)
        $coutAchat = round($montantAchete * $tauxBancaire, 5);

        // Recette en vendant la devise au taux système (prix de vente de la devise en CDF)
        $recetteVente = round($montantAchete * $tauxVenteSysteme, 4);

        // Marge brute (différence entre recette vente système et coût achat bancaire)
        $margeBrute = round($recetteVente - $coutAchat, 4);
        $margePourcentage = $coutAchat > 0 ? round(($margeBrute / $coutAchat) * 100, 2) : 0;


        return response()->json([
            'success' => true,
            'data'    => [
                'devise_id'                => $deviseId,
                'montant_achete'           => $montantAchete,
                'taux_bancaire'            => $tauxBancaire, 
                'taux_vente_systeme'       => $tauxVenteSysteme,     
                'cout_achat_cdf'           => $coutAchat, 
                'recette_vente_cdf'        => $recetteVente,
                'marge_vente_cdf'          => $margeBrute,
                'marge_vente_pourcentage'  => $margePourcentage,
            ],
        ]);
    }

    /**
     * Liste des achats de devises avec filtres.
     */
    public function indexAchats(Request $request)
    {
        $query = AchatDevise::with(['devise', 'caisseCdf', 'caisseDevise', 'createur', 'tauxBancaire']);

        if ($request->filled('devise_id')) {
            $query->where('devise_id', $request->devise_id);
        }
        if ($request->filled('date_debut')) {
            $query->whereDate('date_achat', '>=', $request->date_debut);
        }
        if ($request->filled('date_fin')) {
            $query->whereDate('date_achat', '<=', $request->date_fin);
        }
        if ($request->filled('caisse_cdf_id')) {
            $query->where('caisse_cdf_id', $request->caisse_cdf_id);
        }
        if ($request->filled('caisse_devise_id')) {
            $query->where('caisse_devise_id', $request->caisse_devise_id);
        }

        $achats = $query->orderBy('date_achat', 'desc')->paginate(20);

        return response()->json(['success' => true, 'data' => $achats]);
    }

    public function indexTaux(Request $request)
    {
        $query = TauxBancaire::with('devise');

        // Filtre par devise (ID exact)
        $query->when($request->input('devise_id'), function ($q, $deviseId) {
            $q->where('devise_id', $deviseId);
        });

        // Filtre par date exacte
        $query->when($request->input('date_taux'), function ($q, $date) {
            $q->whereDate('date_taux', $date);
        });

        // Filtre par plage de dates (du ... au ...)
        $query->when($request->input('date_debut'), function ($q, $dateDebut) {
            $q->whereDate('date_taux', '>=', $dateDebut);
        });
        $query->when($request->input('date_fin'), function ($q, $dateFin) {
            $q->whereDate('date_taux', '<=', $dateFin);
        });

        // Tri par date (du plus récent au plus ancien par défaut)
        $sort = $request->input('sort', 'desc');
        $taux = $query->orderBy('date_taux', $sort)->paginate(15);

        return response()->json([
            'success' => true,
            'data'    => $taux,
        ], 200);
    }

}