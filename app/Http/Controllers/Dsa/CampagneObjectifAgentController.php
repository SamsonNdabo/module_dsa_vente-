<?php

namespace App\Http\Controllers\Dsa;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dsa\Concerns\ExigePermission;
use App\Models\AgentAffectation;
use App\Models\Campagne;
use App\Models\CampagneObjectif;
use App\Models\ObjectifMesurable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CampagneObjectifAgentController extends Controller
{
    use ExigePermission;

    /** Statuts de campagne autorisant l'attribution d'objectifs aux agents. */
    private const STATUTS_ATTRIBUTION = ['PLANIFIEE', 'ACTIVE'];

    /**
     * GET /api/dsa/campagnes/{id}/objectifs-agents
     * Retourne la matrice : objectifs × agents affectés + attributions existantes.
     */
    public function matrice(Request $request, int $campagneId)
    {
        $this->exiger($request, 'dsa.campagnes.view');

        $campagne = Campagne::with([
            'partenaire:id,nom',
            'devise:id,code',
            'objectifsTypes.produit:id,code,nom',
        ])->findOrFail($campagneId);

        // Agents affectés à cette campagne (affectations en cours)
        $agents = AgentAffectation::with('agent:id,code,prenom,nom')
            ->where('campagne_id', $campagne->id)
            ->whereNull('date_fin')
            ->get()
            ->pluck('agent')
            ->filter()
            ->values()
            ->map(fn ($a) => [
                'id'          => $a->id,
                'code'        => $a->code,
                'nom_complet' => trim("{$a->prenom} {$a->nom}"),
            ]);

        // Objectifs (types) de la campagne
        $objectifs = $campagne->objectifsTypes->map(fn ($o) => [
            'id'              => $o->id,
            'type_objectif'   => $o->type_objectif,
            'libelle'         => $o->libelleType(),
            'valeur_cible'    => (float) $o->valeur_cible,
            'valeur_realisee' => (float) $o->valeur_realisee,
            'unite'           => $o->unite,
            'produit'         => $o->produit ? [
                'id'   => $o->produit->id,
                'code' => $o->produit->code,
                'nom'  => $o->produit->nom,
            ] : null,
        ]);

        // Attributions existantes (objectifs_mesurables de la campagne)
        $attributions = ObjectifMesurable::where('campagne_id', $campagne->id)
            ->whereNotNull('campagne_objectif_id')
            ->whereNotNull('agent_dsa_id')
            ->get()
            ->map(fn ($o) => [
                'id'                   => $o->id,
                'campagne_objectif_id' => $o->campagne_objectif_id,
                'agent_dsa_id'         => $o->agent_dsa_id,
                'valeur_cible'         => (float) $o->valeur_cible,
                'valeur_realisee'      => (float) $o->valeur_realisee,
                'statut'               => $o->statut,
            ]);

        // ⚠️ Flag d'autorisation basé sur le statut campagne
        $attributionPossible = in_array($campagne->statut, self::STATUTS_ATTRIBUTION, true);

        return response()->json([
            'success' => true,
            'data'    => [
                'campagne'     => [
                    'id'                    => $campagne->id,
                    'code'                  => $campagne->code,
                    'nom'                   => $campagne->nom,
                    'budget'                => $campagne->budget,
                    'devise'                => $campagne->devise ? $campagne->devise->code : null,
                    'statut'                => $campagne->statut,
                    'attribution_possible'  => $attributionPossible,
                    'partenaire'            => $campagne->partenaire ? [
                        'id'  => $campagne->partenaire->id,
                        'nom' => $campagne->partenaire->nom,
                    ] : null,
                ],
                'objectifs'    => $objectifs,
                'agents'       => $agents,
                'attributions' => $attributions,
            ],
        ]);
    }

    /**
     * POST /api/dsa/campagnes/{id}/attribuer-objectifs
     * Body : { attributions: [{campagne_objectif_id, agent_dsa_id, valeur_cible}, ...] }
     */
    public function attribuer(Request $request, int $campagneId)
    {
        $this->exiger($request, 'dsa.campagnes.gerer');

        $campagne = Campagne::findOrFail($campagneId);

        // ⚠️ Contrôle statut campagne
        if (! in_array($campagne->statut, self::STATUTS_ATTRIBUTION, true)) {
            throw ValidationException::withMessages([
                'statut' => "Impossible de modifier les attributions d'une campagne en statut {$campagne->statut}. Seules les campagnes PLANIFIEE ou ACTIVE sont modifiables.",
            ]);
        }

        $data = $request->validate([
            'attributions'                        => 'required|array',
            'attributions.*.campagne_objectif_id' => 'required|integer|exists:campagne_objectifs,id',
            'attributions.*.agent_dsa_id'         => 'required|integer|exists:agents_dsa,id',
            'attributions.*.valeur_cible'         => 'required|numeric|min:0.01',
        ]);

        // Vérifications préalables
        $agentsAffectes = AgentAffectation::where('campagne_id', $campagne->id)
            ->whereNull('date_fin')
            ->pluck('agent_dsa_id')
            ->toArray();

        $objectifsIds = CampagneObjectif::where('campagne_id', $campagne->id)
            ->pluck('id')
            ->toArray();

        foreach ($data['attributions'] as $att) {
            if (! in_array($att['agent_dsa_id'], $agentsAffectes)) {
                throw ValidationException::withMessages([
                    'attributions' => "L'agent #{$att['agent_dsa_id']} n'est pas affecté à cette campagne.",
                ]);
            }
            if (! in_array($att['campagne_objectif_id'], $objectifsIds)) {
                throw ValidationException::withMessages([
                    'attributions' => "L'objectif #{$att['campagne_objectif_id']} n'appartient pas à cette campagne.",
                ]);
            }
        }

        DB::transaction(function () use ($campagne, $data, $request) {
            foreach ($data['attributions'] as $att) {
                $objectif = CampagneObjectif::findOrFail($att['campagne_objectif_id']);

                // Chercher une attribution existante
                $existing = ObjectifMesurable::where('campagne_objectif_id', $att['campagne_objectif_id'])
                    ->where('agent_dsa_id', $att['agent_dsa_id'])
                    ->first();

                // ⚠️ Statut cible selon le statut campagne
                $statutCible = $existing
                    ? ObjectifMesurable::statutCibleSelonCampagne($campagne->statut, $existing->statut)
                    : ObjectifMesurable::statutCibleSelonCampagne($campagne->statut, null);

                ObjectifMesurable::updateOrCreate(
                    [
                        'campagne_objectif_id' => $att['campagne_objectif_id'],
                        'agent_dsa_id'         => $att['agent_dsa_id'],
                    ],
                    [
                        'campagne_id'     => $campagne->id,
                        'partenaire_id'   => $campagne->partenaire_id,
                        'produit_dsa_id'  => $objectif->produit_dsa_id,
                        'code'            => $existing?->code ?? ('OBJ-' . uniqid()),
                        'libelle'         => $objectif->libelleType(),
                        'type_objectif'   => $objectif->type_objectif,
                        'valeur_cible'    => $att['valeur_cible'],
                        'valeur_realisee' => $existing?->valeur_realisee ?? 0,
                        'unite'           => $objectif->unite,
                        'periode_debut'   => $campagne->date_debut,
                        'periode_fin'     => $campagne->date_fin ?? now()->addMonths(3),
                        'statut'          => $statutCible,
                        'cree_par'        => $existing?->cree_par ?? $request->user()->id,
                    ]
                );
            }

            // Recalculer les valeurs réalisées globales des objectifs touchés
            $objectifsTouches = array_unique(array_column($data['attributions'], 'campagne_objectif_id'));
            foreach ($objectifsTouches as $oid) {
                CampagneObjectif::find($oid)?->recalculerRealisee();
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Attributions enregistrées',
        ]);
    }

    /**
     * DELETE /api/dsa/objectifs/{id}/retirer-attribution
     */
    public function retirer(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.campagnes.gerer');

        $objectif = ObjectifMesurable::with('campagne')->findOrFail($id);

        // ⚠️ Contrôle statut campagne
        if ($objectif->campagne && ! in_array($objectif->campagne->statut, self::STATUTS_ATTRIBUTION, true)) {
            throw ValidationException::withMessages([
                'statut' => "Impossible de retirer une attribution d'une campagne en statut {$objectif->campagne->statut}.",
            ]);
        }

        if ($objectif->statut === 'ANNULE') {
            throw ValidationException::withMessages([
                'statut' => 'Objectif déjà annulé.',
            ]);
        }

        // Retirer = annuler l'objectif mesurable
        $objectif->update(['statut' => 'ANNULE']);

        // Recalculer la valeur globale
        $objectif->campagneObjectif?->recalculerRealisee();

        return response()->json(['success' => true, 'message' => 'Attribution retirée']);
    }
}