<?php

namespace App\Http\Controllers\TauxChange;

use App\Http\Controllers\Controller;
use App\Models\SuccursaleExchangeRate;
use App\Models\Succursale;
use App\Exceptions\BusinessException;
use Illuminate\Http\Request;
use App\Traits\Notifiable; // Ajout du trait

class SuccursaleExchangeRateController extends Controller
{
    use Notifiable; // Utilisation du trait

    public function index(Request $request)
    {
        $query = SuccursaleExchangeRate::with('succursale');

        if ($request->filled('succursale_id')) {
            $query->where('succursale_id', $request->succursale_id);
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
            'succursale_id'      => 'required|exists:succursales,id',
            'devise_source'      => 'required|string|max:10|exists:devise,code',
            'devise_destination' => 'required|string|max:10|exists:devise,code|different:devise_source',
            'taux_reference'     => 'required|numeric|min:0',
            'spread_achat'       => 'required|numeric|min:0',
            'spread_vente'       => 'required|numeric|min:0',
            'date_effet'         => 'required|date',
            'actif'              => 'sometimes|boolean',
            'motif'              => 'nullable|string|max:255',
        ]);

        // Vérifier que la succursale existe
        $succursale = Succursale::find($validated['succursale_id']);
        if (!$succursale) {
            return response()->json(['success' => false, 'message' => 'Succursale introuvable.'], 404);
        }

        // Récupérer l'ancien taux valide pour cette paire dans cette succursale
        $ancien = SuccursaleExchangeRate::where('succursale_id', $validated['succursale_id'])
            ->where('devise_source', $validated['devise_source'])
            ->where('devise_destination', $validated['devise_destination'])
            ->where('statut', 'VALIDE')
            ->orderBy('date_effet', 'desc')
            ->first();

        // Si un taux est actif, on le désactive (sauf si le nouveau est en attente)
        if (($validated['actif'] ?? false) && !isset($validated['statut'])) {
            SuccursaleExchangeRate::where('succursale_id', $validated['succursale_id'])
                ->where('devise_source', $validated['devise_source'])
                ->where('devise_destination', $validated['devise_destination'])
                ->where('actif', true)
                ->update(['actif' => false]);
        }

        $data = [
            'succursale_id'         => $validated['succursale_id'],
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

        $taux = SuccursaleExchangeRate::create($data);

        //  NOTIFICATION AUX SUPER_ADMIN
        $this->notifierChangementTauxSuccursale($taux, $succursale, $ancien);

        return response()->json([
            'success' => true,
            'message' => 'Taux succursale soumis pour validation.',
            'data'    => $taux->load(['modificateur', 'succursale']),
        ], 201);
    }

