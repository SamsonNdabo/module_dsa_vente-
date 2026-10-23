<?php

namespace App\Services;

use App\Models\Client;              // ← CHANGÉ
use App\Models\ConsentementClient;
use App\Models\Demande;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConsentementService
{
    public function enregistrer(
        Client $client,
        string $type,
        string $version,
        string $texte,
        bool $consenti,
        ?Demande $demande,
        Request $request
    ): ConsentementClient {
        if (! in_array($type, ConsentementClient::TYPES, true)) {
            abort(422, "Type de consentement invalide : {$type}");
        }

        return DB::transaction(fn () => ConsentementClient::create([
            'client_id'          => $client->id,           // ← CHANGÉ
            'demande_id'         => $demande?->id,
            'type_consentement'  => $type,
            'version_formulaire' => $version,
            'texte_consentement' => $texte,
            'consenti'           => $consenti,
            'date_consentement'  => now(),
            'ip_address'         => $request->ip(),
            'user_agent'         => substr((string) $request->userAgent(), 0, 255),
        ]));
    }

    public function aConsenti(Client $client, string $type): bool
    {
        return ConsentementClient::where('client_id', $client->id)   // ← CHANGÉ
            ->where('type_consentement', $type)
            ->where('consenti', true)
            ->exists();
    }
}