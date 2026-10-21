<?php

namespace App\Services;

use App\Models\Devise;
use App\Models\Produit;
use App\Models\ProduitDsa;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProduitDsaService
{
    public function __construct(private SequenceService $sequences) {}

    /**
     * Crée un produit DSA selon 2 cas :
     *  - Cas 1 : lien vers un Produit POS existant (produit_id fourni)
     *  - Cas 2a : produit sans stock, sans POS (produit_id = NULL)
     *  - Cas 2b : création d'un nouveau Produit POS + lien automatique
     *
     * La devise est résolue en cascade (voir resoudreDeviseId()).
     */
    public function creer(array $data, Utilisateur $auteur): ProduitDsa
    {
        return DB::transaction(function () use ($data, $auteur) {
            $produitId  = $data['produit_id'] ?? null;
            $produitPos = null;

            // Cas 2b : création d'un nouveau produit POS
            if (! $produitId && ($data['creer_produit_pos'] ?? false)) {
                $produitPos = $this->creerProduitPos($data);
                $produitId  = $produitPos->id;
            }

            // Cas 1 : vérifier que le produit POS existe
            if ($produitId && ! $produitPos) {
                $produitPos = Produit::find($produitId);
                if (! $produitPos) {
                    throw ValidationException::withMessages([
                        'produit_id' => 'Le produit POS sélectionné est introuvable.',
                    ]);
                }
            }

            // Résolution de la devise en cascade
            $deviseId = $this->resoudreDeviseId($data, $produitPos);

            // Création du produit DSA
            $produitDsa = ProduitDsa::create([
                'partenaire_id'   => $data['partenaire_id'],
                'produit_id'      => $produitId,
                'devise_id'       => $deviseId,
                'code'            => $data['code'],
                'nom'             => $data['nom'],
                'type'            => $data['type'],
                'categorie'       => $data['categorie'] ?? null,
                'prix_vente'      => $data['prix_vente'] ?? null,
                'unite'           => $data['unite'] ?? null,
                'commission'      => $data['commission'] ?? null,
                'commission_type' => $data['commission_type'] ?? 'FIXE',
                'taux_commission' => $data['taux_commission'] ?? null,
                'stock_requis'    => $data['stock_requis'] ?? false,
                'est_activation'  => $data['est_activation'] ?? false,
                'date_debut'      => $data['date_debut'] ?? null,
                'date_fin'        => $data['date_fin'] ?? null,
                'statut'          => $data['statut'] ?? 'ACTIF',
                'metadata'        => $data['metadata'] ?? null,
            ]);

            $auteur->logAudit('DSA_PRODUIT_CREE', null, [
                'produit_dsa_id' => $produitDsa->id,
                'code'           => $produitDsa->code,
                'produit_pos_id' => $produitId,
                'devise_id'      => $deviseId,
            ]);

            return $produitDsa;
        });
    }

    public function modifier(ProduitDsa $produitDsa, array $data, Utilisateur $auteur): ProduitDsa
    {
        return DB::transaction(function () use ($produitDsa, $data, $auteur) {
            $avant = $produitDsa->only(array_keys($data));

            // Si on change de produit POS
            if (array_key_exists('produit_id', $data) && $data['produit_id'] !== $produitDsa->produit_id) {
                if ($data['produit_id'] && ! Produit::whereKey($data['produit_id'])->exists()) {
                    throw ValidationException::withMessages([
                        'produit_id' => 'Le produit POS sélectionné est introuvable.',
                    ]);
                }
            }

            // Si on ne change pas explicitement devise_id, on garde l'actuelle
            if (! array_key_exists('devise_id', $data) && empty($data['devise_id'])) {
                unset($data['devise_id']);
            }

            $produitDsa->update($data);

            $auteur->logAudit('DSA_PRODUIT_MODIFIE', $avant, $data);

            return $produitDsa->fresh(['partenaire', 'produit', 'devise']);
        });
    }

    public function desactiver(ProduitDsa $produitDsa, Utilisateur $auteur): ProduitDsa
    {
        $produitDsa->update(['statut' => 'INACTIF']);

        $auteur->logAudit('DSA_PRODUIT_DESACTIVE', null, ['produit_dsa_id' => $produitDsa->id]);

        return $produitDsa->fresh();
    }

    /* ------------------------------------------------------------------ */
    /* Helpers privés                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Résout la devise du produit DSA en cascade :
     *   1. devise_id explicitement fourni (data.devise_id)
     *   2. devise_id du produit POS lié/créé
     *   3. devise active par défaut en base
     */
    private function resoudreDeviseId(array $data, ?Produit $produitPos): ?int
    {
        if (! empty($data['devise_id'])) {
            return (int) $data['devise_id'];
        }

        if ($produitPos && ! empty($produitPos->devise_id)) {
            return (int) $produitPos->devise_id;
        }

        // Fallback : première devise active (sinon première tout court)
        return Devise::query()
            ->when(
                \Schema::hasColumn('devise', 'est_actif'),
                fn ($q) => $q->where('est_actif', 1)
            )
            ->orderBy('id')
            ->value('id');
    }

    /**
     * Crée un Produit POS à partir des données DSA.
     * La devise du POS = produit_pos_devise_id fourni, sinon devise par défaut.
     */
    private function creerProduitPos(array $data): Produit
    {
        $codeBarre = $data['produit_pos_code_barre'] ?? $this->genererCodeBarre($data['code'] ?? $data['nom']);

        if (Produit::where('code_barre', $codeBarre)->exists()) {
            throw ValidationException::withMessages([
                'produit_pos_code_barre' => "Le code-barre {$codeBarre} existe déjà dans le POS.",
            ]);
        }

        // Devise du POS : fournie explicitement, sinon défaut
        $deviseIdPos = ! empty($data['produit_pos_devise_id'])
            ? (int) $data['produit_pos_devise_id']
            : Devise::query()
                ->when(
                    \Schema::hasColumn('devise', 'est_actif'),
                    fn ($q) => $q->where('est_actif', 1)
                )
                ->orderBy('id')
                ->value('id');

        return Produit::create([
            'code_barre'      => $codeBarre,
            'nom'             => $data['nom'],
            'description'     => $data['produit_pos_description'] ?? null,
            'marque'          => $data['produit_pos_marque'] ?? null,
            'modele'          => $data['produit_pos_modele'] ?? null,
            'type'            => $data['produit_pos_type'] ?? 'STANDARD',
            'gere_imei'       => (bool) ($data['produit_pos_gere_imei'] ?? false),
            'prix_vente'      => $data['prix_vente'] ?? 0,
            'prix_vente_TTC'  => ($data['prix_vente'] ?? 0) * 1.16,
            'prix_achat'      => $data['produit_pos_prix_achat'] ?? 0,
            'prix_achatTTC'   => ($data['produit_pos_prix_achat'] ?? 0) * 1.16,
            'tva'             => $data['produit_pos_tva'] ?? 16,
            'stock_minimum'   => $data['produit_pos_stock_minimum'] ?? 5,
            'est_perissable'  => (bool) ($data['produit_pos_est_perissable'] ?? false),
            'categorie_id'    => $data['produit_pos_categorie_id'] ?? null,
            'devise_id'       => $deviseIdPos,
            'actif'           => true,
        ]);
    }

    private function genererCodeBarre(string $base): string
    {
        $slug = strtoupper(preg_replace('/[^A-Z0-9]/', '', $base));
        $slug = substr($slug, 0, 8) ?: 'PROD';
        return 'DSA-' . $slug . '-' . strtoupper(substr(uniqid(), -4));
    }
}