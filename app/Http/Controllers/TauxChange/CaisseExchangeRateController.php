<?php

namespace App\Http\Controllers\TauxChange;

use App\Http\Controllers\Controller;
use App\Models\CaisseExchangeRate;
use App\Models\Caisses;
use App\Exceptions\BusinessException;
use Illuminate\Http\Request;
use App\Traits\Notifiable; 

class CaisseExchangeRateController extends Controller
{
    use Notifiable; 

    public function index(Request $request)
    {
        $query = CaisseExchangeRate::with('caisse');

        if ($request->filled('caisse_id')) {
            $query->where('caisse_id', $request->caisse_id);
        }
        if ($request->filled('devise_source')) {
            $query->where('devise_source', $request->devise_source);
        }
        if ($request->filled('devise_destination')) {
            $query->where('devise_destination', $request->devise_destination);
        }
        if ($request->has('actif')) {
            $query->where('actif', $request->boolean('actif'));
        }

        $rates = $query->orderBy('date_effet', 'desc')->paginate(20);
        return response()->json(['success' => true, 'data' => $rates]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'caisse_id'          => 'required|exists:caisses,id',
            'devise_source'      => 'required|string|max:10|exists:devise,code',
            'devise_destination' => 'required|string|max:10|exists:devise,code|different:devise_source',
            'taux_reference'     => 'required|numeric|min:0',
            'spread_achat'       => 'required|numeric|min:0',
            'spread_vente'       => 'required|numeric|min:0',
            'date_effet'         => 'required|date',
            'actif'              => 'sometimes|boolean',
            'motif'              => 'nullable|string|max:255',
        ]);

        // Vérifier que la caisse existe
        $caisse = Caisses::find($validated['caisse_id']);
        if (!$caisse) {
            return response()->json(['success' => false, 'message' => 'Caisse introuvable.'], 404);
        }

        // Récupérer l'ancien taux valide pour cette paire dans cette caisse
        $ancien = CaisseExchangeRate::where('caisse_id', $validated['caisse_id'])
            ->where('devise_source', $validated['devise_source'])
            ->where('devise_destination', $validated['devise_destination'])
            ->where('statut', 'VALIDE')
            ->orderBy('date_effet', 'desc')
            ->first();

        // Si un taux est actif, on le désactive (sauf si le nouveau est en attente)
        if (($validated['actif'] ?? false) && !isset($validated['statut'])) {
            CaisseExchangeRate::where('caisse_id', $validated['caisse_id'])
                ->where('devise_source', $validated['devise_source'])
                ->where('devise_destination', $validated['devise_destination'])
                ->where('actif', true)
                ->update(['actif' => false]);
        }

        $data = [
            'caisse_id'             => $validated['caisse_id'],
            'devise_source'         => $validated['devise_source'],
            'devise_destination'    => $validated['devise_destination'],
            'taux_reference'        => $validated['taux_reference'],
            'spread_achat'          => $validated['spread_achat'],
            'spread_vente'          => $validated['spread_vente'],
            'date_effet'            => $validated['date_effet'],
            'actif'                 => false, // ne pas activer tant que non validé
            'modifie_par'           => auth()->id(),
            'ancien_taux_reference' => $ancien ? $ancien->taux_reference : null,
            'motif'                 => $validated['motif'] ?? null,
            'statut'                => 'EN_ATTENTE',
            // valide_par, date_validation seront remplis lors de la validation
        ];

        $taux = CaisseExchangeRate::create($data);

        $this->notifierChangementTaux($taux, $caisse, $ancien);

