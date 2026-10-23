<?php

namespace App\Http\Controllers\Dsa;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dsa\Concerns\ExigePermission;
use App\Models\Client;
use App\Models\Demande;
use App\Models\KycPiece;
use App\Services\KycService;
use App\Services\RegleKycService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KycController extends Controller
{
    use ExigePermission;

    public function __construct(
        private KycService $kyc,
        private RegleKycService $regles
    ) {}

    /**
     * POST /api/dsa/clients/{id}/kyc/ouvrir
     */
    public function ouvrir(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.kyc.soumettre');

        $client = Client::findOrFail($id);
        $data   = $request->validate(['local_uuid' => 'nullable|uuid']);

        $demande = $this->kyc->ouvrirDossier($client, $request->user(), $request->user()->agentDsa, $data['local_uuid'] ?? null);

        return response()->json([
            'success' => true,
            'demande' => $demande->load('kycDossier.pieces'),
        ], 201);
    }

    /**
     * GET /api/dsa/clients/{id}/kyc/exigences?partenaire_id=&produit_dsa_id=
     */
    public function exigences(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.clients.view');

        $client = Client::findOrFail($id);

        $data = $request->validate([
            'partenaire_id'  => 'required|integer|exists:partenaires,id',
            'produit_dsa_id' => 'nullable|integer|exists:produits_dsa,id',
        ]);

        $produitId = $data['produit_dsa_id'] ?? null;

        return response()->json([
            'success'    => true,
            'regles'     => $this->regles->reglesPour($data['partenaire_id'], $produitId)
                ->map(fn ($r) => $r->only(['type_document', 'obligatoire', 'validite_jours']))->values(),
            'evaluation' => $this->regles->evaluer($client, $data['partenaire_id'], $produitId),
        ]);
    }

    /**
     * POST /api/dsa/demandes/{id}/kyc/pieces (upload multipart)
     */
    public function ajouterPiece(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.kyc.soumettre');
        $demande = $this->demandeKyc($request, $id);

        $data = $request->validate([
            'type_document'   => ['required', Rule::in(KycPiece::TYPES)],
            'numero'          => 'nullable|string|max:60',
            'date_expiration' => 'nullable|date|after:today',
            'fichier'         => 'required|file|mimes:jpg,jpeg,png,pdf,doc,docx|max:10240',
        ]);

        return response()->json(
            ['success' => true, 'piece' => $this->kyc->ajouterPiece($demande, $data, $request->file('fichier'))],
            201
        );
    }

    /**
     * POST /api/dsa/demandes/{id}/kyc/signature
     */
    public function signature(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.kyc.soumettre');
        $demande = $this->demandeKyc($request, $id);

        $request->validate(['signature' => 'required|file|mimes:png,jpg,jpeg|max:2048']);

        $this->kyc->enregistrerSignature($demande, $request->file('signature'));

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/dsa/kyc/pieces/{id}/validation
     */
    public function validerPiece(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.kyc.valider');

        $piece = KycPiece::whereHas('dossier.demande', fn ($q) => $q->visiblePour($request->user()))
            ->findOrFail($id);

        $data = $request->validate([
            'statut' => 'required|in:VALIDE,REJETE',
            'motif'  => 'nullable|string|max:500',
        ]);

        return response()->json([
            'success' => true,
            'piece'   => $this->kyc->validerPiece($piece, $data['statut'], $data['motif'] ?? null, $request->user()),
        ]);
    }

    /**
     * GET /api/dsa/kyc/pieces/{id}/telecharger
     * Télécharge une pièce justificative (fichier privé).
     */
    public function telechargerPiece(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.kyc.soumettre');

        $piece = KycPiece::whereHas('dossier.demande', fn ($q) => $q->visiblePour($request->user()))
            ->findOrFail($id);

        $chemin = storage_path('app/private/' . $piece->fichier_ref);

        if (! file_exists($chemin)) {
            abort(404, 'Fichier introuvable');
        }

        $nomFichier = basename($piece->fichier_ref);

        return response()->download($chemin, $piece->type_document . '_' . $nomFichier);
    }

    private function demandeKyc(Request $request, int $id): Demande
    {
        return Demande::visiblePour($request->user())
            ->where('type', 'KYC')
            ->with('kycDossier')
            ->findOrFail($id);
    }
}