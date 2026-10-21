<?php

namespace App\Http\Controllers\Commission;

use App\Http\Controllers\Controller;
use App\Models\CommissionConfigSuccursale;
use App\Models\CommissionConfig;
use Illuminate\Http\Request;

class CommissionConfigSuccursaleController extends Controller
{
    // Liste des configs par succursale
        public function index(Request $request)
    {
        $user = auth()->user();
    
    
        if ($user->isSuperAdmin() || $user->isChefOperation()||$user->isAuditeur()) {
    
            $validated = $request->validate([
                'succursale_id' => 'required|integer|exists:succursale,id',
            ]);
    
            $succursaleId = $validated['succursale_id'];
    
        } elseif ($user->isChefAgence()) {
    
            if (!$user->succursale_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune succursale associée'
                ], 403);
            }
    
            $succursaleId = $user->succursale_id;
    
        } else {
    
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé.'
            ], 403);
    
        }
    
    
        // Récupérer toutes les configs globales actives
        $configsGlobales = CommissionConfig::actif()->get();
    
    
        $rapport = $configsGlobales->map(function ($config) use ($succursaleId) {
    
            $configSuccursale = CommissionConfigSuccursale::getValeurPourSuccursale(
                $succursaleId,
                $config->id
            );
    
            return [
                'commission_config_id' => $config->id,
                'service'              => $config->service,
                'type_transaction'     => $config->type_transaction,
                'type_calcul'          => $config->type_calcul,
                'valeur_globale'       => $config->valeur,
                'valeur_succursale'    => $configSuccursale?->valeur ?? null,
                'valeur_effective'     => $configSuccursale?->valeur ?? $config->valeur,
                'source'               => $configSuccursale ? 'succursale' : 'global',
                'statut'               => $configSuccursale?->statut ?? null,
            ];
        });
    
    
        return response()->json([
            'success' => true,
            'message' => 'Configs de commission pour la succursale',
            'data'    => $rapport,
        ], 200);
    }

    // Définir ou modifier une config pour une succursale
    public function store(Request $request)
    {
        $validated = $request->validate([
            'succursale_id'        => 'required|integer|exists:succursales,id',
            'commission_config_id' => 'required|integer|exists:commission_config,id',
            'valeur'               => 'required|numeric|min:0',
            'modifie_par'          => 'required|integer|exists:utilisateurs,id',
            'motif'                => 'required|string|max:255',
        ]);

        // Vérifier que la valeur succursale est <= valeur globale
        $configGlobale = CommissionConfig::find($validated['commission_config_id']);

        if ((float) $validated['valeur'] > (float) $configGlobale->valeur) {
            return response()->json([
                'success' => false,
                'message' => 'La valeur succursale ne peut pas dépasser la valeur globale (' . $configGlobale->valeur . ')',
                'data'    => null,
            ], 422);
        }

        $config = CommissionConfigSuccursale::updateOrCreate(
            [
                'succursale_id'        => $validated['succursale_id'],
                'commission_config_id' => $validated['commission_config_id'],
            ],
            [
                'valeur'      => $validated['valeur'],
                'statut'      => 'ACTIF',
                'modifie_par' => $validated['modifie_par'],
                'motif'       => $validated['motif'],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Config de commission succursale définie avec succès',
            'data'    => $config->load(['succursale', 'commissionConfig']),
        ], 201);
    }

    // Désactiver une config succursale → retour au global
    public function desactiver(Request $request, $id)
    {
        if (!ctype_digit(\strval($id))) {
            return response()->json([
                'success' => false,
                'message' => 'ID invalide',
                'data'    => null,
            ], 400);
        }

        $config = CommissionConfigSuccursale::find($id);

        if (!$config) {
            return response()->json([
                'success' => false,
                'message' => 'Config introuvable',
                'data'    => null,
            ], 404);
        }

        $config->statut = 'INACTIF';
        $config->save();

        return response()->json([
            'success' => true,
            'message' => 'Config désactivée — la succursale utilisera la valeur globale',
            'data'    => $config->fresh(),
        ], 200);
    }
    
    
    // Liste toute les succursales avec commission particulière
    public function listeSuccursalesAvecConfig(Request $request)
    {
        $request->validate([
            'statut' => 'nullable|in:ACTIF,INACTIF',
        ]);

        $query = CommissionConfigSuccursale::with(['succursale', 'commissionConfig', 'modificateur'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }

        $configsSuccursales = $query->get();

        $resultat = $configsSuccursales->map(function ($item) {
            return [
                'id'                     => $item->id,
                'succursale_id'          => $item->succursale_id,
                'succursale_nom'         => $item->succursale->nom,
                'succursale_code'        => $item->succursale->code,
                'commission_config_id'   => $item->commission_config_id,
                'commission_config_nom'  => $item->commissionConfig->nom ?? $item->commissionConfig->service,
                'service'                => $item->commissionConfig->service,
                'type_transaction'       => $item->commissionConfig->type_transaction,
                'type_calcul'            => $item->commissionConfig->type_calcul,
                'valeur'                 => (float) $item->valeur,
                'statut'                 => $item->statut,
                'modifie_par'            => $item->modifie_par,
                'modificateur_nom'       => $item->modificateur->nom_complet ?? 'Utilisateur introuvable',
                'motif'                  => $item->motif,
                'created_at'             => $item->created_at,
                'updated_at'             => $item->updated_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $resultat,
        ]);
    }
}