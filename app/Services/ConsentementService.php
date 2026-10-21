<?php

namespace App\Services;

use App\Models\ClientDsa;
use App\Models\ConsentementClient;
use App\Models\Demande;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConsentementService
{
    /**
     * Enregistre un consentement client DSA.
     */
    public function enregistrer(
        ClientDsa $client,
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
            'client_dsa_id'      => $client->id,           // ← CORRIGÉ
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

    /**
     * Vérifie qu'un client DSA a bien consenti à un type donné.
     */
    public function aConsenti(ClientDsa $client, string $type): bool
    {
        return ConsentementClient::where('client_dsa_id', $client->id)   // ← CORRIGÉ
            ->where('type_consentement', $type)
            ->where('consenti', true)
            ->exists();
    }
}