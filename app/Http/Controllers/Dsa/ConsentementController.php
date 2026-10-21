<?php

namespace App\Http\Controllers\Dsa;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dsa\Concerns\ExigePermission;
use App\Models\ClientDsa;
use App\Models\ConsentementClient;
use App\Models\Demande;
use App\Services\ConsentementService;
use Illuminate\Http\Request;

class ConsentementController extends Controller
{
    use ExigePermission;

    public function __construct(private ConsentementService $service) {}

    public function index(Request $request, int $clientId)
    {
        $this->exiger($request, 'dsa.clients.view');

        return response()->json([
            'success' => true,
            'data'    => ConsentementClient::where('client_dsa_id', $clientId)   // ← CORRIGÉ
                ->orderByDesc('date_consentement')
                ->get(),
        ]);
    }

    public function store(Request $request, int $clientId)
    {
        $this->exiger($request, 'dsa.kyc.soumettre');

        $client = ClientDsa::findOrFail($clientId);   // ← CORRIGÉ

        $data = $request->validate([
            'type_consentement'  => 'required|in:' . implode(',', ConsentementClient::TYPES),
            'version_formulaire' => 'required|string|max:20',
            'texte_consentement' => 'required|string',
            'consenti'           => 'required|boolean',
            'demande_id'         => 'nullable|integer|exists:demandes,id',
        ]);

        $demande = ! empty($data['demande_id']) ? Demande::find($data['demande_id']) : null;

        $consentement = $this->service->enregistrer(
            $client,
            $data['type_consentement'],
            $data['version_formulaire'],
            $data['texte_consentement'],
            (bool) $data['consenti'],
            $demande,
            $request
        );

        return response()->json(['success' => true, 'data' => $consentement], 201);
    }
}