    /**
     *  NOUVELLE MÉTHODE : Notifier les SUPER_ADMIN d'une demande de changement de taux pour succursale
     */
    protected function notifierChangementTauxSuccursale($taux, $succursale, $ancien)
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
                "🏢 Demande de changement de taux pour la succursale\n".
                "Succursale: %s\n".
                "Code: %s\n".
                "Devise: %s → %s\n".
                "Ancien taux: %s\n".
                "Nouveau taux: %s\n".
                "Spread achat: %s\n".
                "Spread vente: %s\n".
                "Date d'effet: %s\n".
                "Motif: %s\n".
                "Demandeur: %s (%s)",
                $succursale->nom ?? 'Succursale inconnue',
                $succursale->code ?? 'N/A',
                $deviseSource,
                $deviseDestination,
                $ancienTaux,
                $nouveauTaux,
                $taux->spread_achat,
                $taux->spread_vente,
                $taux->date_effet->format('d/m/Y H:i'),
                $taux->motif ?? 'Non spécifié',
                $utilisateur->nom_complet ?? 'Utilisateur inconnu',
                $utilisateur->email ?? 'Email inconnu'
            );

            // 1. Notifier tous les SUPER_ADMIN (via le trait Notifiable)
            $this->notifierDestinatairesSpecifiques([
                'destinataires' => [
                    'type' => 'role',
                    'code' => 'SUPER_ADMIN'
                ],
                'type' => 'CHANGEMENT_TAUX_SUCCURSALE', // Nouveau type de notification
                'titre' => "💰 Demande de changement de taux - Succursale {$succursale->nom}",
                'message' => $message,
                'donnees' => [
                    'taux_id' => $taux->id,
                    'succursale_id' => $succursale->id,
                    'succursale_nom' => $succursale->nom,
                    'succursale_code' => $succursale->code,
                    'devise_source' => $deviseSource,
                    'devise_destination' => $deviseDestination,
                    'ancien_taux' => $ancien ? $ancien->taux_reference : null,
                    'ancien_taux_id' => $ancien ? $ancien->id : null,
                    'nouveau_taux' => $nouveauTaux,
                    'spread_achat' => $taux->spread_achat,
                    'spread_vente' => $taux->spread_vente,
                    'date_effet' => $taux->date_effet->toISOString(),
                    'motif' => $taux->motif,
                    'demandeur_id' => auth()->id(),
                    'demandeur_nom' => $utilisateur->nom_complet ?? 'Inconnu',
                    'demandeur_email' => $utilisateur->email ?? 'Inconnu',
                    'statut' => $taux->statut,
                    'created_at' => $taux->created_at->toISOString()
                ],
                'priorite' => 'HAUTE'
            ]);

            // 2. Notifier également le responsable de la succursale si différent des SUPER_ADMIN
            if ($succursale->responsable_id) {
                $responsable = \App\Models\Utilisateur::find($succursale->responsable_id);
                if ($responsable && $responsable->role && $responsable->role->code !== 'SUPER_ADMIN') {
                    $this->notifierDestinatairesSpecifiques([
                        'destinataires' => [
                            'type' => 'utilisateur',
                            'id' => $succursale->responsable_id
                        ],
                        'type' => 'CHANGEMENT_TAUX_SUCCURSALE',
                        'titre' => "💰 Changement de taux demandé pour votre succursale",
                        'message' => "Un changement de taux a été demandé pour la succursale {$succursale->nom}.\n\n" .
                                     "Devise: {$deviseSource} → {$deviseDestination}\n" .
                                     "Nouveau taux: {$nouveauTaux}\n" .
                                     "Demandeur: {$utilisateur->nom_complet}\n\n" .
                                     "Veuillez contacter un SUPER_ADMIN pour valider ou rejeter cette demande.",
                        'donnees' => [
                            'taux_id' => $taux->id,
                            'succursale_id' => $succursale->id,
                            'succursale_nom' => $succursale->nom,
                            'devise_source' => $deviseSource,
                            'devise_destination' => $deviseDestination,
                            'nouveau_taux' => $nouveauTaux,
                            'demandeur_nom' => $utilisateur->nom_complet ?? 'Inconnu'
                        ],
                        'priorite' => 'NORMALE'
                    ]);
                }
            }

            \Illuminate\Support\Facades\Log::info('Notification de changement de taux succursale envoyée', [
                'taux_id' => $taux->id,
                'succursale_id' => $succursale->id,
                'demandeur' => auth()->id(),
                'type' => 'SUPER_ADMIN'
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Erreur lors de la notification de changement de taux succursale', [
                'error' => $e->getMessage(),
                'taux_id' => $taux->id ?? null,
                'succursale_id' => $succursale->id ?? null
            ]);
        }
    }

    public function show($id)
    {
        $rate = SuccursaleExchangeRate::with('succursale')->findOrFail($id);
        return response()->json(['success' => true, 'data' => $rate]);
    }

    public function activate($id)
    {
        $rate = SuccursaleExchangeRate::findOrFail($id);
        SuccursaleExchangeRate::where('succursale_id', $rate->succursale_id)
            ->where('devise_source', $rate->devise_source)
            ->where('devise_destination', $rate->devise_destination)
            ->where('id', '!=', $rate->id)
            ->update(['actif' => false]);

        $rate->actif = true;
        $rate->save();

        return response()->json(['success' => true, 'message' => 'Taux succursale activé.', 'data' => $rate]);
    }

    public function valider($id, Request $request)
    {
        $taux = SuccursaleExchangeRate::findOrFail($id);
        if ($taux->statut !== 'EN_ATTENTE') {
            throw new BusinessException('Seul un taux en attente peut être validé.');
        }
        $taux->statut = 'VALIDE';
        $taux->valide_par = auth()->id();
        $taux->date_validation = now();
        $taux->notes_validation = $request->input('notes');
        $taux->save();

        // Désactiver l'ancien taux actif si nécessaire
        SuccursaleExchangeRate::where('succursale_id', $taux->succursale_id)
            ->where('devise_source', $taux->devise_source)
            ->where('devise_destination', $taux->devise_destination)
            ->where('id', '!=', $taux->id)
            ->where('actif', true)
            ->update(['actif' => false]);

        $taux->actif = true;
        $taux->save();

        $this->notifierValidationTaux($taux);

        return response()->json(['success' => true, 'message' => 'Taux validé et activé.']);
    }

    public function rejeter($id, Request $request)
    {
        $taux = SuccursaleExchangeRate::findOrFail($id);
        if ($taux->statut !== 'EN_ATTENTE') {
            throw new BusinessException('Seul un taux en attente peut être rejeté.');
        }
        $taux->statut = 'REJETE';
        $taux->valide_par = auth()->id();
        $taux->date_validation = now();
        $taux->notes_validation = $request->input('notes');
        $taux->save();

        $this->notifierRejetTaux($taux);

        return response()->json(['success' => true, 'message' => 'Taux rejeté.']);
    }

    /**
     *  Notifier le demandeur de la validation de son taux
     */
    protected function notifierValidationTaux($taux)
    {
        try {
            if ($taux->modifie_par) {
                $validateur = auth()->user();
                $this->notifierDestinatairesSpecifiques([
                    'destinataires' => [
                        'type' => 'utilisateur',
                        'id' => $taux->modifie_par
                    ],
                    'type' => 'TAUX_SUCCURSALE_VALIDE',
                    'titre' => "Taux de change validé - Succursale {$taux->succursale->nom}",
                    'message' => "Votre demande de changement de taux a été validée par {$validateur->nom_complet}.\n\n" .
                                 "Devise: {$taux->devise_source} → {$taux->devise_destination}\n" .
                                 "Nouveau taux: {$taux->taux_reference}\n" .
                                 "Date d'effet: {$taux->date_effet->format('d/m/Y H:i')}",
                    'donnees' => [
                        'taux_id' => $taux->id,
                        'succursale_id' => $taux->succursale_id,
                        'valide_par' => $validateur->id,
                        'valide_par_nom' => $validateur->nom_complet
                    ],
                    'priorite' => 'HAUTE'
                ]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Erreur lors de la notification de validation', [
                'error' => $e->getMessage(),
                'taux_id' => $taux->id
            ]);
        }
    }

    /**
     *  Notifier le demandeur du rejet de son taux
     */
    protected function notifierRejetTaux($taux)
    {
        try {
            if ($taux->modifie_par) {
                $validateur = auth()->user();
                $this->notifierDestinatairesSpecifiques([
                    'destinataires' => [
                        'type' => 'utilisateur',
                        'id' => $taux->modifie_par
                    ],
                    'type' => 'TAUX_SUCCURSALE_REJETE',
                    'titre' => "Taux de change rejeté - Succursale {$taux->succursale->nom}",
                    'message' => "Votre demande de changement de taux a été rejetée par {$validateur->nom_complet}.\n\n" .
                                 "Devise: {$taux->devise_source} → {$taux->devise_destination}\n" .
                                 "Motif du rejet: " . ($taux->notes_validation ?? 'Non spécifié'),
                    'donnees' => [
                        'taux_id' => $taux->id,
                        'succursale_id' => $taux->succursale_id,
                        'rejete_par' => $validateur->id,
                        'rejete_par_nom' => $validateur->nom_complet,
                        'motif_rejet' => $taux->notes_validation
                    ],
                    'priorite' => 'HAUTE'
                ]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Erreur lors de la notification de rejet', [
                'error' => $e->getMessage(),
                'taux_id' => $taux->id
            ]);
        }
    }
    

    public function revoke($id)
    {
        $taux = SuccursaleExchangeRate::findOrFail($id);
        if ($taux->statut === 'REVOQUE') {
            return response()->json(['success' => false, 'message' => 'Ce taux est déjà révoqué.'], 422);
        }

        $taux->actif = false;
        $taux->statut = 'REVOQUE';
        $taux->save();

        // Réactiver l'ancien taux (s'il existe)
        $ancienTaux = SuccursaleExchangeRate::where('succursale_id', $taux->succursale_id)
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

        return response()->json([
            'success' => true,
            'message' => 'Taux révoqué avec succès.',
            'data'    => $taux,
        ]);
    }
}