        return response()->json([
            'success' => true,
            'message' => 'Taux caisse soumis pour validation.',
            'data'    => $taux->load(['modificateur', 'caisse']),
        ], 201);
    }

    /**
     *  NOUVELLE MÉTHODE : Notifier les chefs d'agence d'une demande de changement de taux
     */
    protected function notifierChangementTaux($taux, $caisse, $ancien)
    {
        try {
            // Récupérer l'utilisateur qui a fait la demande
            $utilisateur = auth()->user();
            
            // Construire le message
            $ancienTaux = $ancien ? $ancien->taux_reference : 'Nouveau taux (aucun ancien)';
            $nouveauTaux = $taux->taux_reference;
            $deviseSource = $taux->devise_source;
            $deviseDestination = $taux->devise_destination;
            
            $message = sprintf(
                "Demande de changement de taux de change\n".
                "Caisse: %s\n".
                "Devise: %s → %s\n".
                "Ancien taux: %s\n".
                "Nouveau taux: %s\n".
                "Spread achat: %s\n".
                "Spread vente: %s\n".
                "Motif: %s\n".
                "Demandeur: %s",
                $caisse->nom ?? 'Caisse inconnue',
                $deviseSource,
                $deviseDestination,
                $ancienTaux,
                $nouveauTaux,
                $taux->spread_achat,
                $taux->spread_vente,
                $taux->motif ?? 'Non spécifié',
                $utilisateur->nom_complet ?? 'Utilisateur inconnu'
            );

            // 1. Notifier tous les chefs d'agence (via le trait Notifiable)
            $this->notifierDestinatairesSpecifiques([
                'destinataires' => [
                    'type' => 'role',
                    'code' => 'CHEF_AGENCE'
                ],
                'type' => 'CHANGEMENT_TAUX_CAISSE', // Nouveau type de notification
                'titre' => "💰 Demande de changement de taux - Caisse {$caisse->nom}",
                'message' => $message,
                'donnees' => [
                    'taux_id' => $taux->id,
                    'caisse_id' => $caisse->id,
                    'caisse_nom' => $caisse->nom,
                    'devise_source' => $deviseSource,
                    'devise_destination' => $deviseDestination,
                    'ancien_taux' => $ancien ? $ancien->taux_reference : null,
                    'nouveau_taux' => $nouveauTaux,
                    'spread_achat' => $taux->spread_achat,
                    'spread_vente' => $taux->spread_vente,
                    'date_effet' => $taux->date_effet,
                    'motif' => $taux->motif,
                    'demandeur_id' => auth()->id(),
                    'demandeur_nom' => $utilisateur->nom_complet ?? 'Inconnu'
                ],
                'priorite' => 'HAUTE'
            ]);

            // 2. Notifier également le responsable de la caisse si différent des chefs d'agence
            if ($caisse->responsable_id) {
                $this->notifierDestinatairesSpecifiques([
                    'destinataires' => [
                        'type' => 'utilisateur',
                        'id' => $caisse->responsable_id
                    ],
                    'type' => 'CHANGEMENT_TAUX_CAISSE',
                    'titre' => "💰 Changement de taux demandé pour votre caisse",
                    'message' => "Un changement de taux a été demandé pour la caisse {$caisse->nom}. Veuillez valider ou rejeter cette demande.",
                    'donnees' => [
                        'taux_id' => $taux->id,
                        'caisse_id' => $caisse->id,
                        'caisse_nom' => $caisse->nom,
                        'devise_source' => $deviseSource,
                        'devise_destination' => $deviseDestination,
                        'nouveau_taux' => $nouveauTaux,
                        'demandeur_nom' => $utilisateur->nom_complet ?? 'Inconnu'
                    ],
                    'priorite' => 'HAUTE'
                ]);
            }

            \Illuminate\Support\Facades\Log::info('Notification de changement de taux envoyée', [
                'taux_id' => $taux->id,
                'caisse_id' => $caisse->id,
                'demandeur' => auth()->id()
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Erreur lors de la notification de changement de taux', [
                'error' => $e->getMessage(),
                'taux_id' => $taux->id ?? null
            ]);
        }
    }

    public function show($id)
    {
        $rate = CaisseExchangeRate::with('caisse')->findOrFail($id);
        return response()->json(['success' => true, 'data' => $rate]);
    }
