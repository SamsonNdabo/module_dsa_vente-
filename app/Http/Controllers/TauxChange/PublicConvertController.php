<?php

namespace App\Http\Controllers\TauxChange;

use App\Http\Controllers\Controller;
use App\Models\Devise;
use App\Models\Succursale;
use App\Models\ExchangeRate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PublicConvertController extends Controller
{
    // Récupère la liste des villes disponibles (sans doublon, avec format correct)
    public function getVilles()
    {
        $villes = Succursale::where('statut', 'ACTIVE')
            ->select('ville')
            ->distinct()
            ->orderBy('ville')
            ->get()
            ->pluck('ville')
            ->map(function ($ville) {
                return ucfirst(strtolower(trim($ville)));
            })
            ->filter()
            ->values();

        return response()->json($villes);
    }


    /**
     * Récupère la liste des devises actives.
     */
    public function getDevises()
    {
        $devises = Devise::where('statut', 1)->get();
        return response()->json($devises);
    }

    /**
     * Convertisseur public.
     */
    public function convert(Request $request)
    {
        $request->validate([
            'ville'          => 'required|string',
            'devise_source'  => 'required|exists:devise,id',
            'devise_cible'   => 'required|exists:devise,id|different:devise_source',
            'montant'        => 'required|numeric|min:0',
        ]);

        // Normalisation de la ville
        $villeNormalisee = ucfirst(strtolower(trim($request->ville)));
        $succursale = Succursale::where('ville', $villeNormalisee)
            ->where('statut', 'ACTIVE')
            ->first();

        if (!$succursale) {
            return response()->json([
                'error'   => 'ville_non_trouvee',
                'message' => 'Ville non supportée. Veuillez choisir une ville de la liste.'
            ], 422);
        }

        $sourceId = (int) $request->devise_source;
        $cibleId  = (int) $request->devise_cible;

        $sourceCode = Devise::find($sourceId)?->code;
        $destCode   = Devise::find($cibleId)?->code;

        if (!$sourceCode || !$destCode) {
            return response()->json(['error' => 'Devise introuvable.'], 404);
        }

        $tauxData = ExchangeRate::getTauxActif($sourceCode, $destCode, null, $succursale->id);

        if (!$tauxData) {
            return response()->json([
                'error'   => 'taux_indisponible',
                'message' => "Aucun taux de référence trouvé pour la paire {$sourceCode}/{$destCode}."
            ], 422);
        }

        $exchangeRate = $tauxData['rate'];
        $sensTaux = $tauxData['sens'];

        $tauxAchat = $exchangeRate->getTauxAchat($sensTaux);
        $tauxVente = $exchangeRate->getTauxVente($sensTaux);

        // Taux de référence (pour affichage)
        $tauxReference = (float) $exchangeRate->taux_reference;

        $montantSource = (float) $request->montant;
        $cdfCode = 'CDF';

        // Logique métier 
        if ($sourceCode === $cdfCode) {

            $tauxApplique = $tauxVente;
            $montantRecu = round($montantSource / $tauxApplique, 4);

        } else {

            $tauxApplique = $tauxAchat;
            $montantRecu = round($montantSource * $tauxApplique, 4);

        }

        $deviseSource = Devise::find($sourceId);
        $deviseCible  = Devise::find($cibleId);

        return response()->json([
            'success' => true,
            'data' => [
                'montant_source'                => $montantSource,
                'montant_recu'                  => $montantRecu,
                'taux'                          => $tauxApplique,
                'sens_de_la_conversion'         => $sensTaux,
                'devise_source_code'            => $deviseSource->code,
                'devise_cible_code'             => $deviseCible->code,
            ],
        ]);
    }

}