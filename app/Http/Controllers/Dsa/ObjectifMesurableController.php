<?php

namespace App\Http\Controllers\Dsa;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dsa\Concerns\ExigePermission;
use App\Models\ObjectifMesurable;
use App\Services\ObjectifService;
use Illuminate\Http\Request;

class ObjectifMesurableController extends Controller
{
    use ExigePermission;

    public function __construct(private ObjectifService $service) {}

    /**
     * GET /api/dsa/objectifs?campagne_id=&agent_dsa_id=&partenaire_id=&statut=
     */
    public function index(Request $request)
{
    $this->exiger($request, 'dsa.campagnes.view');

    $q = ObjectifMesurable::with([
            'campagne:id,code,nom',
            'campagneObjectif.produit:id,code,nom',
            'partenaire:id,nom',
            'agent:id,code,prenom,nom',
        ])
        ->when($request->filled('campagne_objectif_id'), fn ($w) => $w->where('campagne_objectif_id', $request->integer('campagne_objectif_id')))
        ->when($request->filled('campagne_id'),          fn ($w) => $w->where('campagne_id', $request->integer('campagne_id')))
        ->when($request->filled('agent_dsa_id'),         fn ($w) => $w->where('agent_dsa_id', $request->integer('agent_dsa_id')))
        ->when($request->filled('partenaire_id'),        fn ($w) => $w->where('partenaire_id', $request->integer('partenaire_id')))
        ->when($request->filled('statut'),               fn ($w) => $w->where('statut', $request->string('statut')))
        ->when($request->filled('type'),                 fn ($w) => $w->where('type_objectif', $request->string('type')))
        ->orderByDesc('periode_debut');

    return response()->json([
        'success' => true,
        'data'    => $q->paginate($request->integer('per_page', 15)),
    ]);
}
    public function show(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.campagnes.view');

        return response()->json([
            'success' => true,
            'data'    => ObjectifMesurable::with(['campagne', 'partenaire', 'agent', 'produit', 'zone'])->findOrFail($id),
        ]);
    }

