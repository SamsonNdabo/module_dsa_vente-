<?php

namespace App\Http\Controllers\Dsa;

use App\DTO\SouscriptionRequest;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dsa\Concerns\ExigePermission;
use App\Http\Requests\Dsa\SouscriptionClientRequest;
use App\Models\Partenaire\PartenaireProduit;
use App\Models\Produit;
use App\Models\ProduitEpargne;
use App\Services\Souscription\SouscriptionService;
use Illuminate\Http\Request;

class SouscriptionController extends Controller
{
    use ExigePermission;

    public function __construct(private SouscriptionService $service) {}

    /**
     * POST /api/dsa/souscriptions
     */
    public function store(SouscriptionClientRequest $request)
    {
        $this->exiger($request, 'dsa.souscriptions.creer');

        $dto = SouscriptionRequest::fromArray($request->validated());
        $resultat = $this->service->souscrire($dto, $request->user());

        return response()->json([
            'success' => true,
            'data'    => $resultat,
        ], 201);
    }

    /**
     * POST /api/dsa/souscriptions/{id}/finaliser
     */
    public function finaliser(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.souscriptions.creer');

        $data = $request->validate([
            'mode_paiement' => 'nullable|string|in:ESPECES,MOBILE_MONEY,VIREMENT',
            'montant'       => 'nullable|numeric|min:0',
            'notes'         => 'nullable|string|max:500',
        ]);

        try {
            $resultat = $this->service->finaliserVente(
                factureId:    $id,
                modePaiement: $data['mode_paiement'] ?? 'ESPECES',
                montant:      $data['montant'] ?? null,
                notes:        $data['notes'] ?? null,
                auteur:       $request->user(),
            );

            return response()->json([
                'success' => true,
                'data'    => $resultat,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Log::error('[SOUSCRIPTION] Erreur finalisation', [
                'facture_id' => $id,
                'error'      => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la finalisation : ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/dsa/clients/{clientId}/souscriptions-eligibles
     */
    public function eligibles(Request $request, int $clientId)
    {
        $this->exiger($request, 'dsa.souscriptions.view');

        $client = \App\Models\Client::findOrFail($clientId);
        $kycValide = $client->kyc_statut === 'VALIDE';

        return response()->json([
            'success'    => true,
            'kyc_valide' => $kycValide,
            'kyc_statut' => $client->kyc_statut,
            'client'     => [
                'id'          => $client->id,
                'nom_complet' => $client->nom_complet,
                'code_client' => $client->code_client,
                'kyc_statut'  => $client->kyc_statut,
            ],
            'eligible'   => $kycValide,
            'message'    => $kycValide
                ? 'Le client est éligible aux souscriptions.'
                : 'Le KYC du client doit être validé avant toute souscription.',
        ]);
    }

    /**
     * GET /api/dsa/souscriptions/produits?type=STOCK|EPARGNE&partenaire_id=X
     */
    public function produits(Request $request)
    {
        $this->exiger($request, 'dsa.souscriptions.view');

        $data = $request->validate([
            'type'          => 'required|in:STOCK,EPARGNE',
            'partenaire_id' => 'nullable|integer|exists:partenaires,id',
        ]);

        $type         = $data['type'];
        $partenaireId = $data['partenaire_id'] ?? null;

        if ($type === 'STOCK') {
            $produits = Produit::where('actif', 1)
                ->with('devise:id,code')
                ->get()
                ->map(function ($p) {
                    $lien = PartenaireProduit::where('type_produit', 'STOCK')
                        ->where('id_produit', $p->id)
                        ->where('actif', 1)
                        ->first();

                    return [
                        'id'               => $p->id,
                        'nom'              => $p->nom,
                        'code'             => $p->code_barre ?? $p->code ?? null,
                        'prix_vente'       => $p->prix_vente,
                        'prix_vente_TTC'   => $p->prix_vente_TTC,
                        'devise'           => $p->devise,
                        'stock_disponible' => $p->stock_total ?? null,
                        'est_partenaire'   => $lien !== null,
                        'partenaire_id'    => $lien?->id_partenaire,
                    ];
                });

            if ($partenaireId) {
                $produits = $produits->filter(function ($p) use ($partenaireId) {
                    return $p['partenaire_id'] === null || $p['partenaire_id'] === (int) $partenaireId;
                })->values();
            }
        } else {
            $produits = ProduitEpargne::where('actif', 1)
                ->with('devise:id,code')
                ->get()
                ->map(function ($p) {
                    $lien = PartenaireProduit::where('type_produit', 'EPARGNE')
                        ->where('id_produit', $p->id)
                        ->where('actif', 1)
                        ->first();

                    return [
                        'id'             => $p->id,
                        'nom'            => $p->nom,
                        'code'           => $p->code,
                        'solde_minimum'  => $p->solde_minimum,
                        'devise'         => $p->devise,
                        'est_partenaire' => $lien !== null,
                        'partenaire_id'  => $lien?->id_partenaire,
                    ];
                });

            if ($partenaireId) {
                $produits = $produits->filter(function ($p) use ($partenaireId) {
                    return $p['partenaire_id'] === null || $p['partenaire_id'] === (int) $partenaireId;
                })->values();
            }
        }

        return response()->json([
            'success' => true,
            'data'    => $produits,
        ]);
    }
}