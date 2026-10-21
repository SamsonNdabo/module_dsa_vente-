<?php

namespace App\Http\Controllers;

use App\Models\Paiement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Services\EcritureService;

class PaiementController extends Controller
{
    protected $ecritureService;

    public function __construct(EcritureService $ecritureService)
    {
        $this->ecritureService = $ecritureService;
    }
    /**
     * Liste des paiements fournisseurs
     */
    public function index()
    {
        $paiements = Paiement::with([
            'commandeFournisseur',
            'reception',
            'utilisateur'
        ])
        ->whereNotNull('commande_fournisseur_id')
        ->latest()
        ->paginate(20);

        return response()->json($paiements);
    }

    /**
     * Enregistrer un paiement fournisseur
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [

            'commande_fournisseur_id'        => 'required|exists:commandes_fournisseurs,id',

            'mode_paiement'      => 'required|string|max:50',

            'montant'            => 'required|numeric|min:0.01',

            'date_paiement'      => 'nullable|date',

            'reference_externe'  => 'nullable|string|max:255',

            'details_paiement'   => 'nullable|array',

            'notes'              => 'nullable|string'

        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {

            $paiement = Paiement::create([

                'reference'          => Paiement::genererReference(),

                'operation_libelle'  => 'PAIEMENT_COMMANDE_FOURNISSEUR',

                'commande_fournisseur_id'        => $request->commande_fournisseur_id,

                // sera renseigné lors de la réception
                'reception_id'       => null,

                'utilisateur_id'     => auth()->id(),

                'mode_paiement'      => $request->mode_paiement,

                'montant'            => $request->montant,

                'date_paiement'      => $request->date_paiement ?? now(),

                'statut'             => 'VALIDE',

                'reference_externe'  => $request->reference_externe,

                'details_paiement'   => $request->details_paiement,

                'notes'              => $request->notes

            ]);
            
            $commande = $paiement->commandeFournisseur;

            $commande->recalculerPaiement();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Paiement enregistré avec succès.',
                'data'    => $paiement->load([
                    'commandeFournisseur',
                    'utilisateur'
                ])
            ], 201);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);

        }
    }

    /**
     * Détails d'un paiement
     */
    public function show($id)
    {
        $paiement = Paiement::with([
            'commandeFournisseur',
            'reception',
            'utilisateur'
        ])->findOrFail($id);

        return response()->json($paiement);
    }

    /**
     * Modifier un paiement
     */
    public function update(Request $request, $id)
    {
        $paiement = Paiement::findOrFail($id);

        $validator = Validator::make($request->all(), [

            'mode_paiement'     => 'sometimes|string|max:50',

            'montant'           => 'sometimes|numeric|min:0.01',

            'date_paiement'     => 'nullable|date',

            'reference_externe' => 'nullable|string|max:255',

            'details_paiement'  => 'nullable|array',

            'notes'             => 'nullable|string',

            // sera utilisé après création de la réception
            'reception_id'      => 'nullable|exists:receptions_fournisseurs,id',

            'statut'            => 'nullable|string|max:30'

        ]);

        if ($validator->fails()) {

            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);

        }

        $paiement->update($request->only([
            'mode_paiement',
            'montant',
            'date_paiement',
            'reference_externe',
            'details_paiement',
            'notes',
            'reception_id',
            'statut'
        ]));
        
        if ($paiement->commandeFournisseur) {
        $paiement->commandeFournisseur->recalculerPaiement();
    }

        return response()->json([
            'success' => true,
            'message' => 'Paiement modifié avec succès.',
            'data' => $paiement->fresh()->load([
                'commande',
                'reception',
                'utilisateur'
            ])
        ]);
    }

    /**
     * Supprimer un paiement
     */
    public function destroy($id)
    {
        $paiement = Paiement::findOrFail($id);

        $paiement->delete();
        $commande = $paiement->commandeFournisseur;

        $paiement->delete();
        
        if ($commande) {
            $commande->recalculerPaiement();
        }

        return response()->json([
            'success' => true,
            'message' => 'Paiement supprimé avec succès.'
        ]);
    }
}