    public function store(Request $request)
    {
        $this->exiger($request, 'dsa.campagnes.gerer');

        $data = $request->validate([
            'campagne_id'     => 'nullable|integer|exists:campagnes,id',
            'partenaire_id'   => 'required|integer|exists:partenaires,id',
            'agent_dsa_id'    => 'nullable|integer|exists:agents_dsa,id',
            'produit_dsa_id'  => 'nullable|integer|exists:produits_dsa,id',
            'zone_id'         => 'nullable|integer|exists:zones,id',
            'code'            => 'nullable|string|max:30|unique:objectifs_mesurables,code',
            'libelle'         => 'required|string|max:200',
            'type_objectif'   => 'required|in:MONTANT,VOLUME,CLIENTS,COMPTES,VISITES',
            'valeur_cible'    => 'required|numeric|min:0.01',
            'valeur_realisee' => 'nullable|numeric|min:0',
            'unite'           => 'nullable|string|max:20',
            'periode_debut'   => 'required|date',
            'periode_fin'     => 'required|date|after_or_equal:periode_debut',
            'statut'          => 'nullable|in:ACTIF,ATTEINT,DEPASSE,ANNULE',
            'metadata'        => 'nullable|array',
        ]);

        $objectif = $this->service->creer($data, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Objectif créé',
            'data'    => $objectif->load(['campagne', 'agent']),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.campagnes.gerer');

        $objectif = ObjectifMesurable::findOrFail($id);

        $data = $request->validate([
            'libelle'         => 'sometimes|string|max:200',
            'valeur_cible'    => 'sometimes|numeric|min:0.01',
            'valeur_realisee' => 'sometimes|numeric|min:0',
            'unite'           => 'nullable|string|max:20',
            'periode_debut'   => 'sometimes|date',
            'periode_fin'     => 'sometimes|date|after_or_equal:periode_debut',
            'statut'          => 'sometimes|in:ACTIF,ATTEINT,DEPASSE,ANNULE',
            'metadata'        => 'nullable|array',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Objectif mis à jour',
            'data'    => $this->service->modifier($objectif, $data, $request->user()),
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.campagnes.gerer');
        ObjectifMesurable::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'Objectif supprimé']);
    }

    /**
     * GET /api/dsa/campagnes/{campagneId}/objectifs-mesurables?agent_dsa_id=&statut=&type=
     * Liste les objectifs mesurables (par agent) d'une campagne.
     */
    public function parAgentCampagne(Request $request, int $campagneId)
    {
        $this->exiger($request, 'dsa.campagnes.view');

        $campagne = \App\Models\Campagne::findOrFail($campagneId);
        $user = $request->user();

        $q = ObjectifMesurable::with([
                'agent:id,code,prenom,nom',
                'campagneObjectif:id,type_objectif,unite,produit_dsa_id',
                'campagneObjectif.produit:id,code,nom',
            ])
            ->where('campagne_id', $campagne->id)
            ->when($request->filled('agent_dsa_id'), fn ($w) => $w->where('agent_dsa_id', $request->integer('agent_dsa_id')))
            ->when($request->filled('statut'),       fn ($w) => $w->where('statut', $request->string('statut')))
            ->when($request->filled('type'),         fn ($w) => $w->where('type_objectif', $request->string('type')))
            ->orderBy('agent_dsa_id')
            ->orderBy('id');

        // Si l'utilisateur est un AGENT_DSA, forcer son propre agent_dsa_id
        if ($user->hasRole('AGENT_DSA') && $user->agentDsa) {
            $q->where('agent_dsa_id', $user->agentDsa->id);
        }

        return response()->json([
            'success' => true,
            'data'    => $q->get()->map(fn ($o) => [
                'id'                  => $o->id,
                'campagne_objectif_id'=> $o->campagne_objectif_id,
                'agent_dsa_id'        => $o->agent_dsa_id,
                'agent'               => $o->agent ? [
                    'id'          => $o->agent->id,
                    'code'        => $o->agent->code,
                    'nom_complet' => trim("{$o->agent->prenom} {$o->agent->nom}"),
                ] : null,
                'type_objectif'       => $o->type_objectif,
                'libelle'             => $o->libelle,
                'unite'               => $o->unite,
                'valeur_cible'        => (float) $o->valeur_cible,
                'valeur_realisee'     => (float) $o->valeur_realisee,
                'statut'              => $o->statut,
                'taux'                => (float) $o->valeur_cible > 0
                    ? round(((float) $o->valeur_realisee / (float) $o->valeur_cible) * 100, 2)
                    : 0,
                'produit'             => $o->campagneObjectif?->produit ? [
                    'id'   => $o->campagneObjectif->produit->id,
                    'code' => $o->campagneObjectif->produit->code,
                    'nom'  => $o->campagneObjectif->produit->nom,
                ] : null,
            ]),
        ]);
    }

    /**
     * POST /api/dsa/objectifs/{id}/incrementer
     * Utilisé par les ventes / ouvertures de comptes pour avancer un objectif.
     */
    public function incrementer(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.campagnes.gerer');

        $data = $request->validate(['valeur' => 'required|numeric|min:0']);

        $objectif = ObjectifMesurable::findOrFail($id);
        $objectif = $this->service->incrementer($objectif, (float) $data['valeur'], $request->user());

        return response()->json(['success' => true, 'data' => $objectif]);
    }
    
    public function setValeurRealisee(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.campagnes.gerer');

        $data = $request->validate(['valeur' => 'required|numeric|min:0']);

        $objectif = ObjectifMesurable::with('campagne')->findOrFail($id);

        // ⚠️ Campagne doit être ACTIVE
        if ($objectif->campagne && $objectif->campagne->statut !== 'ACTIVE') {
            return response()->json([
                'success' => false,
                'error'   => "Impossible de modifier : la campagne est en statut {$objectif->campagne->statut}.",
            ], 422);
        }

        // ⚠️ Objectif doit être ACTIF/ATTEINT/DEPASSE (pas PLANIFIE ni ANNULE)
        if (! in_array($objectif->statut, ['ACTIF', 'ATTEINT', 'DEPASSE'], true)) {
            return response()->json([
                'success' => false,
                'error'   => "Impossible de modifier un objectif en statut {$objectif->statut}.",
            ], 422);
        }

        $objectif->valeur_realisee = (float) $data['valeur'];
        $objectif->recalculerStatutEtGlobal();

        return response()->json(['success' => true, 'data' => $objectif->fresh()]);
    }

    /**
     * GET /api/dsa/objectifs/{id}/clients
     * Liste les clients DSA créés par l'agent de cet objectif pendant la période de la campagne.
     */
    public function clientsParObjectif(Request $request, int $id)
    {
        $this->exiger($request, 'dsa.objectifs.view');

        $objectif = ObjectifMesurable::with(['campagne', 'agent'])->findOrFail($id);

        // Seulement pour les objectifs de type CLIENTS
        if ($objectif->type_objectif !== 'CLIENTS') {
            return response()->json([
                'success' => false,
                'message' => 'Cet objectif n\'est pas de type CLIENTS.',
            ], 422);
        }

        if (! $objectif->campagne || ! $objectif->agent_dsa_id) {
            return response()->json([
                'success' => true,
                'data'    => [],
                'meta'    => ['total' => 0],
            ]);
        }

        $campagne = $objectif->campagne;
        $dateDebut = $campagne->date_debut ? $campagne->date_debut->startOfDay() : now()->subYear();
        $dateFin   = $campagne->date_fin ? $campagne->date_fin->endOfDay() : now();

        // Filtrer les clients DSA créés par l'agent pendant la campagne
        $query = \App\Models\Client::query()
            ->where('agent_dsa_id', $objectif->agent_dsa_id)
            ->whereBetween('created_at', [$dateDebut, $dateFin])
            ->orderByDesc('created_at');

        // Filtres optionnels
        if ($request->filled('q')) {
            $q = '%' . $request->string('q') . '%';
            $query->where(fn ($w) => $w
                ->where('nom_complet', 'like', $q)
                ->orWhere('code_client', 'like', $q)
                ->orWhere('telephone', 'like', $q)
                ->orWhere('email', 'like', $q));
        }

        $perPage = min($request->integer('per_page', 20), 100);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $paginator->items(),
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
                'campagne'     => [
                    'id'         => $campagne->id,
                    'code'       => $campagne->code,
                    'nom'        => $campagne->nom,
                    'date_debut' => $campagne->date_debut?->toDateString(),
                    'date_fin'   => $campagne->date_fin?->toDateString(),
                ],
                'agent' => [
                    'id'          => $objectif->agent?->id,
                    'code'        => $objectif->agent?->code,
                    'nom_complet' => $objectif->agent
                        ? trim("{$objectif->agent->prenom} {$objectif->agent->nom}")
                        : null,
                ],
            ],
        ]);
    }


}