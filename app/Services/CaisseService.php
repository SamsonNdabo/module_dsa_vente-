<?php

namespace App\Services;

use App\DTO\ApprovisionnementDTO;
use App\DTO\FermetureDTO;
use App\DTO\TransfertDTO;
use App\Exceptions\BusinessException;
use App\Models\Caisses;
use App\Models\Devise;
use App\Models\Journal_Caisses;
use App\Models\JournalCaisseSolde;
use App\Models\JournalMouvementCaisse;
use App\Models\SoldeCaisse;
use App\Models\Succursale;
use App\Models\Transaction_Globale;
use App\Models\Utilisateur;
use App\Models\ExchangeRate;
use App\Services\EcritureService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class CaisseService
{
    // Constantes de permission (à harmoniser avec celles du contrôleur)
    public const PEUT_OUVRIR = 'OUVRIR';
    public const PEUT_FERMER = 'FERMER';
    public const PEUT_TRANSFERER = 'TRANSFERER';
    public const PEUT_RECEVOIR = 'RECEVOIR';
    public const PEUT_BLOQUER = 'BLOQUER';
    public const PEUT_DEBLOQUER = 'DEBLOQUER';
    public const PEUT_DESACTIVER = 'DESACTIVER';
    public const PEUT_REACTIVER = 'REACTIVER';
    public const PEUT_MODIFIER = 'MODIFIER';

    /**
     * Récupère les caisses (index).
     */
    public function getCaisses(array $filters): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = Caisses::with(['succursale', 'devise', 'soldes.devise']);

        // Exclusion des secondaires et coffres par défaut (sauf si demandé)
        if (!isset($filters['categorie'])) {
            $query->where('categorie', '!=', 'SECONDAIRE');
            // $query->where('categorie', '!=', 'COFFRE'); // décommenter selon besoin
        }

        // Application des filtres
        foreach (['statut', 'succursale_id', 'type_caisse', 'inactif', 'categorie', 'parent_id'] as $key) {
            if (!empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }

        $caisses = $query->orderBy('categorie')->orderBy('nom_caisse')->paginate(20);

        // Injecter le caissier humain (utilisateur)
        $this->injecterCaissiers($caisses);

        return $caisses;
    }

    /**
     * Récupère les coffres.
     */
    public function getCoffres(array $filters): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = Caisses::with(['succursale', 'devise', 'caissier', 'soldes.devise'])
            ->where('categorie', 'COFFRE');

        foreach (['statut', 'succursale_id', 'type_caisse', 'inactif'] as $key) {
            if (!empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }

        $caisses = $query->orderBy('nom_caisse')->paginate(10);
        $this->injecterCaissiers($caisses);

        return $caisses;
    }

    /**
     * Crée une nouvelle caisse.
     */
    public function creerCaisse(array $data, $id): Caisses
    {
        // Construction de la clé de verrouillage unique
        $lockKey = 'caisse_create_' . md5(
            ($data['succursale_id'] ?? '') .
            ($data['parent_id'] ?? '') .
            ($data['nom_caisse'] ?? '') .
            ($data['type_caisse'] ?? '') .
            ($data['categorie'] ?? '')
        );

        // Verrou pendant 5 secondes pour éviter les doublons en concurrence
        $lock = Cache::lock($lockKey, 5);
        if (!$lock->get()) {
            throw new BusinessException('Une création de caisse est déjà en cours. Veuillez patienter.');
        }

        try {
            DB::beginTransaction();

            // 1. Vérifications métier (catégorie, parent, etc.)
            if ($data['categorie'] === 'SECONDAIRE') {
                if (empty($data['parent_id'])) {
                    throw new BusinessException('Une caisse secondaire doit être rattachée à une caisse principale.');
                }
                $parent = Caisses::find($data['parent_id']);
                if (!$parent || $parent->peutEtreFermee()) {
                    throw new BusinessException('La caisse principale associée doit être fermée lors de la création d\'une caisse secondaire.');
                }
                $data['caissier_attribue'] = null;

                // Vérifier unicité pour une secondaire : nom + parent_id + type_caisse
                $exists = Caisses::where('parent_id', $data['parent_id'])
                    ->where('nom_caisse', $data['nom_caisse'])
                    ->where('type_caisse', $data['type_caisse'])
                    ->exists();
                if ($exists) {
                    throw new BusinessException("Une caisse secondaire du même type et portant le même nom existe déjà pour cette caisse principale.");
                }
            } else {
                if (!empty($data['parent_id'])) {
                    throw new BusinessException('Seules les caisses secondaires peuvent avoir un parent.');
                }
                if ($data['categorie'] === 'COFFRE') {
                    $data['caissier_attribue'] = null;
                }

                // Vérifier unicité pour une principale/coffre : nom + succursale_id + categorie
                $exists = Caisses::where('succursale_id', $data['succursale_id'])
                    ->where('nom_caisse', $data['nom_caisse'])
                    ->where('categorie', $data['categorie'])
                    ->exists();
                if ($exists) {
                    throw new BusinessException("Une caisse de type '{$data['categorie']}' portant ce nom existe déjà dans cette succursale.");
                }
            }

            // Génération du code si absent
            if (empty($data['code'])) {
                $data['code'] = 'CAISSE-' . strtoupper(Str::random(8));
            }

            // Création
            $caisse = Caisses::create([
                'code'                    => $data['code'],
                'nom_caisse'              => $data['nom_caisse'],
                'id_creator'              => $id,
                'type_caisse'             => $data['type_caisse'],
                'categorie'               => $data['categorie'],
                'succursale_id'           => $data['succursale_id'],
                'caissier_attribue'       => $data['caissier_attribue'] ?? null,
                'parent_id'               => $data['parent_id'] ?? null,
                'statut'                  => 'FERMEE',
                'inactif'                 => 0,
                'date_derniere_operation' => null,
                'service_mobile_money_id' => $data['service_mobile_money_id'] ?? null,
            ]);

            DB::commit();

            return $caisse->load(['succursale', 'devise', 'caissier', 'parent']);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * Met à jour une caisse.
     */
    public function mettreAJour(int $caisseId, array $data): array
    {
        $caisse = Caisses::find($caisseId);
        if (!$caisse) {
            throw new BusinessException('Caisse introuvable', 404);
        }

        if ($caisse->statut === 'OUVERTE') {
            throw new BusinessException('Impossible de modifier une caisse ouverte.');
        }

        if (!$caisse->peut(self::PEUT_MODIFIER)) {
            throw new BusinessException('Cette caisse n\'est pas autorisée à être modifiée.', 403);
        }

        // Mise à jour des champs simples
        $champs = array_filter([
            'nom_caisse'        => $data['nom_caisse'] ?? null,
            'type_caisse'       => $data['type_caisse'] ?? null,
            'categorie'         => $data['categorie'] ?? null,
            'caissier_attribue' => $data['caissier_attribue'] ?? null,
            'parent_id'         => $data['parent_id'] ?? null,
        ], fn($v, $k) => array_key_exists($k, $data), ARRAY_FILTER_USE_BOTH);

        // Vérifications métier sur la catégorie et le parent
        $nouvelleCategorie = $data['categorie'] ?? $caisse->categorie;
        $nouveauParentId   = $data['parent_id'] ?? $caisse->parent_id;

        if ($nouvelleCategorie === 'SECONDAIRE' && empty($nouveauParentId)) {
            throw new BusinessException('Une caisse secondaire doit être rattachée à une caisse principale.');
        }
        if (in_array($nouvelleCategorie, ['PRINCIPALE', 'COFFRE']) && !empty($nouveauParentId)) {
            throw new BusinessException('Seules les caisses secondaires peuvent avoir un parent.');
        }
        if (in_array($nouvelleCategorie, ['SECONDAIRE', 'COFFRE'])) {
            $champs['caissier_attribue'] = null;
        }

        if (!empty($champs)) {
            $caisse->update($champs);
        }

        // Gestion des soldes (ajout/mise à jour/suppression)
        $resultatSoldes = $this->gererSoldesCaisse($caisse, $data);

        return [
            'caisse'      => $caisse->fresh()->load(['succursale', 'caissier', 'soldes.devise', 'parent']),
            'soldes'      => $resultatSoldes['soldes'] ?? [],
            'suppressions'=> $resultatSoldes['suppressions'] ?? [],
        ];
    }

    /**
     * Gère l'ajout, la mise à jour et la suppression des soldes d'une caisse.
     */
    private function gererSoldesCaisse(Caisses $caisse, array $data): array
    {
        $resultat = ['soldes' => [], 'suppressions' => []];

        // Suppression
        foreach ($data['devises_a_supprimer'] ?? [] as $deviseId) {
            $solde = SoldeCaisse::where('caisse_id', $caisse->id)
                ->where('devise_id', $deviseId)
                ->first();
            if (!$solde) {
                $resultat['suppressions'][] = ['devise_id' => $deviseId, 'statut' => 'IGNOREE', 'raison' => 'Devise non associée'];
                continue;
            }
            if ((float) $solde->solde !== 0.0) {
                $devise = Devise::find($deviseId);
                $resultat['suppressions'][] = [
                    'devise_id'   => $deviseId,
                    'devise_code' => $devise->code ?? null,
                    'statut'      => 'REFUSEE',
                    'raison'      => 'Solde non nul : ' . $solde->solde,
                ];
                continue;
            }
            $solde->delete();
            $resultat['suppressions'][] = ['devise_id' => $deviseId, 'statut' => 'SUPPRIMEE'];
        }

        // Ajout / mise à jour
        foreach ($data['soldes'] ?? [] as $item) {
            $solde = SoldeCaisse::where('caisse_id', $caisse->id)
                ->where('devise_id', $item['devise_id'])
                ->first();
            $devise = Devise::find($item['devise_id']);

            if ($solde) {
                if (array_key_exists('solde_minimal', $item)) {
                    $solde->solde_minimal = $item['solde_minimal'] ?? 0;
                }
                if (array_key_exists('solde_maximal', $item)) {
                    $solde->solde_maximal = $item['solde_maximal'] ?? null;
                }
                $solde->save();
                $resultat['soldes'][] = [
                    'devise_id'     => $item['devise_id'],
                    'devise_code'   => $devise->code ?? null,
                    'statut'        => 'MISE_A_JOUR',
                    'solde_actuel'  => (float) $solde->solde,
                    'solde_minimal' => (float) $solde->solde_minimal,
                    'solde_maximal' => $solde->solde_maximal,
                ];
            } else {
                $solde = SoldeCaisse::create([
                    'caisse_id'     => $caisse->id,
                    'devise_id'     => $item['devise_id'],
                    'solde'         => 0,
                    'solde_minimal' => $item['solde_minimal'] ?? 0,
                    'solde_maximal' => $item['solde_maximal'] ?? null,
                ]);
                $resultat['soldes'][] = [
                    'devise_id'     => $item['devise_id'],
                    'devise_code'   => $devise->code ?? null,
                    'statut'        => 'AJOUTEE',
                    'solde_actuel'  => 0,
                    'solde_minimal' => (float) $solde->solde_minimal,
                    'solde_maximal' => $solde->solde_maximal,
                ];
            }
        }

        return $resultat;
    }

    /**
     * Ouvre une caisse (et ses secondaires si principale).
     */
    public function ouvrirCaisse(int $caisseId, int $utilisateurId, ?string $notes = null): array
    {
        $caisse = Caisses::find($caisseId);
        if (!$caisse) {
            throw new BusinessException('Caisse introuvable', 404);
        }

        // Vérifier que la succursale est active
        $succursale = Succursale::find($caisse->succursale_id);
        if (!$succursale || $succursale->statut != 'ACTIVE') {
            throw new BusinessException('La succursale n\'est pas ouverte. Impossible d\'ouvrir la caisse.');
        }

        if (!$caisse->peutEtreOuverte()) {
            throw new BusinessException('Cette caisse ne peut pas être ouverte.');
        }
        if (!$caisse->peut(self::PEUT_OUVRIR)) {
            throw new BusinessException('Cette caisse n\'est pas autorisée à être ouverte.', 403);
        }

        // Ouvrir la caisse
        $this->ouvrirCaisseDirecte($caisse, $utilisateurId, $notes);

        $resultatsEnfants = [];
        if ($caisse->isPrincipale()) {
            $enfants = $caisse->enfants()->where('statut', '!=', 'OUVERTE')->get();
            foreach ($enfants as $enfant) {
                if ($enfant->peutEtreOuverte()) {
                    try {
                        $this->ouvrirCaisseDirecte($enfant, $utilisateurId,
                            "Ouverture automatique via principale #{$caisse->id}" . ($notes ? " ($notes)" : '')
                        );
                        $resultatsEnfants[] = [
                            'id'     => $enfant->id,
                            'nom'    => $enfant->nom_caisse,
                            'statut' => 'OUVERT',
                        ];
                    } catch (\Exception $e) {
                        $resultatsEnfants[] = [
                            'id'     => $enfant->id,
                            'nom'    => $enfant->nom_caisse,
                            'statut' => 'ERREUR',
                            'erreur' => $e->getMessage(),
                        ];
                    }
                } else {
                    $resultatsEnfants[] = [
                        'id'     => $enfant->id,
                        'nom'    => $enfant->nom_caisse,
                        'statut' => 'NON_OUVERT',
                        'raison' => 'Ne peut pas être ouverte',
                    ];
                }
            }
        }

        return [
            'caisse'     => $caisse->fresh()->load(['soldes.devise']),
            'secondaires'=> $resultatsEnfants,
        ];
    }

    /**
     * Ouvre une caisse directement (privé, utilisé en cascade).
     */
    private function ouvrirCaisseDirecte(Caisses $caisse, int $utilisateurId, ?string $notes = null): void
    {
        $caisse->ouvrir(); // statut => OUVERTE

        $journal = Journal_Caisses::create([
            'caisse_id'      => $caisse->id,
            'user_id'        => $utilisateurId,
            'type_operation' => 'OUVERTURE',
            'date_debut'     => now()->toDateTimeString(),
            'statut'         => 'EN_COURS',
            'notes'          => $notes,
        ]);

        $soldesCaisse = SoldeCaisse::where('caisse_id', $caisse->id)->get();
        foreach ($soldesCaisse as $solde) {
            JournalCaisseSolde::create([
                'journal_id'      => $journal->id,
                'devise_id'       => $solde->devise_id,
                'solde_ouverture' => $solde->solde,
                'solde_fermeture' => null,
                'ecart'           => null,
            ]);
        }
    }

    /**
     * Met une caisse en attente (pré‑fermeture).
     */
    public function mettreEnAttente(FermetureDTO $dto): array
    {
        $caisse = Caisses::find($dto->caisseId);
        if (!$caisse) {
            throw new BusinessException('Caisse introuvable', 404);
        }

        if (!$caisse->isPrincipale() && !$caisse->isCoffre()) {
            throw new BusinessException('Seules les caisses principales et les coffres peuvent être mis en attente.');
        }

        if (!$caisse->peutEtreEnAttente()) {
            throw new BusinessException('La caisse doit être ouverte pour être mise en attente.');
        }

        $journal = Journal_Caisses::where('caisse_id', $caisse->id)
            ->enCours()
            ->latest()
            ->first();
        if (!$journal) {
            throw new BusinessException('Aucun journal en cours pour cette caisse.');
        }

        // Cas COFFRE : fermeture directe sans contrôle
        if ($caisse->isCoffre()) {
            $caisse->enAttente();
            $journal->date_fin = now();
            $journal->statut = 'TERMINEE';
            $journal->notes = $dto->notes ?? $journal->notes;
            $journal->save();

            return [
                'caisse'  => $caisse->fresh(),
                'journal' => $journal->fresh(),
                'secondaires' => [],
                'rapport_ecarts' => [],
            ];
        }

        // Cas PRINCIPALE : contrôle des écarts
        return $this->mettreEnAttentePrincipale($caisse, $journal, $dto);
    }

    /**
     * Logique de mise en attente pour une caisse principale.
     */
    private function mettreEnAttentePrincipale(Caisses $caisse, Journal_Caisses $journal, FermetureDTO $dto): array
    {
        // Calculer les soldes consolidés des secondaires physiques
        $soldesPhysiques = SoldeCaisse::whereIn('caisse_id', $caisse->enfants()->where('type_caisse', 'PHYSIQUE')->pluck('id'))
            ->get()
            ->groupBy('devise_id')
            ->map(fn($group) => $group->sum('solde'));

        $lignesOuverture = JournalCaisseSolde::where('journal_id', $journal->id)->get()->keyBy('devise_id');
        $soldesFermetureFournis = collect($dto->soldesFermeture ?? [])->keyBy('devise_id');

        $rapportEcarts = [];
        $aAnomalie = false;

        foreach ($soldesPhysiques as $deviseId => $soldePhysique) {
            $devise = Devise::find($deviseId);
            $soldeOuverture = isset($lignesOuverture[$deviseId]) ? (float) $lignesOuverture[$deviseId]->solde_ouverture : 0;
            $soldeSysteme = (float) $soldePhysique;
            $mouvementJour = round($soldeSysteme - $soldeOuverture, 4);

            $saisie = $soldesFermetureFournis->get($deviseId);
            $montantCompte = $saisie ? (float) $saisie['montant'] : null;

            if ($soldeSysteme > $soldeOuverture && $montantCompte === null) {
                throw new BusinessException("La devise {$devise->code} a augmenté. Veuillez saisir le montant compté.");
            }

            if ($montantCompte === null) {
                $montantCompte = $soldeSysteme;
            }

            if ($montantCompte < $soldeSysteme) {
                throw new BusinessException("Le montant compté pour {$devise->code} est inférieur au solde système.");
            }

            $ecart = round($montantCompte - $soldeSysteme, 4);
            $statutEcart = $ecart > 0 ? 'EXCEDENT' : ($ecart < 0 ? 'DEFICIT' : 'EQUILIBRE');

            $rapportEcarts[$deviseId] = [
                'devise_id'       => $deviseId,
                'devise_code'     => $devise->code,
                'solde_ouverture' => $soldeOuverture,
                'solde_systeme'   => $soldeSysteme,
                'montant_compte'  => $montantCompte,
                'ecart'           => $ecart,
                'statut_ecart'    => $statutEcart,
            ];

            if ($ecart !== 0.0) {
                $aAnomalie = true;
            }
        }

        // Passer la principale en EN_ATTENTE
        $caisse->enAttente();
        $journal->date_fin = now();
        $journal->statut = $aAnomalie ? 'ANOMALIE' : 'TERMINEE';
        $journal->notes = $dto->notes ?? $journal->notes;
        $journal->save();

        // Enregistrer les écarts
        foreach ($rapportEcarts as $rapport) {
            JournalCaisseSolde::updateOrCreate(
                ['journal_id' => $journal->id, 'devise_id' => $rapport['devise_id']],
                [
                    'solde_fermeture' => $rapport['montant_compte'],
                    'ecart'           => $rapport['ecart'],
                ]
            );
        }

        // Mettre en attente les secondaires
        $resultatsSecondaires = $this->propagerEnAttenteAuxSecondaires($caisse, $dto->utilisateurId);

        return [
            'caisse'               => $caisse->fresh()->load(['soldes.devise']),
            'journal'              => $journal->fresh()->load('soldes.devise'),
            'rapport_ecarts'       => array_values($rapportEcarts),
            'secondaires'          => $resultatsSecondaires,
        ];
    }

    /**
     * Propage EN_ATTENTE aux secondaires.
     */
    private function propagerEnAttenteAuxSecondaires(Caisses $caisse, int $utilisateurId): array
    {
        if (!$caisse->isPrincipale()) {
            return [];
        }

        $resultats = [];
        $enfantsOuverts = $caisse->enfants()->where('statut', 'OUVERTE')->get();

        foreach ($enfantsOuverts as $enfant) {
            try {
                $this->mettreEnAttenteSecondaire($enfant, $utilisateurId);
                $resultats[] = [
                    'id'     => $enfant->id,
                    'nom'    => $enfant->nom_caisse,
                    'statut' => 'EN_ATTENTE',
                ];
            } catch (\Exception $e) {
                $resultats[] = [
                    'id'     => $enfant->id,
                    'nom'    => $enfant->nom_caisse,
                    'statut' => 'ERREUR',
                    'erreur' => $e->getMessage(),
                ];
            }
        }
        return $resultats;
    }

    /**
     * Met une secondaire en EN_ATTENTE.
     */
    private function mettreEnAttenteSecondaire(Caisses $caisse, int $utilisateurId): void
    {
        if ($caisse->statut !== 'OUVERTE') {
            return;
        }

        $journal = Journal_Caisses::where('caisse_id', $caisse->id)
            ->enCours()
            ->latest()
            ->first();

        if (!$journal) {
            // Créer un journal d'office
            $journal = Journal_Caisses::create([
                'caisse_id'      => $caisse->id,
                'user_id'        => $utilisateurId,
                'type_operation' => 'OUVERTURE',
                'date_debut'     => now(),
                'statut'         => 'EN_COURS',
            ]);
            foreach (SoldeCaisse::where('caisse_id', $caisse->id)->get() as $solde) {
                JournalCaisseSolde::create([
                    'journal_id'      => $journal->id,
                    'devise_id'       => $solde->devise_id,
                    'solde_ouverture' => $solde->solde,
                    'solde_fermeture' => null,
                    'ecart'           => null,
                ]);
            }
        }

        $soldesActuels = SoldeCaisse::where('caisse_id', $caisse->id)->get()->keyBy('devise_id');
        $lignesJournal = JournalCaisseSolde::where('journal_id', $journal->id)->get();
        foreach ($lignesJournal as $ligne) {
            $soldeActuel = $soldesActuels->get($ligne->devise_id);
            if ($soldeActuel) {
                $ligne->update([
                    'solde_fermeture' => $soldeActuel->solde,
                    'ecart'           => 0,
                ]);
            }
        }

        $journal->update([
            'date_fin' => now(),
            'statut'   => 'TERMINEE',
            'notes'    => ($journal->notes ? $journal->notes . ' | ' : '')
                        . "Mise en attente auto via principale #{$caisse->parent_id}",
        ]);

        $caisse->enAttente();
    }

    /**
     * Valide la fermeture d'une caisse (superviseur).
     */
    public function validerFermeture(FermetureDTO $dto): array
    {
        return DB::transaction(function () use ($dto) {
            $caisse = Caisses::findOrFail($dto->caisseId);

            if (!$caisse->peutEtreValidee()) {
                throw new BusinessException('Seule une caisse EN_ATTENTE peut être validée — statut actuel : ' . $caisse->statut);
            }

            $journal = Journal_Caisses::where('caisse_id', $dto->caisseId)
                ->whereIn('statut', ['TERMINEE', 'ANOMALIE'])
                ->latest()
                ->first();
            if (!$journal) {
                throw new BusinessException('Aucun journal de fermeture trouvé pour cette caisse.');
            }

            $coffre = Caisses::coffrePourSuccursale($caisse->succursale_id);
            if (!$coffre) {
                throw new BusinessException('Aucun coffre trouvé pour cette succursale.');
            }

            // Traiter les ajustements et transferts pour la caisse principale
            $rapportAjustements = [];
            $transferts = [];
            $this->traiterClotureEtTransfert($caisse, $journal, $coffre, $dto->utilisateurId, $rapportAjustements, $transferts);

            // Secondaires
            $resultatsSecondaires = [];
            if ($caisse->isPrincipale()) {
                $enfants = $caisse->enfants()->where('statut', '!=', 'FERMEE')->get();
                foreach ($enfants as $enfant) {
                    try {
                        $journalEnfant = Journal_Caisses::where('caisse_id', $enfant->id)
                            ->whereIn('statut', ['TERMINEE', 'ANOMALIE'])
                            ->latest()
                            ->first();
                        if (!$journalEnfant) {
                            $journalEnfant = Journal_Caisses::create([
                                'caisse_id'      => $enfant->id,
                                'user_id'        => $dto->utilisateurId,
                                'type_operation' => 'FERMETURE_AUTO',
                                'date_debut'     => now(),
                                'date_fin'       => now(),
                                'statut'         => 'TERMINEE',
                                'notes'          => "Fermeture automatique via principale #{$caisse->id}",
                            ]);
                            foreach (SoldeCaisse::where('caisse_id', $enfant->id)->get() as $solde) {
                                JournalCaisseSolde::create([
                                    'journal_id'      => $journalEnfant->id,
                                    'devise_id'       => $solde->devise_id,
                                    'solde_ouverture' => $solde->solde,
                                    'solde_fermeture' => $solde->solde,
                                    'ecart'           => 0,
                                ]);
                            }
                        }

                        $rapportEnfant = [];
                        $transfertsEnfant = [];
                        $this->traiterClotureEtTransfert($enfant, $journalEnfant, $coffre,
                            $dto->utilisateurId, $rapportEnfant, $transfertsEnfant);

                        $journalEnfant->valide_fermeture = $dto->utilisateurId;
                        $journalEnfant->save();
                        $enfant->fermer();

                        $resultatsSecondaires[] = [
                            'id'         => $enfant->id,
                            'nom'        => $enfant->nom_caisse,
                            'statut'     => 'FERMEE',
                            'transferts' => $transfertsEnfant,
                        ];
                    } catch (\Exception $e) {
                        $resultatsSecondaires[] = [
                            'id'     => $enfant->id,
                            'nom'    => $enfant->nom_caisse,
                            'statut' => 'ERREUR',
                            'erreur' => $e->getMessage(),
                        ];
                    }
                }
            }

            // Finalisation de la caisse principale
            $journal->valide_fermeture = $dto->utilisateurId;
            if ($dto->notes) {
                $journal->notes = ($journal->notes ? $journal->notes . ' | ' : '') . 'Validation : ' . $dto->notes;
            }
            $journal->save();
            $caisse->fermer();

            return [
                'caisse'                => $caisse->fresh()->load(['soldes.devise', 'parent']),
                'journal'               => $journal->fresh()->load(['soldes.devise', 'validateurFermeture']),
                'rapport_ajustements'   => $rapportAjustements,
                'transferts_principale' => $transferts,
                'secondaires'           => $resultatsSecondaires,
                'resume' => [
                    'nb_devises_ajustees'    => collect($rapportAjustements)->where('ajuste', true)->count(),
                    'nb_devises_transferees' => count($transferts),
                    'coffre_destination'     => $coffre->id,
                    'valide_par'             => $dto->utilisateurId,
                    'date_validation'        => now()->toDateTimeString(),
                ],
            ];
        });
    }

    /**
     * Gère l'ajustement des écarts et le transfert des soldes vers le coffre.
     */
    private function traiterClotureEtTransfert(
        Caisses $caisse,
        Journal_Caisses $journal,
        Caisses $coffre,
        int $utilisateurId,
        array &$rapportAjustements,
        array &$transferts
    ): void {
        if (in_array($caisse->type_caisse, ['MOBILE_MONEY', 'BANCAIRE'])) {
            return;
        }

        // Ajustement des écarts
        $soldesJournal = JournalCaisseSolde::where('journal_id', $journal->id)
            ->with('devise')
            ->get();

        foreach ($soldesJournal as $soldeJournal) {
            $devise        = $soldeJournal->devise;
            $soldeSysteme  = (float) $caisse->getSolde($soldeJournal->devise_id);
            $montantCompte = $soldeJournal->solde_fermeture !== null
                                ? (float) $soldeJournal->solde_fermeture
                                : $soldeSysteme;
            $ecart         = round($montantCompte - $soldeSysteme, 4);

            if ($ecart > 0) {
                $soldeCaisseModel = SoldeCaisse::where('caisse_id', $caisse->id)
                    ->where('devise_id', $soldeJournal->devise_id)
                    ->first();
                if ($soldeCaisseModel) {
                    $ancienSolde = (float) $soldeCaisseModel->solde;
                    $soldeCaisseModel->solde = $montantCompte;
                    $soldeCaisseModel->save();

                    JournalMouvementCaisse::enregistrer(
                        $caisse->id,
                        $soldeJournal->devise_id,
                        'AJUSTEMENT_SURPLUS',
                        $ecart,
                        $ancienSolde,
                        $montantCompte,
                        'VALID-' . $journal->id,
                        'Ajustement excédent — validation fermeture journal #' . $journal->id,
                        $utilisateurId
                    );
                }
            }

            $rapportAjustements[] = [
                'devise_id'        => $soldeJournal->devise_id,
                'devise_code'      => $devise->code ?? null,
                'solde_ouverture'  => (float) $soldeJournal->solde_ouverture,
                'montant_caissier' => $montantCompte,
                'solde_systeme'    => $soldeSysteme,
                'ecart'            => $ecart,
                'type_ecart'       => $ecart > 0 ? 'EXCEDENT' : ($ecart < 0 ? 'DEFICIT' : 'EQUILIBRE'),
                'ajuste'           => $ecart > 0,
                'nouveau_solde'    => $ecart > 0 ? $montantCompte : $soldeSysteme,
            ];
        }

        // Transfert des soldes vers le coffre
        $soldesCaisse = SoldeCaisse::where('caisse_id', $caisse->id)->get();
        foreach ($soldesCaisse as $solde) {
            $montant = (float) $solde->solde;
            if ($montant <= 0) continue;

            $caisse->debiter(
                $montant,
                $solde->devise_id,
                "Fermeture — transfert au coffre #{$coffre->id}",
                'CLOSE-' . $journal->id,
                $utilisateurId
            );

            $coffre->crediter(
                $montant,
                $solde->devise_id,
                "Fermeture caisse #{$caisse->id}",
                'CLOSE-' . $journal->id,
                $utilisateurId
            );

            $transferts[] = [
                'devise_id'   => $solde->devise_id,
                'devise_code' => optional(Devise::find($solde->devise_id))->code,
                'montant'     => $montant,
                'coffre_id'   => $coffre->id,
            ];
        }
    }

    /**
     * Détail de fermeture (pour la vue superviseur).
     */
    public function detailFermeture(int $caisseId): array
    {
        $caisse = Caisses::find($caisseId);
        if (!$caisse) {
            throw new BusinessException('Caisse introuvable', 404);
        }

        $journal = Journal_Caisses::where('caisse_id', $caisseId)
            ->whereIn('statut', ['EN_COURS', 'TERMINEE', 'ANOMALIE'])
            ->latest()
            ->first();
        if (!$journal) {
            throw new BusinessException('Aucun journal trouvé pour cette caisse.');
        }

        // Soldes du journal
        $soldesJournal = JournalCaisseSolde::where('journal_id', $journal->id)
            ->with('devise')
            ->get()
            ->map(fn($s) => [
                'devise_id'       => $s->devise_id,
                'devise_code'     => $s->devise->code ?? null,
                'devise_nom'      => $s->devise->nom ?? null,
                'solde_ouverture' => (float) $s->solde_ouverture,
                'solde_fermeture' => $s->solde_fermeture !== null
                                        ? (float) $s->solde_fermeture
                                        : (float) $caisse->getSolde($s->devise_id),
                'ecart'           => $s->ecart !== null
                                        ? (float) $s->ecart
                                        : round($caisse->getSolde($s->devise_id) - (float) $s->solde_ouverture, 4),
                'statut_ecart'    => $s->ecart > 0 ? 'EXCEDENT' : ($s->ecart < 0 ? 'DEFICIT' : 'EQUILIBRE'),
            ]);

        $dateJournal = Carbon::parse($journal->date_debut)->toDateString();

        // Secondaires
        if ($caisse->isPrincipale()) {
            $secondaires = $caisse->enfants;
        } else {
            $secondaires = collect([$caisse]);
        }
        $idsSecondaires = $secondaires->pluck('id');

        // Transactions du jour
        $transactions = Transaction_Globale::with('devise')
            ->where(function ($q) use ($idsSecondaires) {
                $q->whereIn('caisse_id', $idsSecondaires)
                  ->orWhereIn('caisse_mobile_id', $idsSecondaires)
                  ->orWhereIn('caisse_physique_id', $idsSecondaires);
            })
            ->whereDate('date_transaction', $dateJournal)
            ->get();

        // Grouper par caisse et devise
        $parCaisse = [];
        foreach ($secondaires as $secondaire) {
            $devisesInit = SoldeCaisse::where('caisse_id', $secondaire->id)
                ->with('devise')
                ->get()
                ->mapWithKeys(fn($s) => [
                    $s->devise_id => [
                        'devise_id'   => $s->devise_id,
                        'devise_code' => $s->devise->code ?? null,
                        'devise_nom'  => $s->devise->nom ?? null,
                        'entrees'     => 0,
                        'sorties'     => 0,
                        'transactions'=> [],
                    ]
                ])->toArray();

            $parCaisse[$secondaire->id] = [
                'caisse_id'   => $secondaire->id,
                'caisse_nom'  => $secondaire->nom_caisse,
                'caisse_type' => $secondaire->type_caisse,
                'par_devise'  => $devisesInit,
            ];
        }

        $typesEntrees = ['MOBILE_MONEY','CHANGE','TRANSFERT_INTERNATIONAL_RECEPTION'];
        $typesSorties = ['MOBILE_MONEY_RETRAIT','TRANSFERT_INTERNATIONAL','VENTE'];

        foreach ($transactions as $tr) {
            $caisseSecondaireId = $tr->caisse_id ?? $tr->caisse_mobile_id ?? $tr->caisse_physique_id;
            if (!$caisseSecondaireId || !isset($parCaisse[$caisseSecondaireId])) continue;

            $deviseId = $tr->devise_id;
            if (!isset($parCaisse[$caisseSecondaireId]['par_devise'][$deviseId])) {
                $parCaisse[$caisseSecondaireId]['par_devise'][$deviseId] = [
                    'devise_id'   => $deviseId,
                    'devise_code' => optional($tr->devise)->code,
                    'devise_nom'  => optional($tr->devise)->nom,
                    'entrees'     => 0,
                    'sorties'     => 0,
                    'transactions'=> [],
                ];
            }

            $detail = &$parCaisse[$caisseSecondaireId]['par_devise'][$deviseId];
            $montant = (float) $tr->montant_local;

            if (in_array($tr->type, $typesEntrees)) {
                $detail['entrees'] += $montant;
            } elseif (in_array($tr->type, $typesSorties)) {
                $detail['sorties'] += $montant;
            }

            $detail['transactions'][] = [
                'id'          => $tr->id,
                'reference'   => $tr->reference,
                'type'        => $tr->type,
                'montant'     => $montant,
                'statut'      => $tr->statut,
                'date'        => $tr->date_transaction,
                'description' => $tr->description,
            ];
        }

        $resultatParCaisse = [];
        foreach ($parCaisse as $caisseId => $data) {
            $devises = [];
            foreach ($data['par_devise'] as $deviseId => $d) {
                $d['solde_net'] = round($d['entrees'] - $d['sorties'], 4);
                $d['nombre_transactions'] = count($d['transactions']);
                $devises[] = $d;
            }
            $resultatParCaisse[] = [
                'caisse_id'   => $data['caisse_id'],
                'caisse_nom'  => $data['caisse_nom'],
                'caisse_type' => $data['caisse_type'],
                'devises'     => $devises,
            ];
        }

        return [
            'caisse'   => [
                'id'   => $caisse->id,
                'nom'  => $caisse->nom_caisse,
                'code' => $caisse->code,
                'type' => $caisse->type_caisse,
                'statut' => $caisse->statut,
            ],
            'journal'  => [
                'id'         => $journal->id,
                'statut'     => $journal->statut,
                'date_debut' => $journal->date_debut,
                'date_fin'   => $journal->date_fin,
                'notes'      => $journal->notes,
            ],
            'soldes_par_devise'       => $soldesJournal,
            'transactions_par_caisse' => $resultatParCaisse,
            'resume'                  => [
                'date_journal'       => $dateJournal,
                'total_transactions' => $transactions->count(),
                'total_commission'   => round($transactions->sum('commission_starehe'), 4),
            ],
        ];
    }

    /**
     * Bloque une caisse (et ses secondaires).
     */
    public function bloquerCaisse(int $caisseId, string $notes): array
    {
        $caisse = Caisses::find($caisseId);
        if (!$caisse) {
            throw new BusinessException('Caisse introuvable', 404);
        }

        if (!$caisse->peutEtreBloquee()) {
            throw new BusinessException('Cette caisse ne peut pas être bloquée.');
        }
        if (!$caisse->peut(self::PEUT_BLOQUER)) {
            throw new BusinessException('Cette caisse n\'est pas autorisée à être bloquée.', 403);
        }

        $caisse->bloquer();

        $resultatsSecondaires = [];
        if ($caisse->isPrincipale()) {
            $enfants = $caisse->enfants()
                ->where('statut', '!=', 'BLOQUEE')
                ->where('statut', '!=', 'FERMEE')
                ->get();
            foreach ($enfants as $enfant) {
                try {
                    if ($enfant->peutEtreBloquee()) {
                        $enfant->bloquer();
                        $resultatsSecondaires[] = ['id' => $enfant->id, 'nom' => $enfant->nom_caisse, 'statut' => 'BLOQUEE'];
                    } else {
                        $resultatsSecondaires[] = ['id' => $enfant->id, 'nom' => $enfant->nom_caisse, 'statut' => 'NON_BLOQUEE', 'raison' => 'Statut incompatible'];
                    }
                } catch (\Exception $e) {
                    $resultatsSecondaires[] = ['id' => $enfant->id, 'nom' => $enfant->nom_caisse, 'statut' => 'ERREUR', 'erreur' => $e->getMessage()];
                }
            }
        }

        return [
            'caisse'      => $caisse->fresh()->load(['soldes.devise']),
            'notes'       => $notes,
            'secondaires' => $resultatsSecondaires,
        ];
    }

    /**
     * Débloque une caisse (et ses secondaires).
     */
    public function debloquerCaisse(int $caisseId): array
    {
        $caisse = Caisses::find($caisseId);
        if (!$caisse) {
            throw new BusinessException('Caisse introuvable', 404);
        }

        if (!$caisse->peutEtreDebloquee()) {
            throw new BusinessException('La caisse n\'est pas bloquée.');
        }
        if (!$caisse->peut(self::PEUT_DEBLOQUER)) {
            throw new BusinessException('Cette caisse n\'est pas autorisée à être débloquée.', 403);
        }

        $caisse->debloquer();

        $resultatsSecondaires = [];
        if ($caisse->isPrincipale()) {
            $enfants = $caisse->enfants()->where('statut', 'BLOQUEE')->get();
            foreach ($enfants as $enfant) {
                try {
                    $enfant->debloquer();
                    $resultatsSecondaires[] = ['id' => $enfant->id, 'nom' => $enfant->nom_caisse, 'statut' => 'DEBLOQUEE'];
                } catch (\Exception $e) {
                    $resultatsSecondaires[] = ['id' => $enfant->id, 'nom' => $enfant->nom_caisse, 'statut' => 'ERREUR', 'erreur' => $e->getMessage()];
                }
            }
        }

        return [
            'caisse'      => $caisse->fresh()->load(['soldes.devise']),
            'secondaires' => $resultatsSecondaires,
        ];
    }

    /**
     * Désactive une caisse (et ses secondaires).
     */
    public function desactiverCaisse(int $caisseId): array
    {
        $caisse = Caisses::find($caisseId);
        if (!$caisse) {
            throw new BusinessException('Caisse introuvable', 404);
        }

        if (!$caisse->desactiver()) {
            throw new BusinessException('Impossible de désactiver une caisse ouverte.');
        }
        if (!$caisse->peut(self::PEUT_DESACTIVER)) {
            throw new BusinessException('Cette caisse n\'est pas autorisée à être désactivée.', 403);
        }

        $resultatsSecondaires = [];
        if ($caisse->isPrincipale()) {
            $enfants = $caisse->enfants()->where('inactif', 0)->get();
            foreach ($enfants as $enfant) {
                try {
                    if ($enfant->desactiver()) {
                        $resultatsSecondaires[] = ['id' => $enfant->id, 'nom' => $enfant->nom_caisse, 'statut' => 'DESACTIVEE'];
                    } else {
                        $resultatsSecondaires[] = ['id' => $enfant->id, 'nom' => $enfant->nom_caisse, 'statut' => 'ERREUR', 'raison' => 'Ouverte ou impossible'];
                    }
                } catch (\Exception $e) {
                    $resultatsSecondaires[] = ['id' => $enfant->id, 'nom' => $enfant->nom_caisse, 'statut' => 'ERREUR', 'erreur' => $e->getMessage()];
                }
            }
        }

        return [
            'caisse'      => $caisse->fresh()->load(['soldes.devise']),
            'secondaires' => $resultatsSecondaires,
        ];
    }

    /**
     * Réactive une caisse (et ses secondaires).
     */
    public function reactiverCaisse(int $caisseId): array
    {
        $caisse = Caisses::find($caisseId);
        if (!$caisse) {
            throw new BusinessException('Caisse introuvable', 404);
        }

        if (!$caisse->peut(self::PEUT_REACTIVER)) {
            throw new BusinessException('Cette caisse n\'est pas autorisée à être réactivée.', 403);
        }

        $caisse->reactiver();

        $resultatsSecondaires = [];
        if ($caisse->isPrincipale()) {
            $enfants = $caisse->enfants()->where('inactif', 1)->get();
            foreach ($enfants as $enfant) {
                try {
                    $enfant->reactiver();
                    $resultatsSecondaires[] = ['id' => $enfant->id, 'nom' => $enfant->nom_caisse, 'statut' => 'REACTIVEE'];
                } catch (\Exception $e) {
                    $resultatsSecondaires[] = ['id' => $enfant->id, 'nom' => $enfant->nom_caisse, 'statut' => 'ERREUR', 'erreur' => $e->getMessage()];
                }
            }
        }

        return [
            'caisse'      => $caisse->fresh()->load(['soldes.devise']),
            'secondaires' => $resultatsSecondaires,
        ];
    }

    /**
     * Approvisionne un coffre ou des secondaires (atomic).
     */
    public function initialiserSoldes(ApprovisionnementDTO $dto): array
    {
        return DB::transaction(function () use ($dto) {
            $caisse = Caisses::find($dto->caisseId);
            if (!$caisse) {
                throw new BusinessException('Caisse introuvable', 404);
            }

            $userId = auth()->id();
            $reference = $dto->reference ?? 'APPROV-' . now()->format('YmdHis');

            if ($caisse->isCoffre()) {
                return $this->approvisionnerCoffre($caisse, $dto->operations, $reference, $dto->motif, $userId);
            }

            if ($caisse->isPrincipale()) {
                return $this->approvisionnerSecondaires($caisse, $dto->operations, $reference, $dto->motif, $userId);
            }

            throw new BusinessException('Seules les caisses principales ou le coffre peuvent être approvisionnés.');
        });
    }

    /**
     * Approvisionnement direct du coffre.
     */
    private function approvisionnerCoffre(Caisses $caisse, array $soldesData, string $reference, string $motif, ?int $userId): array
    {
        $resultats = [];
        foreach ($soldesData as $soldeData) {
            $montant = (float) $soldeData['montant'];
            $deviseId = $soldeData['devise_id'];
            $devise = Devise::find($deviseId);

            $this->verifierSeuils($soldeData, $deviseId);

            $soldeCaisse = SoldeCaisse::firstOrNew([
                'caisse_id' => $caisse->id,
                'devise_id' => $deviseId,
            ]);
            if (!$soldeCaisse->exists) {
                $soldeCaisse->solde = 0;
                $soldeCaisse->solde_minimal = $soldeData['solde_minimal'] ?? 0;
                $soldeCaisse->solde_maximal = $soldeData['solde_maximal'] ?? null;
            }

            $ancienSolde = (float) $soldeCaisse->solde;
            $soldeCaisse->solde += $montant;

            if (array_key_exists('solde_minimal', $soldeData) && $soldeData['solde_minimal'] !== null) {
                $soldeCaisse->solde_minimal = $soldeData['solde_minimal'];
            }
            if (array_key_exists('solde_maximal', $soldeData)) {
                $soldeCaisse->solde_maximal = $soldeData['solde_maximal'];
            }

            $soldeCaisse->save();

            JournalMouvementCaisse::enregistrer(
                $caisse->id,
                $deviseId,
                'APPROVISIONNEMENT',
                $montant,
                $ancienSolde,
                (float) $soldeCaisse->solde,
                $reference,
                $motif,
                $userId
            );
            
            $service = Utilisateur::extraireServiceCoffre($caisse->nom_caisse);

            if ($service !== 'PHYSIQUE')
            {
            
                // ── Conversion en USD pour l'écriture comptable ────
                $user = auth()->user();
                $montantUsd = ExchangeRate::convertirEnUsd($montant, $deviseId, null, $user->succursale_id);
                if ($montantUsd === null) {
                    throw new \RuntimeException("Impossible de convertir le montant en USD pour la devise ID {$deviseId}.");
                }
                
                // ────────────  Écriture comptable ────────────

                $ecriture = new EcritureService();

                $ecriture->creerEcritureSimple([
                    'montant'           => $montantUsd,
                    'code'              => 'FIT',
                    'entite_id'         => 2,
                    'operation_libelle' => 'Approvisionnement Float ' . strtoupper($service),
                    'libelle'           => 'Approv : ' .$motif,
                    'date_ecriture'     => now()->format('Y-m-d'),
                    'reference'         => $reference
                ]);
                
            }

            $resultats[] = [
                'devise_id'     => $deviseId,
                'devise_code'   => $devise->code ?? null,
                'montant'       => $montant,
                'nouveau_solde' => (float) $soldeCaisse->solde,
                'solde_minimal' => $soldeCaisse->solde_minimal,
                'solde_maximal' => $soldeCaisse->solde_maximal,
            ];
        }

        $caisse->date_derniere_operation = now();
        $caisse->save();

        return [
            'caisse'     => $caisse->only(['id', 'nom_caisse']),
            'operations' => $resultats,
        ];
    }

    /**
     * Approvisionnement des secondaires via une principale.
     */
    private function approvisionnerSecondaires(Caisses $principale, array $secondairesData, string $reference, string $motif, ?int $userId): array
    {
        $resultats = [];
        foreach ($secondairesData as $item) {
            $caisseSecondaire = Caisses::find($item['caisse_id']);
            if (!$caisseSecondaire || $caisseSecondaire->parent_id != $principale->id) {
                throw new BusinessException("La caisse #{$item['caisse_id']} n'est pas une secondaire de la principale #{$principale->id}.");
            }

            foreach ($item['soldes'] as $soldeData) {
                $montant = (float) $soldeData['montant'];
                $deviseId = $soldeData['devise_id'];
                $devise = Devise::find($deviseId);

                $this->verifierSeuils($soldeData, $deviseId, $caisseSecondaire->id);

                $soldeCaisse = SoldeCaisse::firstOrNew([
                    'caisse_id' => $caisseSecondaire->id,
                    'devise_id' => $deviseId,
                ]);
                if (!$soldeCaisse->exists) {
                    $soldeCaisse->solde = 0;
                    $soldeCaisse->solde_minimal = $soldeData['solde_minimal'] ?? 0;
                    $soldeCaisse->solde_maximal = $soldeData['solde_maximal'] ?? null;
                }

                $ancienSolde = (float) $soldeCaisse->solde;
                $soldeCaisse->solde += $montant;

                if (array_key_exists('solde_minimal', $soldeData) && $soldeData['solde_minimal'] !== null) {
                    $soldeCaisse->solde_minimal = $soldeData['solde_minimal'];
                }
                if (array_key_exists('solde_maximal', $soldeData)) {
                    $soldeCaisse->solde_maximal = $soldeData['solde_maximal'];
                }

                $soldeCaisse->save();

                JournalMouvementCaisse::enregistrer(
                    $caisseSecondaire->id,
                    $deviseId,
                    'APPROVISIONNEMENT',
                    $montant,
                    $ancienSolde,
                    (float) $soldeCaisse->solde,
                    $reference,
                    $motif,
                    $userId
                );

                $resultats[] = [
                    'caisse_id'     => $caisseSecondaire->id,
                    'caisse_nom'    => $caisseSecondaire->nom_caisse,
                    'devise_id'     => $deviseId,
                    'devise_code'   => $devise->code ?? null,
                    'montant'       => $montant,
                    'nouveau_solde' => (float) $soldeCaisse->solde,
                    'solde_minimal' => $soldeCaisse->solde_minimal,
                    'solde_maximal' => $soldeCaisse->solde_maximal,
                ];
            }
        }

        if (!empty($resultats)) {
            $ids = collect($resultats)->pluck('caisse_id')->unique();
            Caisses::whereIn('id', $ids)->update(['date_derniere_operation' => now()]);
        }

        return [
            'caisse_principale' => ['id' => $principale->id, 'nom' => $principale->nom_caisse],
            'operations'        => $resultats,
        ];
    }

    /**
     * Transfère des fonds (coffre → principales ou principale → principale).
     */
    public function transfererFonds(TransfertDTO $dto): array
    {
        return DB::transaction(function () use ($dto) {
            $source = Caisses::findOrFail($dto->sourceId);

            if (!$source->peut(self::PEUT_TRANSFERER)) {
                throw new BusinessException('La caisse source n\'est pas autorisée à effectuer des transferts.', 403);
            }

            if ($source->isCoffre()) {
                if (!$dto->destinations || count($dto->destinations) === 0) {
                    throw new BusinessException('Aucune destination spécifiée pour le transfert multiple.');
                }
                return $this->transfertMultipleDepuisCoffre($source, $dto);
            }

            if ($source->isPrincipale()) {
                if (!$dto->destinationId) {
                    throw new BusinessException('Destination requise pour ce type de transfert.');
                }
                return $this->transfertEntrePrincipales($source, $dto);
            }

            throw new BusinessException('Seules les caisses principales ou le coffre peuvent être sources de transfert.');
        });
    }

    /**
     * Transfert multiple depuis un coffre.
     */
    private function transfertMultipleDepuisCoffre(Caisses $coffre, TransfertDTO $dto): array
    {
        if ($coffre->statut != 'OUVERTE' || !$coffre->peutEtreOpereeDirectement()) {
            throw new BusinessException('Le coffre source doit être ouvert et opérable.');
        }

        $typeCoffre = $coffre->type_caisse;
        $serviceMobileId = $coffre->service_mobile_money_id; // Utilisé pour MOBILE_MONEY et BANCAIRE
        $montantUnitaire = $dto->montant;
        $deviseId = $dto->deviseId;

        $operations = [];
        $totalDebit = 0;

        foreach ($dto->destinations as $dest) {
            $principale = Caisses::find($dest['caisse_id']);
            if (!$principale || !$principale->isPrincipale()) {
                throw new BusinessException("La caisse #{$dest['caisse_id']} n'est pas une principale.");
            }

            $secondaire = match ($typeCoffre) {
                'PHYSIQUE'    => $principale->secondairePhysique(),
                'MOBILE_MONEY'=> $principale->secondaireMobileMoney($serviceMobileId),
                'BANCAIRE'    => $principale->secondaireBancaire($serviceMobileId), // On passe le même service ID !
                default       => null,
            };

            if (!$secondaire) {
                throw new BusinessException("La principale #{$principale->id} n'a pas de secondaire {$typeCoffre} correspondante.");
            }
            if ($secondaire->statut != 'OUVERTE' || !$secondaire->peutEtreOpereeDirectement()) {
                throw new BusinessException("La secondaire de la principale #{$principale->id} n'est pas ouverte ou non éligible.");
            }
            if (!$principale->peut(self::PEUT_RECEVOIR)) {
                throw new BusinessException("La principale #{$principale->id} n'est pas autorisée à recevoir des transferts.", 403);
            }

            $operations[] = ['principale' => $principale, 'secondaire' => $secondaire];
            $totalDebit += $montantUnitaire;
        }

        return DB::transaction(function () use ($coffre, $deviseId, $totalDebit, $montantUnitaire, $operations, $dto) {
            $reference = 'TRF-MULTI-' . now()->format('YmdHis');

            if (!$coffre->soldeSuffisant($totalDebit, $deviseId)) {
                throw new BusinessException('Solde insuffisant dans le coffre.');
            }

            if (!$coffre->debiter($totalDebit, $deviseId, "Transfert multiple vers principales", $reference, auth()->id())) {
                throw new BusinessException('Échec du débit du coffre.');
            }

            $resultats = [];
            foreach ($operations as $op) {
                if (!$op['secondaire']->crediter($montantUnitaire, $deviseId, "Transfert depuis coffre #{$coffre->id}", $reference, auth()->id())) {
                    throw new BusinessException("Impossible de créditer la secondaire de la principale #{$op['principale']->id}.");
                }
                $resultats[] = [
                    'principale_id'  => $op['principale']->id,
                    'principale_nom' => $op['principale']->nom_caisse,
                    'secondaire_id'  => $op['secondaire']->id,
                    'secondaire_nom' => $op['secondaire']->nom_caisse,
                    'montant'        => $montantUnitaire,
                    'statut'         => 'SUCCES',
                ];
            }

            return [
                'source_coffre'    => $coffre->only(['id', 'nom_caisse']),
                'montant_unitaire' => $montantUnitaire,
                'devise_id'        => $deviseId,
                'motif'            => $dto->motif,
                'destinations'     => $resultats,
            ];
        });
    }

    /**
     * Transfert entre deux principales.
     */
    private function transfertEntrePrincipales(Caisses $source, TransfertDTO $dto): array
    {
        return DB::transaction(function () use ($source, $dto) {
            $dest = Caisses::findOrFail($dto->destinationId);

            if ($source->statut != 'OUVERTE' || $dest->statut != 'OUVERTE') {
                throw new BusinessException('Les deux caisses doivent être ouvertes.');
            }
            if ($source->isCoffre()) {
                throw new BusinessException('Impossible de créditer le coffre manuellement.');
            }
            if (!$source->isPrincipale() || !$dest->isPrincipale()) {
                throw new BusinessException('Les caisses source et destination doivent être principales.');
            }
            if (!$dest->peut('TRANSFERER')) {
                throw new BusinessException('La caisse destination n\'est pas autorisée à recevoir des transferts.', 403);
            }

            $sourceOp = $source->secondairePhysique();
            $destOp = $dest->secondairePhysique();
            if (!$sourceOp || !$destOp) {
                throw new BusinessException('Impossible de trouver les caisses secondaires physiques.');
            }
            if ($sourceOp->statut != 'OUVERTE' || $destOp->statut != 'OUVERTE') {
                throw new BusinessException('Les caisses physiques doivent être ouvertes.');
            }

            // Vérification du plafond (ex: 1000 USD/EUR)
            $this->verifierPlafondTransfert($dto->montant, $dto->deviseId, $source->succursale_id, $sourceOp->id);

            $reference = $dto->reference ?? 'TRF-' . now()->format('YmdHis');

            if (!$sourceOp->debiter($dto->montant, $dto->deviseId, $dto->motif, $reference, auth()->id())) {
                throw new BusinessException('Échec du débit de la caisse source.');
            }
            if (!$destOp->crediter($dto->montant, $dto->deviseId, $dto->motif, $reference, auth()->id())) {
                // Rollback : on rétablit le débit
                $sourceOp->crediter($dto->montant, $dto->deviseId, 'Rollback transfert échoué', null, auth()->id());
                throw new BusinessException('Échec du crédit de la caisse destination.');
            }

            return [
                'source_principale'     => $source->only(['id', 'nom_caisse']),
                'source_reelle'         => $sourceOp->only(['id', 'nom_caisse']),
                'destination_principale'=> $dest->only(['id', 'nom_caisse']),
                'destination_reelle'    => $destOp->only(['id', 'nom_caisse']),
                'montant'               => $dto->montant,
                'devise_id'             => $dto->deviseId,
                'motif'                 => $dto->motif,
            ];
        });
    }

    /**
     * Récupère la caisse d'un agent.
     */
    public function caissePourAgent(int $utilisateurId): ?Caisses
    {
        return Caisses::with(['soldes.devise', 'succursale'])
            ->where('caissier_attribue', $utilisateurId)
            ->existante()
            ->first();
    }

    /**
     * Récupère les caisses secondaires d'une principale.
     */
    public function getSecondaires(int $parentId): Collection
    {
        return Caisses::with(['succursale', 'devise', 'caissier', 'soldes.devise'])
            ->where('parent_id', $parentId)
            ->get();
    }

    /**
     * Solde consolidé d'une principale.
     */
    public function soldeConsolide(int $caisseId): array
    {
        $caisse = Caisses::with('enfants.soldes.devise')->find($caisseId);
        if (!$caisse) {
            throw new BusinessException('Caisse introuvable', 404);
        }

        $soldeConsolide = $caisse->solde_consolide; // Collection [devise_id => montant]
        $resultat = [];
        foreach ($soldeConsolide as $deviseId => $montant) {
            $devise = Devise::find($deviseId);
            $resultat[] = [
                'devise_id'   => $deviseId,
                'devise_code' => $devise->code ?? null,
                'devise_nom'  => $devise->nom ?? null,
                'solde'       => (float) $montant,
            ];
        }

        return [
            'caisse_id'         => $caisse->id,
            'caisse_nom'        => $caisse->nom_caisse,
            'categorie'         => $caisse->categorie,
            'solde_consolide'   => $resultat,
            'nb_secondaires'    => $caisse->isPrincipale() ? $caisse->enfants->count() : 0,
        ];
    }

    /**
     * Soldes par type de caisse pour une succursale.
     */
    public function soldesSuccursale(int $succursaleId): array
    {
        $succursale = Succursale::find($succursaleId);
        if (!$succursale) {
            throw new BusinessException('Succursale introuvable', 404);
        }

        $principales = Caisses::where('succursale_id', $succursaleId)
            ->where('categorie', 'PRINCIPALE')
            ->where('inactif', 0)
            ->pluck('id');

        if ($principales->isEmpty()) {
            return [];
        }

        $secondaires = Caisses::whereIn('parent_id', $principales)
            ->where('inactif', 0)
            ->with('soldes.devise')
            ->get();

        $resultat = [];
        foreach ($secondaires as $secondaire) {
            $type = $secondaire->type_caisse;
            if (!isset($resultat[$type])) {
                $resultat[$type] = [];
            }
            foreach ($secondaire->soldes as $solde) {
                $code = $solde->devise->code;
                $montant = (float) $solde->solde;
                if (!isset($resultat[$type][$code])) {
                    $resultat[$type][$code] = 0;
                }
                $resultat[$type][$code] += $montant;
            }
        }

        $formatted = [];
        foreach ($resultat as $type => $devises) {
            $details = [];
            foreach ($devises as $code => $montant) {
                $details[] = ['devise' => $code, 'montant' => $montant];
            }
            $formatted[] = ['type' => $type, 'soldes' => $details];
        }
        return $formatted;
    }

    // ──────────────── Méthodes utilitaires internes ─────────────

    private function verifierSeuils(array $soldeData, int $deviseId, ?int $caisseId = null): void
    {
        if (isset($soldeData['solde_minimal'], $soldeData['solde_maximal'])
            && $soldeData['solde_minimal'] !== null
            && $soldeData['solde_maximal'] !== null
            && (float) $soldeData['solde_maximal'] <= (float) $soldeData['solde_minimal']
        ) {
            $msg = $caisseId
                ? "Le solde maximal doit être strictement supérieur au solde minimal pour la caisse #{$caisseId}, devise #{$deviseId}."
                : "Le solde maximal doit être strictement supérieur au solde minimal pour la devise #{$deviseId}.";
            throw new BusinessException($msg);
        }
    }

    private function verifierPlafondTransfert(float $montant, int $deviseId, int $caisseSecondairePhysiqueId, int $succursaleId): void
    {
        $eurId = Devise::getIdByCode('EUR');
        $usdId = Devise::getIdByCode('USD');

        if ($deviseId == $usdId && $montant > 1000) {
            throw new BusinessException('Le montant maximum par transfert entre principales est de 1000 USD.');
        }
        if ($deviseId == $eurId && $montant > 1000) {
            throw new BusinessException('Le montant maximum par transfert entre principales est de 1000 EUR.');
        }

        if ($deviseId != $usdId && $deviseId != $eurId) {
            $equivalentUsd = $this->convertirEnUSD($montant, $deviseId, $caisseSecondairePhysiqueId, $succursaleId);
            if ($equivalentUsd === null) {
                throw new BusinessException('Impossible de vérifier le plafond : taux de change introuvable.');
            }
            $equivalentUsd = round($equivalentUsd, 4);
            if ($equivalentUsd > 1000) {
                throw new BusinessException("Le montant équivaut à {$equivalentUsd} USD, ce qui dépasse le plafond de 1000 USD.");
            }
        }
    }

    /**
     * Convertit un montant en USD via les taux de change (priorité caisse > succursale > global).
     */
    private function convertirEnUSD(float $montant, int $deviseId, ?int $caisseId, int $succursaleId): ?float
    {
        $usdId = Devise::getIdByCode('USD');
        if ($deviseId == $usdId) {
            return $montant;
        }

        // Taux direct USD -> devise
        $taux = $this->getTauxChangeEffectif($usdId, $deviseId, $caisseId, $succursaleId);
        if ($taux) {
            // Si le taux est pour USD -> devise, on utilise le taux d'achat (pour convertir USD en devise)
            // Mais ici on veut convertir une devise en USD, donc on doit utiliser l'inverse du taux de vente
            // On utilise le taux de vente pour USD -> devise, et on divise le montant par ce taux.
            // Simplification : on prend le taux_vente (si le sens est direct) ou on l'inverse selon le cas.
            // Le plus simple est de refaire un appel en inversant les devises.
            $tauxInverse = $this->getTauxChangeEffectif($deviseId, $usdId, $caisseId, $succursaleId);
            if ($tauxInverse) {
                // Si on a un taux pour devise -> USD, on l'utilise directement
                $tauxAchat = $tauxInverse->taux_achat; // ou taux_vente selon le sens
                return $montant * $tauxAchat; // à adapter selon la formule
            }
            // Sinon, on tente de déduire depuis le premier taux
            // Si le taux est direct (USD -> devise), le taux de vente est le nombre de devise pour 1 USD
            // Donc pour convertir devise -> USD, on divise par le taux de vente
            if ($taux->sens === 'direct') {
                return $montant / $taux->taux_vente;
            } else {
                // Si le taux est inverse, on a déjà devise -> USD, on multiplie par taux_achat
                return $montant * $taux->taux_achat;
            }
        }

        // Fallback : essayer de récupérer le taux de référence global
        $tauxGlobal = ExchangeRate::getTauxActif('USD', 'CDF', null, null);
        if ($tauxGlobal) {
            $rate = $tauxGlobal['rate'];
            $sens = $tauxGlobal['sens'];
            // Utiliser le taux de vente ou d'achat selon la devise
            // Cas simple : on prend le taux de vente (USD -> CDF) pour convertir USD en CDF
            // Pour convertir CDF en USD, on divise par le taux de vente
            if ($deviseId == Devise::getIdByCode('CDF')) {
                $tauxVente = $rate->getTauxVente($sens);
                return $montant / $tauxVente;
            }
            // Sinon, on revient à la méthode précédente
        }

        return null;
    }

    /**
     * Récupère le taux de change avec priorité.
     */
    private function getTauxChangeEffectif(
        int $sourceId,
        int $cibleId,
        ?int $caisseId,
        ?int $succursaleId
    ): ?object {
        $sourceCode = Devise::find($sourceId)?->code;
        $destCode   = Devise::find($cibleId)?->code;

        if (!$sourceCode || !$destCode) {
            return null;
        }

        $tauxData = ExchangeRate::getTauxActif($sourceCode, $destCode, $caisseId, $succursaleId);
        if (!$tauxData) {
            return null;
        }

        $exchangeRate = $tauxData['rate'];
        $sens = $tauxData['sens'];

        // Retourner un objet standardisé
        return (object) [
            'taux_achat' => $exchangeRate->getTauxAchat($sens),
            'taux_vente' => $exchangeRate->getTauxVente($sens),
            'sens'       => $sens,
            'niveau'     => $tauxData['niveau'],
        ];
    }

    /**
     * Injecte les utilisateurs (caissiers) dans une collection de caisses.
     */
    private function injecterCaissiers($caisses): void
    {
        $caissierIds = $caisses->pluck('caissier_attribue')->filter()->unique()->toArray();
        $listeCaissiers = Utilisateur::getUtilisateursByIds($caissierIds);

        $caisses->getCollection()->transform(function ($item) use ($listeCaissiers) {
            $item->caissier = $listeCaissiers->get($item->caissier_attribue);
            return $item;
        });
    }
}