/*
    public function update(Request $request, $id)
    {
        $rate = CaisseExchangeRate::findOrFail($id);

        $validated = $request->validate([
            'taux_reference' => 'sometimes|numeric|min:0',
            'spread_achat'   => 'sometimes|numeric|min:0',
            'spread_vente'   => 'sometimes|numeric|min:0',
            'date_effet'     => 'sometimes|date',
            'actif'          => 'sometimes|boolean',
        ]);

        if (($validated['actif'] ?? false) && !$rate->actif) {
            CaisseExchangeRate::where('caisse_id', $rate->caisse_id)
                ->where('devise_source', $rate->devise_source)
                ->where('devise_destination', $rate->devise_destination)
                ->where('id', '!=', $rate->id)
                ->where('actif', true)
                ->update(['actif' => false]);
        }

        $rate->update($validated);
        return response()->json(['success' => true, 'message' => 'Taux caisse mis à jour.', 'data' => $rate]);
    }

    public function destroy($id)
    {
        $rate = CaisseExchangeRate::findOrFail($id);
        $rate->delete();
        return response()->json(['success' => true, 'message' => 'Taux caisse supprimé.']);
    }
*/
    public function activate($id)
    {
        $rate = CaisseExchangeRate::findOrFail($id);
        CaisseExchangeRate::where('caisse_id', $rate->caisse_id)
            ->where('devise_source', $rate->devise_source)
            ->where('devise_destination', $rate->devise_destination)
            ->where('id', '!=', $rate->id)
            ->update(['actif' => false]);

        $rate->actif = true;
        $rate->save();

        return response()->json(['success' => true, 'message' => 'Taux caisse activé.', 'data' => $rate]);
    }

    public function valider($id, Request $request)
    {
        $taux = CaisseExchangeRate::findOrFail($id);
        if ($taux->statut !== 'EN_ATTENTE') {
            throw new BusinessException('Seul un taux en attente peut être validé.');
        }
        $taux->statut = 'VALIDE';
        $taux->valide_par = auth()->id();
        $taux->date_validation = now();
        $taux->notes_validation = $request->input('notes');
        $taux->save();

        // Désactiver l'ancien taux actif si nécessaire
        CaisseExchangeRate::where('caisse_id', $taux->caisse_id)
            ->where('devise_source', $taux->devise_source)
            ->where('devise_destination', $taux->devise_destination)
            ->where('id', '!=', $taux->id)
            ->where('actif', true)
            ->update(['actif' => false]);

        $taux->actif = true;
        $taux->save();

        return response()->json(['success' => true, 'message' => 'Taux validé et activé.']);
    }

    public function rejeter($id, Request $request)
    {
        $taux = CaisseExchangeRate::findOrFail($id);
        if ($taux->statut !== 'EN_ATTENTE') {
            throw new BusinessException('Seul un taux en attente peut être rejeté.');
        }
        $taux->statut = 'REJETE';
        $taux->valide_par = auth()->id();
        $taux->date_validation = now();
        $taux->notes_validation = $request->input('notes');
        $taux->save();

        return response()->json(['success' => true, 'message' => 'Taux rejeté.']);
    }
    

    public function revoke($id)
    {
        $taux = CaisseExchangeRate::findOrFail($id);
        if ($taux->statut === 'REVOQUE') {
            return response()->json(['success' => false, 'message' => 'Ce taux est déjà révoqué.'], 422);
        }

        // Désactiver le taux
        $taux->actif = false;
        $taux->statut = 'REVOQUE';
        $taux->save();

        // Optionnel : réactiver le taux précédent (s'il existe)
        $ancienTaux = CaisseExchangeRate::where('caisse_id', $taux->caisse_id)
            ->where('devise_source', $taux->devise_source)
            ->where('devise_destination', $taux->devise_destination)
            ->where('id', '!=', $taux->id)
            ->where('statut', 'VALIDE')
            ->orderBy('date_effet', 'desc')
            ->first();

        if ($ancienTaux) {
            $ancienTaux->actif = true;
            $ancienTaux->save();
        }
        // Si aucun ancien taux, le système utilisera le taux de la succursale ou le global (cascade)

        return response()->json([
            'success' => true,
            'message' => 'Taux révoqué avec succès.',
            'data'    => $taux,
        ]);
    }
}