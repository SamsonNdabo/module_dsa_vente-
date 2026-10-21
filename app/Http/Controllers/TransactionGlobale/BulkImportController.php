<?php

namespace App\Http\Controllers\TransactionGlobale;

use App\Http\Controllers\Controller;
use App\Models\Caisses;
use App\Models\Devise;
use App\Models\Operateur_Mobile;
use App\Models\OperateursInternational;
use App\Models\Seuil_Transactionnels;
use App\Models\Transaction_Mobile;
use App\Models\TransfertInternational;
use App\Models\TransfertBancaire;
use App\Models\Transaction_Globale;
use App\Models\Commission;
use App\Models\Utilisateur;
use App\Models\ExchangeRate;
use App\Services\EcritureService;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class BulkImportController extends Controller
{

    public function importMobileMoney(Request $request)
    {
        $user = auth()->id();
        Log::info('Recuperation de l\'id utilisateur '.$user);
        $request->validate([
            'fichier' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ], [
            "fichier.file" => "Le champ doit être un fichier.",
            "fichier.mimes" => "Le fichier doit être au format xlsx, xls ou csv.",
            "fichier.max" => "Le fichier ne doit pas dépasser 10 Mo.",
        ]);

        $fichier = $request->file('fichier');

        try {
            $spreadsheet = IOFactory::load($fichier->getPathname());
            $worksheet = $spreadsheet->getActiveSheet();
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Fichier illisible : ' . $e->getMessage()], 422);
        }

        // Préchargement des données de référence (fusion nom + code normalisés)
        $operateursMobileMap = operateur_mobile::with('serviceMobileMoney')->get()
            ->flatMap(function ($item) {
                $nom = $this->normalizeKey($item->nom_operateur);
                $code = $this->normalizeKey($item->code_operateur);
                return [
                    $nom => $item,
                    $code => $item,
                ];
            });

        $operateursIntMap = OperateursInternational::all()
            ->flatMap(function ($item) {
                $nom = $this->normalizeKey($item->nom_operateur);
                $code = $this->normalizeKey($item->code_operateur);
                return [
                    $nom => $item,
                    $code => $item,
                ];
            });

        $devises = Devise::where('statut', 1)->get()->keyBy(function ($item) {
            return strtolower($item->code);
        });

        $caissesPrincipales = Caisses::with(['enfants', 'permissions'])
            ->where('categorie', 'PRINCIPALE')
            ->where('inactif', 0)
            ->get()
            ->keyBy(function ($item) {
                return strtolower($item->nom_caisse);
            });

        $results = [];
        $headers = [];
        $firstRow = true;

        DB::beginTransaction();

        try {
            foreach ($worksheet->getRowIterator() as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);
                $rowData = [];
                foreach ($cellIterator as $cell) {
                    $rowData[] = $cell->getValue();
                }

                if ($firstRow) {
                    $headers = array_map('trim', $rowData);
                    $firstRow = false;
                    continue;
                }

                $nonEmpty = array_filter($rowData, fn($v) => !empty(trim((string)$v)));
                if (empty($nonEmpty)) {
                    continue;
                }

                $data = array_combine($headers, $rowData);
                if ($data === false) {
                    $results[] = $this->error('Ligne mal formatée.', $row->getRowIndex());
                    continue;
                }

                if (empty(trim((string)($data['numero_client'] ?? '')))) {
                    continue;
                }

                $cle = $this->normalizeKey($data['operateur']);

                $operateurMobile = $operateursMobileMap->get($cle);
                $operateurInt = $operateursIntMap->get($cle);

                if ($operateurMobile) {
                    $results[] = $this->processMobileRow(
                        $data,
                        $row->getRowIndex() - 1,
                        $operateurMobile,
                        $devises,
                        $caissesPrincipales,
                        $user
                    );
                } elseif ($operateurInt) {
                    if ($operateurInt->international) {
                        $results[] = $this->processInternationalRow(
                            $data,
                            $row->getRowIndex() - 1,
                            $operateurInt,
                            $devises,
                            $caissesPrincipales,
                            $user
                        );
                    } else {
                        $results[] = $this->processBancaireRow(
                            $data,
                            $row->getRowIndex() - 1,
                            $operateurInt,
                            $devises,
                            $caissesPrincipales,
                            $user
                        );
                    }
                } else {
                    $results[] = $this->error("Opérateur '{$data['operateur']}' introuvable.", $row->getRowIndex() - 1);
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Échec de l\'import : ' . $e->getMessage()], 500);
        }

        $successCount = collect($results)->where('status', 'success')->count();
        $errorCount   = collect($results)->where('status', 'error')->count();

        return response()->json([
            'success' => true,
            'message' => "Import terminé : $successCount réussis, $errorCount erreurs.",
            'data'    => $results,
        ]);
    }

    /**
     * Normalise une chaîne pour la recherche insensible à la casse, aux espaces et aux accents
     */
    private function normalizeKey(string $value): string
    {
        $value = trim($value);
        $value = iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]/', '', $value);
        return $value;
    }

    /**
     * Traite une ligne d'import pour une transaction MOBILE MONEY.
     *
     * Ordre corrigé :
     *   1. Validations
     *   2. Création de Transaction_Globale
     *   3. Création de Transaction_Mobile  ← AVANT la commission
     *   4. Appel à Commission::creerPourTransaction
     *   5. Mouvements de caisse
     */
    private function processMobileRow(
        array $data,
        int $rowNumber,
        $operateur,
        $devises,
        $caissesPrincipales,
        int $creator
    ): array {
        try {
            // ── 1. Champs obligatoires ──────────────────────────────
            $required = ['operateur', 'numero_client', 'nom_client', 'type_operation', 'montant', 'devise', 'caisse_principale', 'operateurID'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return $this->error("Champ '$field' manquant ou vide.", $rowNumber);
                }
            }

            // ── 2. Référence & doublons ─────────────────────────────
            $reference = trim($data['operateurID']);
            if ($reference === '') {
                return $this->error("operateurID vide.", $rowNumber);
            }
            if (Transaction_Globale::where('reference', $reference)->exists()) {
                return $this->error("Référence '$reference' existe déjà dans transaction_globale.", $rowNumber);
            }
            if (Transaction_Mobile::where('transactionid', $reference)->exists()) {
                return $this->error("Référence '$reference' existe déjà dans transaction_mobile.", $rowNumber);
            }

            // ── 3. Type d'opération ─────────────────────────────────
            $typeOperation = strtoupper(trim($data['type_operation']));
            if (!in_array($typeOperation, ['DEPOT', 'RETRAIT'], true)) {
                return $this->error("Type d'opération invalide (DEPOT ou RETRAIT).", $rowNumber);
            }

            // ── 4. Opérateur mobile ─────────────────────────────────
            if (empty($operateur->service_mobile_money_id)) {
                return $this->error("L'opérateur {$operateur->nom_operateur} n'a pas de service mobile money associé.", $rowNumber);
            }

            // ── 5. Devise ───────────────────────────────────────────
            $codeDevise = strtoupper(trim($data['devise']));
            $devise = $devises->get(strtolower($codeDevise));
            if (!$devise) {
                return $this->error("Devise '{$data['devise']}' introuvable.", $rowNumber);
            }
            $deviseId = $devise->id;

            // ── 6. Caisse principale ────────────────────────────────
            $nomCaisse = strtolower(trim($data['caisse_principale']));
            $caissePrincipale = $caissesPrincipales->get($nomCaisse);
            if (!$caissePrincipale) {
                $caissePrincipale = Caisses::where('code', $data['caisse_principale'])
                    ->where('categorie', 'PRINCIPALE')
                    ->where('inactif', 0)
                    ->first();
                if (!$caissePrincipale) {
                    return $this->error("Caisse principale '{$data['caisse_principale']}' introuvable.", $rowNumber);
                }
            }
            if ($caissePrincipale->statut !== 'OUVERTE') {
                return $this->error("La caisse principale {$caissePrincipale->nom_caisse} n'est pas ouverte.", $rowNumber);
            }
            if (!$caissePrincipale->peut('MOBILE_MONEY')) {
                return $this->error("La caisse principale {$caissePrincipale->nom_caisse} n'a pas la permission Mobile Money.", $rowNumber);
            }

            // ── 7. Caisses secondaires ──────────────────────────────
            $caissePhysique = $caissePrincipale->secondairePhysique();
            if (!$caissePhysique) {
                return $this->error("Aucune caisse secondaire physique pour {$caissePrincipale->nom_caisse}.", $rowNumber);
            }

            $caisseMobile = $caissePrincipale->secondaireMobileMoney($operateur->service_mobile_money_id);
            if (!$caisseMobile) {
                return $this->error("Aucune caisse mobile money pour l'opérateur {$operateur->nom_operateur}.", $rowNumber);
            }
            if ($caisseMobile->statut !== 'OUVERTE') {
                return $this->error("La caisse mobile money {$caisseMobile->nom_caisse} n'est pas ouverte.", $rowNumber);
            }

            // ── 8. Montant & seuil ──────────────────────────────────
            $montant      = (float) $data['montant'];
            $succursaleId = $caissePrincipale->succursale_id;

            $seuil = Seuil_Transactionnels::detecter('MOBILE_MONEY', $montant, $deviseId, auth()->user()->role_id);
            if (!$seuil) {
                return $this->error('Aucun seuil configuré pour ce type de transaction.', $rowNumber);
            }

            // ── 9. Persistance atomique ─────────────────────────────
            DB::beginTransaction();
            try {
                // 9.1 — Transaction globale
                $transactionGlobale = Transaction_Globale::create([
                    'reference'            => $reference,
                    'type'                 => $typeOperation === 'DEPOT' ? 'MOBILE_MONEY' : 'MOBILE_MONEY_RETRAIT',
                    'montant_local'        => $montant,
                    'devise_id'            => $deviseId,
                    'date_transaction'     => now()->format('Y-m-d'),
                    'statut'               => 'COMPLETEE',
                    'commission_starehe'   => 0,
                    'id_succursale'        => $succursaleId,
                    'id_creator'           => $creator,
                    'caisse_id'            => $caissePhysique->id,
                    'caisse_mobile_id'     => $caisseMobile->id,
                    'id_seuil_transaction' => $seuil->id,
                    'montant_devise_pivot' => $montant,
                ]);

                // 9.2 — Transaction mobile (AVANT l'appel à la commission)
                $referenceMobile = $data['reference_transaction']
                    ?? Transaction_Mobile::genererReference($operateur->code_operateur);

                $transaction = Transaction_Mobile::create([
                    'id_transaction_global' => $transactionGlobale->id,
                    'id_operateur_mobile'   => $operateur->id,
                    'reference_transaction' => $referenceMobile,
                    'id_creator'            => $creator,
                    'numero_client'         => $data['numero_client'],
                    'nom_client'            => $data['nom_client'],
                    'type_operation'        => $typeOperation,
                    'montant'               => $montant,
                    'devise_id'             => $deviseId,
                    'caisse_id'             => $caissePhysique->id,
                    'commission_operateur'  => null,
                    'montant_net'           => $montant,
                    'statut_transaction'    => 'COMPLETEE',
                    'transactionid'         => $reference,
                ]);

                // 9.3 — Commission : maintenant la Transaction_Mobile existe
                $commission = Commission::creerPourTransaction($transactionGlobale, 'PAYEE');

                // 9.4 — Mouvements de caisse
                if ($typeOperation === 'DEPOT') {
                    if (!$caisseMobile->debiter($montant, $deviseId, "Débit mobile money", $reference, auth()->id())) {
                        throw new \RuntimeException("Échec du débit de la caisse mobile #{$caisseMobile->id} ({$caisseMobile->nom_caisse}) - solde insuffisant ou caisse non trouvée");
                    }
                    if (!$caissePhysique->crediter($montant, $deviseId, "Crédit physique", $reference, auth()->id())) {
                        throw new \RuntimeException("Échec du crédit de la caisse physique #{$caissePhysique->id} ({$caissePhysique->nom_caisse}) - plafond dépassé ou caisse non opérable");
                    }
                } else {
                    if (!$caissePhysique->debiter($montant, $deviseId, "Débit physique", $reference, auth()->id())) {
                        throw new \RuntimeException("Échec du débit de la caisse physique #{$caissePhysique->id} ({$caissePhysique->nom_caisse}) - solde insuffisant ou caisse non trouvée");
                    }
                    if (!$caisseMobile->crediter($montant, $deviseId, "Crédit mobile money", $reference, auth()->id())) {
                        throw new \RuntimeException("Échec du crédit de la caisse mobile #{$caisseMobile->id} ({$caisseMobile->nom_caisse}) - plafond dépassé ou caisse non opérable");
                    }
                }

                DB::commit();

                return [
                    'status'  => 'success',
                    'row'     => $rowNumber,
                    'message' => 'OK',
                    'id'      => $transaction->id,
                    'type'    => 'MOBILE',
                ];
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), $rowNumber);
        }
    }

    /**
     * Traite une ligne d'import pour un TRANSFERT BANCAIRE.
     *
     * Ordre conservé et sécurisé :
     *   1. Validations
     *   2. Création de TransfertBancaire
     *   3. Création de Transaction_Globale
     *   4. Liaison TransfertBancaire ↔ Transaction_Globale
     *   5. Appel à Commission::creerPourTransaction
     *   6. Mouvements de caisse
     */
    private function processBancaireRow(
        array $data,
        int $rowNumber,
        $operateurInt,
        $devises,
        $caissesPrincipales
    ): array {
        try {
            // ── 1. Champs obligatoires ──────────────────────────────
            $required = ['operateur', 'numero_client', 'nom_client', 'type_operation', 'montant', 'devise', 'caisse_principale', 'operateurID'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return $this->error("Champ '$field' manquant ou vide.", $rowNumber);
                }
            }

            // ── 2. Type d'opération ─────────────────────────────────
            $typeOperation = strtoupper(trim($data['type_operation']));
            if (!in_array($typeOperation, ['DEPOT', 'RETRAIT'], true)) {
                return $this->error("Type d'opération invalide (DEPOT ou RETRAIT).", $rowNumber);
            }

            // ── 3. Devise ───────────────────────────────────────────
            $codeDevise = strtoupper(trim($data['devise']));
            $devise = $devises->get(strtolower($codeDevise));
            if (!$devise) {
                return $this->error("Devise '{$data['devise']}' introuvable.", $rowNumber);
            }
            $deviseId = $devise->id;

            // ── 4. Caisse principale ────────────────────────────────
            $nomCaisse = strtolower(trim($data['caisse_principale']));
            $caissePrincipale = $caissesPrincipales->get($nomCaisse);
            if (!$caissePrincipale) {
                $caissePrincipale = Caisses::where('code', $data['caisse_principale'])
                    ->where('categorie', 'PRINCIPALE')
                    ->where('inactif', 0)
                    ->first();
                if (!$caissePrincipale) {
                    return $this->error("Caisse principale '{$data['caisse_principale']}' introuvable.", $rowNumber);
                }
            }
            if ($caissePrincipale->statut !== 'OUVERTE') {
                return $this->error("La caisse principale {$caissePrincipale->nom_caisse} n'est pas ouverte.", $rowNumber);
            }
            if (!$caissePrincipale->peut('TRANSFERT_INTERNATIONAL')) {
                return $this->error("La caisse principale {$caissePrincipale->nom_caisse} n'a pas la permission Transfert International.", $rowNumber);
            }

            // ── 5. Caisses secondaires ──────────────────────────────
            $caissePhysique = $caissePrincipale->secondairePhysique();
            if (!$caissePhysique) {
                return $this->error("Aucune caisse secondaire PHYSIQUE pour {$caissePrincipale->nom_caisse}.", $rowNumber);
            }

            $caisseBancaire = $caissePrincipale->secondaireBancaire($operateurInt->service_bancaire_id);
            if (!$caisseBancaire) {
                return $this->error("Aucune caisse secondaire BANCAIRE pour {$caissePrincipale->nom_caisse}.", $rowNumber);
            }

            // ── 6. Référence & doublons ─────────────────────────────
            $reference = trim($data['operateurID'] ?? '');
            if ($reference === '') {
                return $this->error("operateurID vide.", $rowNumber);
            }
            if (Transaction_Globale::where('reference', $reference)->exists()) {
                return $this->error("Référence '$reference' existe déjà dans transaction_globale.", $rowNumber);
            }
            if (TransfertInternational::where('reference_transfert', $reference)->exists()) {
                return $this->error("Référence '$reference' existe déjà dans transfert_international.", $rowNumber);
            }

            // ── 7. Montant, seuil & conversion ──────────────────────
            $montant      = (float) $data['montant'];
            $succursaleId = $caissePrincipale->succursale_id;

            $seuil = Seuil_Transactionnels::detecter('TRANSFERT_INTERNATIONAL', $montant, $deviseId, auth()->user()->role_id);
            if (!$seuil) {
                return $this->error('Aucun seuil configuré pour ce type de transaction.', $rowNumber);
            }

            $conversion = $operateurInt->convertir($deviseId, $deviseId, $montant);
            if ($conversion['sens'] === 'aucun_taux') {
                return $this->error('Aucun taux de change disponible pour ce transfert.', $rowNumber);
            }

            $frais = $typeOperation === 'DEPOT'
                ? $operateurInt->calculerFraisEnvoi($montant)
                : $operateurInt->calculerFraisReception($montant);
            $montantTotal = $montant + $frais;

            // ── 8. Persistance atomique ─────────────────────────────
            DB::beginTransaction();
            try {
                // 8.1 — TransfertBancaire (avant la transaction globale)
                $transfert = TransfertBancaire::create([
                    'id_transaction_globale' => null,
                    'id_operateur_bancaire'  => $operateurInt->id,
                    'reference_transfert'    => $reference,
                    'id_creator'             => auth()->id(),
                    'caisse_id'              => $caisseBancaire->id,
                    'succursale_id'          => $succursaleId,
                    'type_operation'         => $typeOperation,
                    'montant'                => $montant,
                    'devise_id'              => $deviseId,
                    'statut'                 => 'PAYE',
                    'description'            => "Transfert bancaire {$typeOperation} via {$operateurInt->nom_operateur} pour le client {$data['nom_client']} ({$data['numero_client']})",
                ]);

                // 8.2 — Transaction globale
                $transactionGlobale = Transaction_Globale::create([
                    'reference'                  => $reference,
                    'type'                       => $typeOperation === 'DEPOT' ? 'TRANSFERT_BANCAIRE' : 'TRANSFERT_BANCAIRE_RETRAIT',
                    'montant_local'              => $montant,
                    'devise_id'                  => $deviseId,
                    'date_transaction'           => now()->format('Y-m-d'),
                    'statut'                     => 'COMPLETEE',
                    'commission_starehe'         => 0,
                    'id_succursale'              => $succursaleId,
                    'id_creator'                 => auth()->id(),
                    'caisse_id'                  => $caissePrincipale->id,
                    'id_seuil_transaction'       => $seuil->id,
                    'montant_devise_pivot'       => $conversion['montant_converti'],
                    'transfert_international_id' => $transfert->id,
                    'transactionid'              => $reference,
                ]);

                // 8.3 — Liaison inverse
                $transfert->update(['id_transaction_globale' => $transactionGlobale->id]);

                // 8.4 — Commission (le TransfertBancaire est en base)
                $commission = Commission::creerPourTransaction($transactionGlobale, 'PAYEE');

                // 8.5 — Mouvements de caisse
                if ($typeOperation === 'DEPOT') {
                    if (!$caissePhysique->crediter($montant, $deviseId, "Crédit physique (envoi)", $reference, auth()->id())) {
                        throw new \RuntimeException("Échec crédit physique #{$caissePhysique->id} ({$caissePhysique->nom_caisse}) - plafond dépassé");
                    }
                    if (!$caisseBancaire->debiter($montant, $deviseId, "Débit bancaire (envoi)", $reference, auth()->id())) {
                        throw new \RuntimeException("Échec débit bancaire #{$caisseBancaire->id} ({$caisseBancaire->nom_caisse}) - solde insuffisant");
                    }
                } else {
                    if (!$caissePhysique->debiter($montant, $deviseId, "Débit physique (réception)", $reference, auth()->id())) {
                        throw new \RuntimeException("Échec débit physique #{$caissePhysique->id} ({$caissePhysique->nom_caisse}) - solde insuffisant");
                    }
                    if (!$caisseBancaire->crediter($montant, $deviseId, "Crédit bancaire (réception)", $reference, auth()->id())) {
                        throw new \RuntimeException("Échec crédit bancaire #{$caisseBancaire->id} ({$caisseBancaire->nom_caisse}) - plafond dépassé");
                    }
                }

                DB::commit();

                return [
                    'status'  => 'success',
                    'row'     => $rowNumber,
                    'message' => 'OK',
                    'id'      => $transfert->id,
                    'type'    => 'INTERNATIONAL',
                ];
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), $rowNumber);
        }
    }
    
    /**
     * Traite une ligne d'import pour un TRANSFERT INTERNATIONAL.
     *
     * Ordre corrigé et sécurisé :
     *   1. Validations
     *   2. Création de TransfertInternational
     *   3. Création de Transaction_Globale (avec transfert_international_id)
     *   4. Appel à Commission::creerPourTransaction
     *   5. Mouvements de caisse
     */
    private function processInternationalRow(
        array $data,
        int $rowNumber,
        $operateurInt,
        $devises,
        $caissesPrincipales
    ): array {
        try {
            // ── 1. Champs obligatoires ──────────────────────────────
            $required = ['operateur', 'numero_client', 'nom_client', 'type_operation', 'montant', 'devise', 'caisse_principale', 'operateurID'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return $this->error("Champ '$field' manquant ou vide.", $rowNumber);
                }
            }

            // ── 2. Type d'opération ─────────────────────────────────
            $typeOperation = strtoupper(trim($data['type_operation']));
            if (!in_array($typeOperation, [TransfertInternational::TYPE_ENVOI, TransfertInternational::TYPE_PAIEMENT], true)) {
                return $this->error("Type d'opération invalide (ENVOI ou PAIEMENT).", $rowNumber);
            }

            // ── 3. Devise ───────────────────────────────────────────
            $codeDevise = strtoupper(trim($data['devise']));
            $devise = $devises->get(strtolower($codeDevise));
            if (!$devise) {
                return $this->error("Devise '{$data['devise']}' introuvable.", $rowNumber);
            }
            $deviseId = $devise->id;

            // ── 4. Caisse principale ────────────────────────────────
            $nomCaisse = strtolower(trim($data['caisse_principale']));
            $caissePrincipale = $caissesPrincipales->get($nomCaisse);
            if (!$caissePrincipale) {
                $caissePrincipale = Caisses::where('code', $data['caisse_principale'])
                    ->where('categorie', 'PRINCIPALE')
                    ->where('inactif', 0)
                    ->first();
                if (!$caissePrincipale) {
                    return $this->error("Caisse principale '{$data['caisse_principale']}' introuvable.", $rowNumber);
                }
            }
            if ($caissePrincipale->statut !== 'OUVERTE') {
                return $this->error("La caisse principale {$caissePrincipale->nom_caisse} n'est pas ouverte.", $rowNumber);
            }
            if (!$caissePrincipale->peut('TRANSFERT_INTERNATIONAL')) {
                return $this->error("La caisse principale {$caissePrincipale->nom_caisse} n'a pas la permission Transfert International.", $rowNumber);
            }

            // ── 5. Caisses secondaires ──────────────────────────────
            $caissePhysique = $caissePrincipale->secondairePhysique();
            if (!$caissePhysique) {
                return $this->error("Aucune caisse secondaire PHYSIQUE pour {$caissePrincipale->nom_caisse}.", $rowNumber);
            }

            $caisseBancaire = $caissePrincipale->secondaireBancaire($operateurInt->service_bancaire_id);
            if (!$caisseBancaire) {
                return $this->error("Aucune caisse secondaire BANCAIRE pour {$caissePrincipale->nom_caisse}.", $rowNumber);
            }

            // ── 6. Référence & doublons ─────────────────────────────
            $reference = trim($data['operateurID'] ?? '');
            if ($reference === '') {
                return $this->error("operateurID vide.", $rowNumber);
            }
            if (Transaction_Globale::where('reference', $reference)->exists()) {
                return $this->error("Référence '$reference' existe déjà dans transaction_globale.", $rowNumber);
            }
            if (TransfertInternational::where('reference_transfert', $reference)->exists()) {
                return $this->error("Référence '$reference' existe déjà dans transfert_international.", $rowNumber);
            }

            // ── 7. Montant, seuil & conversion ──────────────────────
            $montant      = (float) $data['montant'];
            $succursaleId = $caissePrincipale->succursale_id;

            $seuil = Seuil_Transactionnels::detecter('TRANSFERT_INTERNATIONAL', $montant, $deviseId, auth()->user()->role_id);
            if (!$seuil) {
                return $this->error('Aucun seuil configuré pour ce type de transaction.', $rowNumber);
            }

            $conversion = $operateurInt->convertir($deviseId, $deviseId, $montant);
            if ($conversion['sens'] === 'aucun_taux') {
                return $this->error('Aucun taux de change disponible pour ce transfert.', $rowNumber);
            }

            $frais = $typeOperation === TransfertInternational::TYPE_ENVOI
                ? $operateurInt->calculerFraisEnvoi($montant)
                : $operateurInt->calculerFraisReception($montant);
            $montantTotal = $montant + $frais;

            // ── 8. Persistance atomique ─────────────────────────────
            DB::beginTransaction();
            try {
                // 8.1 — Transfert international (AVANT la commission)
                $transfert = TransfertInternational::create([
                    'reference_transfert'       => $reference,
                    'operateur_transfert_id'    => $operateurInt->id,
                    'type_operation'            => $typeOperation,
                    'montant_principal'         => $montant,
                    'devise_origine'            => $devise->code,
                    'devise_destination'        => $devise->code,
                    'montant_converti'          => $conversion['montant_converti'],
                    'taux_change'               => $conversion['taux_utilise'],
                    'frais_operateur'           => $frais,
                    'montant_total'             => $montantTotal,
                    'code_secret'               => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(8)),
                    'informations_expediteur'   => [
                        'nom'       => $data['nom_client'],
                        'telephone' => $data['numero_client'],
                        'adresse'   => $data['adresse'] ?? '',
                    ],
                    'informations_destinataire' => [
                        'nom'       => $data['nom_client'],
                        'telephone' => $data['numero_client'],
                        'adresse'   => $data['adresse'] ?? '',
                    ],
                    'piece_identite_type'       => $data['piece_identite_type'] ?? 'CNI',
                    'piece_identite_numero'     => $data['piece_identite_numero'] ?? 'N/A',
                    'statut_transfert'          => 'PAYE',
                    'date_envoi'                => now(),
                    'date_expiration'           => now()->addDays(30),
                    'succursale_id'             => $succursaleId,
                    'caisse_id'                 => $caisseBancaire->id,
                    'caissier_id'               => auth()->id(),
                    'superviseur_id'            => null,
                ]);

                // 8.2 — Transaction globale (référence vers le transfert)
                $transactionGlobale = Transaction_Globale::create([
                    'reference'                  => $reference,
                    'type'                       => $typeOperation === TransfertInternational::TYPE_ENVOI
                                                        ? 'TRANSFERT_INTERNATIONAL'
                                                        : 'TRANSFERT_INTERNATIONAL_PAIEMENT',
                    'montant_local'              => $montant,
                    'devise_id'                  => $deviseId,
                    'date_transaction'           => now()->format('Y-m-d'),
                    'statut'                     => 'COMPLETEE',
                    'commission_starehe'         => 0,
                    'id_succursale'              => $succursaleId,
                    'id_creator'                 => auth()->id(),
                    'caisse_id'                  => $caissePrincipale->id,
                    'id_seuil_transaction'       => $seuil->id,
                    'montant_devise_pivot'       => $conversion['montant_converti'],
                    'transfert_international_id' => $transfert->id,
                    'transactionid'              => $reference,
                ]);

                // 8.3 — Commission (le transfert international est en base)
                $commission = Commission::creerPourTransaction($transactionGlobale, 'PAYEE');

                // 8.4 — Mouvements de caisse
                if ($typeOperation === TransfertInternational::TYPE_ENVOI) {
                    if (!$caissePhysique->crediter($montant, $deviseId, "Crédit physique (envoi)", $reference, auth()->id())) {
                        throw new \RuntimeException("Échec crédit physique #{$caissePhysique->id} ({$caissePhysique->nom_caisse}) - plafond dépassé");
                    }
                    if (!$caisseBancaire->debiter($montant, $deviseId, "Débit bancaire (envoi)", $reference, auth()->id())) {
                        throw new \RuntimeException("Échec débit bancaire #{$caisseBancaire->id} ({$caisseBancaire->nom_caisse}) - solde insuffisant");
                    }
                } else {
                    if (!$caissePhysique->debiter($montant, $deviseId, "Débit physique (réception)", $reference, auth()->id())) {
                        throw new \RuntimeException("Échec débit physique #{$caissePhysique->id} ({$caissePhysique->nom_caisse}) - solde insuffisant");
                    }
                    if (!$caisseBancaire->crediter($montant, $deviseId, "Crédit bancaire (réception)", $reference, auth()->id())) {
                        throw new \RuntimeException("Échec crédit bancaire #{$caisseBancaire->id} ({$caisseBancaire->nom_caisse}) - plafond dépassé");
                    }
                }

                DB::commit();

                return [
                    'status'  => 'success',
                    'row'     => $rowNumber,
                    'message' => 'OK',
                    'id'      => $transfert->id,
                    'type'    => 'INTERNATIONAL',
                ];
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), $rowNumber);
        }
    }
    private function error(string $message, int $row): array
    {
        return ['status' => 'error', 'row' => $row, 'message' => $message];
    }

    /**
     * Vérifie si un operateurID existe déjà dans les tables concernées
     */
    private function operateurIDExiste(string $operateurID): bool
    {
        // Vérifier dans transaction_mobile (pour les mobiles)
        $existeMobile = Transaction_Mobile::where('transactionid', $operateurID)->exists();
    
        $existeGlobale = transaction_globale::where('reference', $operateurID)->exists();
        
        return $existeMobile || $existeGlobale;
    }

    public function download()
    {
        Log::info('Initialisation du chemin de téléchargement');
        $chemin = public_path('FichierExcel/test.xlsx');
        Log::info('Chemin : '.$chemin);
        if (!file_exists($chemin) || !is_file($chemin)) {
            return response()->json([
                'success' => false,
                'message' => 'Fichier introuvable.',
            ], 404);
        }

        $realPath = realpath($chemin);
        $allowedPath = realpath(public_path('FichierExcel'));
        if ($realPath === false || strpos($realPath, $allowedPath) !== 0) {
            return response()->json([
                'success' => false,
                'message' => 'Accès interdit.',
            ], 403);
        }

        return response()->download($realPath);
    }

}