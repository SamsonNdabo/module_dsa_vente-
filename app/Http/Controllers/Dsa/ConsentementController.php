<?php

namespace App\Http\Controllers\Dsa;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dsa\Concerns\ExigePermission;
use App\Models\Client;
use App\Models\Kyc\ConsentementClient;
use App\Models\Kyc\Demande;
use App\Services\Client_Kyc\ConsentementService;
use Illuminate\Http\Request;

class ConsentementController extends Controller
{
    use ExigePermission;

    public function __construct(private ConsentementService $service) {}

    public function index(Request $request, int $clientId)
    {
        $this->exiger($request, 'dsa.clients.view');

        // Vérifier que le client existe
        $client = Client::findOrFail($clientId);

        return response()->json([
            'success' => true,
            'data'    => ConsentementClient::where('client_id', $client->id)
                ->orderByDesc('date_consentement')
                ->get(),
        ]);
    }

    public function store(Request $request, int $clientId)
    {
        $this->exiger($request, 'dsa.kyc.soumettre');

        $client = Client::findOrFail($clientId);

        $data = $request->validate([
            'type_consentement'  => 'required|in:' . implode(',', ConsentementClient::TYPES),
            'version_formulaire' => 'required|string|max:20',
            'texte_consentement' => 'required|string',
            'consenti'           => 'required|boolean',
            'demande_id'         => 'nullable|integer|exists:demandes,id',
        ]);

        \Log::info('[CONSENTEMENT] store', [
            'clientId_recu' => $clientId,
            'client_trouve' => $client ? $client->id : null,
            'payload' => $request->all(),
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