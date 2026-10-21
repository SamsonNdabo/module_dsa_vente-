<?php
// app/Services/Approvisionnement/GestionImeiService.php

namespace App\Services;

use App\Models\Approvisionnement;
use App\Models\LigneApprovisionnement;
use App\Models\ProduitImei;
use App\Models\StockSuccursale;
use App\Models\MouvementStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GestionImeiService
{
    /**
     * Vérifier et réserver les IMEI lors de la validation OPS
     */
    public function reserverImeisPourValidation(Approvisionnement $approvisionnement, array $imeisParLigne)
    {
        DB::beginTransaction();

        try {
            foreach ($approvisionnement->lignes as $ligne) {
                if (!$this->estProduitSerialise($ligne->produit)) {
                    continue;
                }

                $imeiIds = $imeisParLigne[$ligne->id] ?? [];

                if (empty($imeiIds)) {
                    throw new \Exception("Aucun IMEI sélectionné pour le produit {$ligne->produit->nom}");
                }

                // Vérification des IMEI
                $imeis = ProduitImei::whereIn('id', $imeiIds)
                    ->where('produit_id', $ligne->produit_id)
                    ->where('succursale_id', $approvisionnement->source_decidee_id)
                    ->where('statut', ProduitImei::STATUT_EN_STOCK)
                    ->lockForUpdate()
                    ->get();

                if ($imeis->count() !== count($imeiIds)) {
                    $trouves = $imeis->pluck('id')->toArray();
                    $manquants = array_diff($imeiIds, $trouves);
                    throw new \Exception("Certains IMEI ne sont pas disponibles: " . implode(', ', $manquants));
                }

                // Vérification quantité vs IMEI
                if ($ligne->quantite_demandee != count($imeiIds)) {
                    throw new \Exception(
                        "La quantité demandée ({$ligne->quantite_demandee}) ne correspond pas " .
                        "au nombre d'IMEI sélectionnés (" . count($imeiIds) . ")"
                    );
                }

                // Enregistrer le mouvement de réservation
                $mouvement = $this->enregistrerMouvementReservation($approvisionnement, $ligne, $imeis);

                // Réservation des IMEI
                foreach ($imeis as $imei) {
                    $imei->marquerReserve([
                        'approvisionnement_id' => $approvisionnement->id,
                        'ligne_approvisionnement_id' => $ligne->id,
                        'mouvement_stock_id' => $mouvement->mouvement_id
                    ]);
                }

                // Mise à jour du stock
                $stock = StockSuccursale::where('succursale_id', $approvisionnement->source_decidee_id)
                    ->where('produit_id', $ligne->produit_id)
                    ->first();

                if ($stock) {
                    $stock->quantite_reserve += count($imeis);
                    $stock->save();
                }

                // Stocker les IMEI pour la ligne
                $this->stockerImeisLigne($ligne, $imeis->pluck('id')->toArray());
            }

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Expédier les IMEI lors de l'expédition
     */
    public function expedierImeis(Approvisionnement $approvisionnement)
    {
        DB::beginTransaction();

        try {
            foreach ($approvisionnement->lignes as $ligne) {
                if (!$this->estProduitSerialise($ligne->produit)) {
                    continue;
                }

                $imeiIds = $this->getImeisLigne($ligne);

                if (empty($imeiIds)) {
                    continue;
                }

                // Vérifier que les IMEI sont réservés
                $imeis = ProduitImei::whereIn('id', $imeiIds)
                    ->where('produit_id', $ligne->produit_id)
                    ->where('succursale_id', $approvisionnement->source_decidee_id)
                    ->where('statut', ProduitImei::STATUT_RESERVE)
                    ->where('ligne_approvisionnement_id', $ligne->id)
                    ->lockForUpdate()
                    ->get();

                if ($imeis->count() !== count($imeiIds)) {
                    throw new \Exception("Certains IMEI ne sont pas réservés pour l'expédition");
                }

                // Enregistrer le mouvement de sortie
                $stockSource = StockSuccursale::where('succursale_id', $approvisionnement->source_decidee_id)
                    ->where('produit_id', $ligne->produit_id)
                    ->first();

                $mouvement = $this->enregistrerMouvementSortie($approvisionnement, $ligne, $imeis, $stockSource);

                // Transférer les IMEI
                foreach ($imeis as $imei) {
                    $imei->transferer($approvisionnement->destination_succursale_id, [
                        'approvisionnement_id' => $approvisionnement->id,
                        'ligne_approvisionnement_id' => $ligne->id,
                        'mouvement_stock_id' => $mouvement->mouvement_id
                    ]);
                }

                // Mise à jour du stock source (quantités avant/après déjà gérées dans le mouvement)
                if ($stockSource) {
                    $stockSource->quantite_reserve -= count($imeis);
                    $stockSource->quantite_disponible -= count($imeis);
                    $stockSource->save();
                }

                // Mise à jour de la quantité envoyée
                $ligne->quantite_envoyee = count($imeis);
                $ligne->save();
            }

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Arrivée intermédiaire
     */
    public function arriverIntermediaire(Approvisionnement $approvisionnement)
    {
        DB::beginTransaction();

        try {
            foreach ($approvisionnement->lignes as $ligne) {
                if (!$this->estProduitSerialise($ligne->produit)) {
                    continue;
                }

                $imeiIds = $this->getImeisLigne($ligne);

                if (empty($imeiIds)) {
                    continue;
                }

                // Vérifier que les IMEI sont en transit vers l'intermédiaire
                $imeis = ProduitImei::whereIn('id', $imeiIds)
                    ->where('produit_id', $ligne->produit_id)
                    ->where('succursale_id', $approvisionnement->intermediaire_succursale_id)
                    ->where('statut', ProduitImei::STATUT_TRANSFERE)
                    ->where('ligne_approvisionnement_id', $ligne->id)
                    ->lockForUpdate()
                    ->get();

                if ($imeis->count() !== count($imeiIds)) {
                    throw new \Exception("Certains IMEI ne sont pas arrivés au point intermédiaire");
                }

                // Enregistrer le mouvement d'arrivée intermédiaire
                $stockIntermediaire = StockSuccursale::where('succursale_id', $approvisionnement->intermediaire_succursale_id)
                    ->where('produit_id', $ligne->produit_id)
                    ->first();

                $mouvement = $this->enregistrerMouvementArriveeIntermediaire(
                    $approvisionnement, 
                    $ligne, 
                    $imeis, 
                    $stockIntermediaire
                );

                // Mettre à jour le stock intermédiaire
                if ($stockIntermediaire) {
                    $stockIntermediaire->quantite_disponible += count($imeis);
                    $stockIntermediaire->save();
                }

                // Mettre à jour le statut de l'approvisionnement
                $approvisionnement->statut_operation = 'ARRIVE_INTERMEDIAIRE';
                $approvisionnement->date_arrivee_intermediaire = now();
                $approvisionnement->save();
            }

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Départ intermédiaire
     */
    public function departIntermediaire(Approvisionnement $approvisionnement)
    {
        DB::beginTransaction();

        try {
            foreach ($approvisionnement->lignes as $ligne) {
                if (!$this->estProduitSerialise($ligne->produit)) {
                    continue;
                }

                $imeiIds = $this->getImeisLigne($ligne);

                if (empty($imeiIds)) {
                    continue;
                }

                // Vérifier que les IMEI sont au point intermédiaire
                $imeis = ProduitImei::whereIn('id', $imeiIds)
                    ->where('produit_id', $ligne->produit_id)
                    ->where('succursale_id', $approvisionnement->intermediaire_succursale_id)
                    ->where('statut', ProduitImei::STATUT_TRANSFERE)
                    ->where('ligne_approvisionnement_id', $ligne->id)
                    ->lockForUpdate()
                    ->get();

                if ($imeis->count() !== count($imeiIds)) {
                    throw new \Exception("Certains IMEI sont manquants au point intermédiaire");
                }

                // Enregistrer le mouvement de départ intermédiaire
                $stockIntermediaire = StockSuccursale::where('succursale_id', $approvisionnement->intermediaire_succursale_id)
                    ->where('produit_id', $ligne->produit_id)
                    ->first();

                $mouvement = $this->enregistrerMouvementDepartIntermediaire(
                    $approvisionnement, 
                    $ligne, 
                    $imeis, 
                    $stockIntermediaire
                );

                // Les IMEI restent en transit, mais quittent l'intermédiaire
                // Le stock intermédiaire diminue
                if ($stockIntermediaire) {
                    $stockIntermediaire->quantite_disponible -= count($imeis);
                    $stockIntermediaire->save();
                }

                // Mettre à jour le statut de l'approvisionnement
                $approvisionnement->statut_operation = 'DEPART_INTERMEDIAIRE';
                $approvisionnement->date_depart_intermediaire = now();
                $approvisionnement->save();
            }

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Réceptionner les IMEI à destination
     */
    public function receptionnerImeis(Approvisionnement $approvisionnement, array $ecarts = null)
    {
        DB::beginTransaction();

        try {
            foreach ($approvisionnement->lignes as $ligne) {
                if (!$this->estProduitSerialise($ligne->produit)) {
                    continue;
                }

                $imeiIds = $this->getImeisLigne($ligne);

                if (empty($imeiIds)) {
                    continue;
                }

                // Vérifier que les IMEI sont en transit
                $imeis = ProduitImei::whereIn('id', $imeiIds)
                    ->where('produit_id', $ligne->produit_id)
                    ->where('succursale_id', $approvisionnement->destination_succursale_id)
                    ->where('statut', ProduitImei::STATUT_TRANSFERE)
                    ->where('ligne_approvisionnement_id', $ligne->id)
                    ->lockForUpdate()
                    ->get();

                if ($imeis->count() !== count($imeiIds)) {
                    throw new \Exception("Certains IMEI ne sont pas en transit vers la destination");
                }

                // Gérer les écarts si présents
                $imeisRecus = $imeis;
                $ecartsLigne = [];

                if ($ecarts && isset($ecarts[$ligne->id])) {
                    $resultat = $this->gererEcartsLigne($ligne, $imeis, $ecarts[$ligne->id]);
                    $imeisRecus = $resultat['recus'];
                    $ecartsLigne = $resultat['ecarts'];
                }

                // Enregistrer le mouvement de réception
                $stockDestination = StockSuccursale::where('succursale_id', $approvisionnement->destination_succursale_id)
                    ->where('produit_id', $ligne->produit_id)
                    ->first();

                $mouvement = $this->enregistrerMouvementReception(
                    $approvisionnement,
                    $ligne,
                    $imeisRecus,
                    $ecartsLigne,
                    $stockDestination
                );

                // Réceptionner les IMEI
                foreach ($imeisRecus as $imei) {
                    $imei->recevoir($approvisionnement->destination_succursale_id, [
                        'mouvement_stock_id' => $mouvement->mouvement_id
                    ]);
                }

                // Mise à jour du stock destination
                if ($stockDestination) {
                    $stockDestination->quantite_disponible += count($imeisRecus);
                    $stockDestination->save();
                }

                // Mise à jour de la ligne
                $ligne->quantite_recue = count($imeisRecus);
                $ligne->save();
            }

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Enregistrer le mouvement de réservation
     */
    protected function enregistrerMouvementReservation($approvisionnement, $ligne, $imeis)
    {
        $stock = StockSuccursale::where('succursale_id', $approvisionnement->source_decidee_id)
            ->where('produit_id', $ligne->produit_id)
            ->first();

        $quantiteAvant = $stock ? $stock->quantite_disponible : 0;
        $quantiteApres = $quantiteAvant; // La réservation ne change pas la quantité disponible

        return MouvementStock::create([
            'reference_mouvement' => MouvementStock::genererReference(),
            'succursale_id' => $approvisionnement->source_decidee_id,
            'produit_id' => $ligne->produit_id,
            'stock_source_id' => $stock ? $stock->id : null,
            'stock_destination_id' => null,
            'demande_parent_id' => $approvisionnement->id,
            'type_mouvement' => 'RESERVATION',
            'statut' => 'VALIDE',
            'quantite' => count($imeis),
            'quantite_avant' => $quantiteAvant,
            'quantite_apres' => $quantiteApres,
            'cout_unitaire' => $ligne->produit->prix_achat ?? 0,
            'cout_total' => ($ligne->produit->prix_achat ?? 0) * count($imeis),
            'responsable_id' => auth()->id(),
            'valide_par_id' => auth()->id(),
            'date_mouvement' => now(),
            'date_validation' => now(),
            'raison' => "Réservation IMEI pour approvisionnement #{$approvisionnement->reference}",
            'metadata' => [
                'type' => 'RESERVATION_IMEI',
                'imei_ids' => $imeis->pluck('id')->toArray(),
                'imei_numbers' => $imeis->pluck('numero_imei')->toArray(),
                'ligne_id' => $ligne->id
            ]
        ]);
    }

    /**
     * Enregistrer le mouvement de sortie
     */
    protected function enregistrerMouvementSortie($approvisionnement, $ligne, $imeis, $stockSource)
    {
        $quantiteAvant = $stockSource ? $stockSource->quantite_disponible : 0;
        $quantiteApres = $stockSource ? $stockSource->quantite_disponible - count($imeis) : 0;
        $prixAchat = $ligne->produit->prix_achat ?? 0;

        return MouvementStock::create([
            'reference_mouvement' => MouvementStock::genererReference(),
            'succursale_id' => $approvisionnement->source_decidee_id,
            'produit_id' => $ligne->produit_id,
            'stock_source_id' => $stockSource ? $stockSource->id : null,
            'stock_destination_id' => null,
            'demande_parent_id' => $approvisionnement->id,
            'type_mouvement' => 'SORTIE',
            'statut' => 'VALIDE',
            'quantite' => count($imeis),
            'quantite_avant' => $quantiteAvant,
            'quantite_apres' => $quantiteApres,
            'cout_unitaire' => $prixAchat,
            'cout_total' => $prixAchat * count($imeis),
            'responsable_id' => auth()->id(),
            'valide_par_id' => auth()->id(),
            'date_mouvement' => now(),
            'date_validation' => now(),
            'raison' => "Expédition IMEI pour approvisionnement #{$approvisionnement->reference}",
            'metadata' => [
                'type' => 'EXPEDITION_IMEI',
                'imei_ids' => $imeis->pluck('id')->toArray(),
                'imei_numbers' => $imeis->pluck('numero_imei')->toArray(),
                'ligne_id' => $ligne->id,
                'destination_succursale_id' => $approvisionnement->destination_succursale_id
            ]
        ]);
    }

    /**
     * Enregistrer le mouvement d'arrivée intermédiaire
     */
    protected function enregistrerMouvementArriveeIntermediaire($approvisionnement, $ligne, $imeis, $stockIntermediaire)
    {
        $quantiteAvant = $stockIntermediaire ? $stockIntermediaire->quantite_disponible : 0;
        $quantiteApres = $stockIntermediaire ? $stockIntermediaire->quantite_disponible + count($imeis) : count($imeis);
        $prixAchat = $ligne->produit->prix_achat ?? 0;

        return MouvementStock::create([
            'reference_mouvement' => MouvementStock::genererReference(),
            'succursale_id' => $approvisionnement->intermediaire_succursale_id,
            'produit_id' => $ligne->produit_id,
            'stock_source_id' => null,
            'stock_destination_id' => $stockIntermediaire ? $stockIntermediaire->id : null,
            'demande_parent_id' => $approvisionnement->id,
            'type_mouvement' => 'ENTREE',
            'statut' => 'VALIDE',
            'quantite' => count($imeis),
            'quantite_avant' => $quantiteAvant,
            'quantite_apres' => $quantiteApres,
            'cout_unitaire' => $prixAchat,
            'cout_total' => $prixAchat * count($imeis),
            'responsable_id' => auth()->id(),
            'valide_par_id' => auth()->id(),
            'date_mouvement' => now(),
            'date_validation' => now(),
            'raison' => "Arrivée intermédiaire pour approvisionnement #{$approvisionnement->reference}",
            'metadata' => [
                'type' => 'ARRIVEE_INTERMEDIAIRE_IMEI',
                'imei_ids' => $imeis->pluck('id')->toArray(),
                'imei_numbers' => $imeis->pluck('numero_imei')->toArray(),
                'ligne_id' => $ligne->id,
                'source_succursale_id' => $approvisionnement->source_decidee_id
            ]
        ]);
    }

    /**
     * Enregistrer le mouvement de départ intermédiaire
     */
    protected function enregistrerMouvementDepartIntermediaire($approvisionnement, $ligne, $imeis, $stockIntermediaire)
    {
        $quantiteAvant = $stockIntermediaire ? $stockIntermediaire->quantite_disponible : 0;
        $quantiteApres = $stockIntermediaire ? $stockIntermediaire->quantite_disponible - count($imeis) : 0;
        $prixAchat = $ligne->produit->prix_achat ?? 0;

        return MouvementStock::create([
            'reference_mouvement' => MouvementStock::genererReference(),
            'succursale_id' => $approvisionnement->intermediaire_succursale_id,
            'produit_id' => $ligne->produit_id,
            'stock_source_id' => $stockIntermediaire ? $stockIntermediaire->id : null,
            'stock_destination_id' => null,
            'demande_parent_id' => $approvisionnement->id,
            'type_mouvement' => 'SORTIE',
            'statut' => 'VALIDE',
            'quantite' => count($imeis),
            'quantite_avant' => $quantiteAvant,
            'quantite_apres' => $quantiteApres,
            'cout_unitaire' => $prixAchat,
            'cout_total' => $prixAchat * count($imeis),
            'responsable_id' => auth()->id(),
            'valide_par_id' => auth()->id(),
            'date_mouvement' => now(),
            'date_validation' => now(),
            'raison' => "Départ intermédiaire pour approvisionnement #{$approvisionnement->reference}",
            'metadata' => [
                'type' => 'DEPART_INTERMEDIAIRE_IMEI',
                'imei_ids' => $imeis->pluck('id')->toArray(),
                'imei_numbers' => $imeis->pluck('numero_imei')->toArray(),
                'ligne_id' => $ligne->id,
                'destination_succursale_id' => $approvisionnement->destination_succursale_id
            ]
        ]);
    }

    /**
     * Enregistrer le mouvement de réception
     */
    protected function enregistrerMouvementReception($approvisionnement, $ligne, $imeisRecus, $ecartsLigne, $stockDestination)
    {
        $quantiteAvant = $stockDestination ? $stockDestination->quantite_disponible : 0;
        $quantiteApres = $stockDestination ? $stockDestination->quantite_disponible + count($imeisRecus) : count($imeisRecus);
        $prixAchat = $ligne->produit->prix_achat ?? 0;

        return MouvementStock::create([
            'reference_mouvement' => MouvementStock::genererReference(),
            'succursale_id' => $approvisionnement->destination_succursale_id,
            'produit_id' => $ligne->produit_id,
            'stock_source_id' => null,
            'stock_destination_id' => $stockDestination ? $stockDestination->id : null,
            'demande_parent_id' => $approvisionnement->id,
            'type_mouvement' => 'ENTREE',
            'statut' => 'VALIDE',
            'quantite' => count($imeisRecus),
            'quantite_avant' => $quantiteAvant,
            'quantite_apres' => $quantiteApres,
            'cout_unitaire' => $prixAchat,
            'cout_total' => $prixAchat * count($imeisRecus),
            'responsable_id' => auth()->id(),
            'valide_par_id' => auth()->id(),
            'date_mouvement' => now(),
            'date_validation' => now(),
            'raison' => "Réception IMEI pour approvisionnement #{$approvisionnement->reference}",
            'metadata' => [
                'type' => 'RECEPTION_IMEI',
                'imei_ids_recus' => $imeisRecus->pluck('id')->toArray(),
                'imei_numbers_recus' => $imeisRecus->pluck('numero_imei')->toArray(),
                'ligne_id' => $ligne->id,
                'ecarts' => $ecartsLigne,
                'quantite_recue' => count($imeisRecus),
                'quantite_ecarts' => count($ecartsLigne)
            ]
        ]);
    }

    /**
     * Enregistrer un mouvement d'écart (casse, perte, non conforme)
     */
    protected function enregistrerMouvementEcart($approvisionnement, $ligne, $imei, $typeEcart, $motif = null)
    {
        $stock = StockSuccursale::where('succursale_id', $approvisionnement->destination_succursale_id)
            ->where('produit_id', $ligne->produit_id)
            ->first();

        $quantiteAvant = $stock ? $stock->quantite_disponible : 0;
        // L'écart ne modifie pas la quantité disponible (l'IMEI n'est pas reçu)

        return MouvementStock::create([
            'reference_mouvement' => MouvementStock::genererReference(),
            'succursale_id' => $approvisionnement->destination_succursale_id,
            'produit_id' => $ligne->produit_id,
            'stock_source_id' => null,
            'stock_destination_id' => null,
            'demande_parent_id' => $approvisionnement->id,
            'type_mouvement' => 'ECART',
            'statut' => 'VALIDE',
            'quantite' => 1,
            'quantite_avant' => $quantiteAvant,
            'quantite_apres' => $quantiteAvant,
            'cout_unitaire' => $ligne->produit->prix_achat ?? 0,
            'cout_total' => $ligne->produit->prix_achat ?? 0,
            'responsable_id' => auth()->id(),
            'valide_par_id' => auth()->id(),
            'date_mouvement' => now(),
            'date_validation' => now(),
            'raison' => "Ecart IMEI: {$typeEcart} - {$motif}",
            'metadata' => [
                'type' => 'ECART_IMEI',
                'type_ecart' => $typeEcart,
                'imei_id' => $imei->id,
                'imei_number' => $imei->numero_imei,
                'motif' => $motif,
                'ligne_id' => $ligne->id
            ]
        ]);
    }

    /**
     * Annuler un transfert d'IMEI
     */
    public function annulerTransfertImeis(Approvisionnement $approvisionnement)
    {
        DB::beginTransaction();

        try {
            foreach ($approvisionnement->lignes as $ligne) {
                if (!$this->estProduitSerialise($ligne->produit)) {
                    continue;
                }

                // Récupérer les IMEI en transit
                $imeis = ProduitImei::where('ligne_approvisionnement_id', $ligne->id)
                    ->where('produit_id', $ligne->produit_id)
                    ->where('statut', ProduitImei::STATUT_TRANSFERE)
                    ->get();

                if ($imeis->isNotEmpty()) {
                    // Enregistrer le mouvement d'annulation
                    $stockSource = StockSuccursale::where('succursale_id', $approvisionnement->source_decidee_id)
                        ->where('produit_id', $ligne->produit_id)
                        ->first();

                    $mouvement = MouvementStock::create([
                        'reference_mouvement' => MouvementStock::genererReference(),
                        'succursale_id' => $approvisionnement->source_decidee_id,
                        'produit_id' => $ligne->produit_id,
                        'stock_source_id' => null,
                        'stock_destination_id' => $stockSource ? $stockSource->id : null,
                        'demande_parent_id' => $approvisionnement->id,
                        'type_mouvement' => 'ANNULATION',
                        'statut' => 'VALIDE',
                        'quantite' => $imeis->count(),
                        'quantite_avant' => $stockSource ? $stockSource->quantite_disponible : 0,
                        'quantite_apres' => $stockSource ? $stockSource->quantite_disponible + $imeis->count() : $imeis->count(),
                        'cout_unitaire' => $ligne->produit->prix_achat ?? 0,
                        'cout_total' => ($ligne->produit->prix_achat ?? 0) * $imeis->count(),
                        'responsable_id' => auth()->id(),
                        'valide_par_id' => auth()->id(),
                        'date_mouvement' => now(),
                        'date_validation' => now(),
                        'raison' => "Annulation transfert pour approvisionnement #{$approvisionnement->reference}",
                        'metadata' => [
                            'type' => 'ANNULATION_TRANSFERT_IMEI',
                            'imei_ids' => $imeis->pluck('id')->toArray(),
                            'imei_numbers' => $imeis->pluck('numero_imei')->toArray(),
                            'ligne_id' => $ligne->id
                        ]
                    ]);

                    // Annuler le transfert
                    foreach ($imeis as $imei) {
                        $imei->annulerTransfert();
                    }

                    // Restaurer le stock source
                    if ($stockSource) {
                        $stockSource->quantite_disponible += $imeis->count();
                        $stockSource->save();
                    }
                }

                // Réinitialiser la ligne
                $ligne->quantite_envoyee = 0;
                $ligne->quantite_recue = 0;
                $ligne->save();
            }

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Gérer les écarts IMEI pour une ligne
     */
    protected function gererEcartsLigne(LigneApprovisionnement $ligne, $imeis, array $ecarts)
    {
        $imeisParId = $imeis->keyBy('id');
        $imeisRecus = collect();
        $ecartsTraites = [];

        foreach ($ecarts as $ecart) {
            $imeiId = $ecart['imei_id'];
            $typeEcart = $ecart['type']; // CASSE, PERDU, NON_CONFORME
            $motif = $ecart['motif'] ?? null;

            if (!isset($imeisParId[$imeiId])) {
                throw new \Exception("IMEI ID {$imeiId} non trouvé pour cette ligne");
            }

            $imei = $imeisParId[$imeiId];
            $statutEcart = $this->mapStatutEcart($typeEcart);

            // Enregistrer le mouvement d'écart
            $this->enregistrerMouvementEcart(
                $ligne->approvisionnement,
                $ligne,
                $imei,
                $typeEcart,
                $motif
            );

            // Marquer l'IMEI comme écart
            $imei->marquerEcart($statutEcart, [
                'ligne_approvisionnement_id' => $ligne->id,
                'motif' => $motif
            ]);

            $ecartsTraites[] = [
                'imei_id' => $imeiId,
                'imei_number' => $imei->numero_imei,
                'type' => $typeEcart,
                'motif' => $motif,
                'statut' => $statutEcart
            ];

            // Ne pas inclure cet IMEI dans la réception
            unset($imeisParId[$imeiId]);
        }

        // Les IMEI restants sont reçus normalement
        return [
            'recus' => $imeisParId,
            'ecarts' => $ecartsTraites
        ];
    }

    /**
     * Vérifier la cohérence des IMEI avant validation
     */
    public function verifierCohérenceImeis(Approvisionnement $approvisionnement): array
    {
        $erreurs = [];

        foreach ($approvisionnement->lignes as $ligne) {
            if (!$this->estProduitSerialise($ligne->produit)) {
                continue;
            }

            $imeiIds = $this->getImeisLigne($ligne);
            $imeis = ProduitImei::whereIn('id', $imeiIds)
                ->where('produit_id', $ligne->produit_id)
                ->get();

            // Vérifier que tous les IMEI appartiennent au produit
            if ($imeis->count() !== count($imeiIds)) {
                $erreurs[] = "Ligne {$ligne->id}: Certains IMEI n'appartiennent pas au produit";
            }

            // Vérifier les doublons
            $doublons = $imeis->duplicates('numero_imei');
            if ($doublons->isNotEmpty()) {
                $erreurs[] = "Ligne {$ligne->id}: Doublons IMEI détectés: " . $doublons->implode(', ');
            }

            // Vérifier que les IMEI sont bien dans la succursale source
            $mauvaisesSuccursales = $imeis->where('succursale_id', '!=', $approvisionnement->source_decidee_id);
            if ($mauvaisesSuccursales->isNotEmpty()) {
                $erreurs[] = "Ligne {$ligne->id}: Certains IMEI ne sont pas dans la succursale source";
            }

            // Vérifier les statuts
            $mauvaisStatuts = $imeis->whereNotIn('statut', [ProduitImei::STATUT_EN_STOCK, ProduitImei::STATUT_RESERVE]);
            if ($mauvaisStatuts->isNotEmpty()) {
                $erreurs[] = "Ligne {$ligne->id}: Certains IMEI ne sont pas disponibles";
            }
        }

        return $erreurs;
    }

    /**
     * Vérifier la cohérence des IMEI à l'arrivée intermédiaire
     */
    public function verifierImeisArriveeIntermediaire(Approvisionnement $approvisionnement): array
    {
        $erreurs = [];

        foreach ($approvisionnement->lignes as $ligne) {
            if (!$this->estProduitSerialise($ligne->produit)) {
                continue;
            }

            $imeiIds = $this->getImeisLigne($ligne);

            if (empty($imeiIds)) {
                $erreurs[] = "Ligne {$ligne->id}: Aucun IMEI reçu au point intermédiaire";
                continue;
            }

            $imeis = ProduitImei::whereIn('id', $imeiIds)
                ->where('produit_id', $ligne->produit_id)
                ->where('succursale_id', $approvisionnement->intermediaire_succursale_id)
                ->where('statut', ProduitImei::STATUT_TRANSFERE)
                ->where('ligne_approvisionnement_id', $ligne->id)
                ->get();

            if ($imeis->count() !== count($imeiIds)) {
                $trouves = $imeis->pluck('id')->toArray();
                $manquants = array_diff($imeiIds, $trouves);
                $erreurs[] = "Ligne {$ligne->id}: Certains IMEI ne sont pas arrivés: " . implode(', ', $manquants);
            }
        }

        return $erreurs;
    }

    /**
     * Vérifier la cohérence des IMEI au départ intermédiaire
     */
    public function verifierImeisDepartIntermediaire(Approvisionnement $approvisionnement): array
    {
        $erreurs = [];

        foreach ($approvisionnement->lignes as $ligne) {
            if (!$this->estProduitSerialise($ligne->produit)) {
                continue;
            }

            $imeiIds = $this->getImeisLigne($ligne);

            if (empty($imeiIds)) {
                $erreurs[] = "Ligne {$ligne->id}: Aucun IMEI au départ du point intermédiaire";
                continue;
            }

            $imeis = ProduitImei::whereIn('id', $imeiIds)
                ->where('produit_id', $ligne->produit_id)
                ->where('succursale_id', $approvisionnement->intermediaire_succursale_id)
                ->where('statut', ProduitImei::STATUT_TRANSFERE)
                ->where('ligne_approvisionnement_id', $ligne->id)
                ->get();

            if ($imeis->count() !== count($imeiIds)) {
                $trouves = $imeis->pluck('id')->toArray();
                $manquants = array_diff($imeiIds, $trouves);
                $erreurs[] = "Ligne {$ligne->id}: Certains IMEI sont manquants: " . implode(', ', $manquants);
            }

            // Vérifier qu'aucun IMEI n'a disparu (même nombre qu'à l'arrivée)
            $arriveeImeis = ProduitImei::where('ligne_approvisionnement_id', $ligne->id)
                ->where('produit_id', $ligne->produit_id)
                ->where('statut', ProduitImei::STATUT_TRANSFERE)
                ->where('succursale_id', $approvisionnement->intermediaire_succursale_id)
                ->count();

            if ($arriveeImeis != count($imeiIds)) {
                $erreurs[] = "Ligne {$ligne->id}: Nombre d'IMEI incohérent. Arrivée: {$arriveeImeis}, Départ: " . count($imeiIds);
            }
        }

        return $erreurs;
    }

    /**
     * Vérifier la cohérence des IMEI à la réception finale
     */
    public function verifierImeisReceptionFinale(Approvisionnement $approvisionnement): array
    {
        $erreurs = [];

        foreach ($approvisionnement->lignes as $ligne) {
            if (!$this->estProduitSerialise($ligne->produit)) {
                continue;
            }

            $imeiIds = $this->getImeisLigne($ligne);

            if (empty($imeiIds)) {
                $erreurs[] = "Ligne {$ligne->id}: Aucun IMEI reçu à destination";
                continue;
            }

            $imeis = ProduitImei::whereIn('id', $imeiIds)
                ->where('produit_id', $ligne->produit_id)
                ->where('succursale_id', $approvisionnement->destination_succursale_id)
                ->where('statut', ProduitImei::STATUT_TRANSFERE)
                ->where('ligne_approvisionnement_id', $ligne->id)
                ->get();

            if ($imeis->count() !== count($imeiIds)) {
                $trouves = $imeis->pluck('id')->toArray();
                $manquants = array_diff($imeiIds, $trouves);
                $erreurs[] = "Ligne {$ligne->id}: Certains IMEI ne sont pas reçus: " . implode(', ', $manquants);
            }
        }

        return $erreurs;
    }

    // ============================================================
    // MÉTHODES UTILITAIRES
    // ============================================================

    protected function estProduitSerialise($produit)
    {
        return $produit && in_array($produit->type, ['TELEPHONE', 'ELECTRONIQUE']);
    }

    protected function stockerImeisLigne(LigneApprovisionnement $ligne, array $imeiIds)
    {
        $key = "imeis_ligne_{$ligne->id}";
        session()->put($key, $imeiIds);
    }

    protected function getImeisLigne(LigneApprovisionnement $ligne): array
    {
        $key = "imeis_ligne_{$ligne->id}";
        return session()->get($key, []);
    }

    protected function mapStatutEcart($type)
    {
        $map = [
            'CASSE' => ProduitImei::STATUT_CASSE,
            'PERDU' => ProduitImei::STATUT_PERDU,
            'NON_CONFORME' => ProduitImei::STATUT_NON_CONFORME
        ];
        
        return $map[$type] ?? ProduitImei::STATUT_NON_CONFORME;
    }
}