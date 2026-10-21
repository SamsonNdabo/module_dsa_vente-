<?php

namespace App\Services;

use App\Models\Receipt;
use App\Models\ReceiptConfig;
use App\Models\OperationChange;
use App\Services\QrCodeService;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReceiptService
{
    protected ReceiptConfig $config;

    public function __construct()
    {
        $this->config = ReceiptConfig::getConfig();
    }

    /**
     * Génère un reçu après validation d'une opération de change.
     */
    public function generate(OperationChange $operation): Receipt
    {
    Log::info('=== DEBUT generate ===', ['operation_id' => $operation->id]);
        DB::beginTransaction();
        try {
            // Déterminer le type d'opération
            $typeOperation = $this->determineOperationType($operation);
            $deviseRemise = $typeOperation === 'ACHAT' ? $operation->deviseSource : $operation->deviseCible;
            $deviseRecue = $typeOperation === 'ACHAT' ? $operation->deviseCible : $operation->deviseSource;
            $montantRemis = $typeOperation === 'ACHAT' ? $operation->montant_source : $operation->montant_cible;
            $montantRecu = $typeOperation === 'ACHAT' ? $operation->montant_cible : $operation->montant_source;

            // Charger les relation
            $operation->load('operateur');
            $operation->load('caisse');
            $operation->load('succursale');
            $operation->load('client');

            // Récupérer la caisse et l'agence
            $caisse = $operation->caisse?->parent_nom;
            $guichetier = $operation->operateur?->nom_complet;

            $receipt = Receipt::create([
                'reference'              => Receipt::genererReference(),
                'reference_change'       => $operation->reference,
                'operation_change_id'    => $operation->id,
                'statut'                 => $operation->statut === 'COMPLETEE' ? 'REUSSIE' : 'ANNULEE',
                'type_operation'         => $typeOperation,
                'client_id'              => $operation->client_id,
                'client_nom'             => $operation->client?->nom_complet ?? 'Client non renseigné',
                'client_telephone'       => $operation->client?->telephone ?? '',
                'devise_remise'          => $deviseRemise->code ?? 'Inconnu',
                'devise_remise_montant'  => $montantRemis,
                'devise_recue'           => $deviseRecue->code ?? 'Inconnu',
                'devise_recue_montant'   => $montantRecu,
                'taux_achat'             => $operation->taux_achat,
                'taux_vente'             => $operation->taux_vente,
                'taux_affichage'         => $this->formatTaux($operation),
                'commission'             => $operation->commission ?? 0,
                'frais'                  => 0,
                'montant_net'            => $montantRecu,
                'guichetier_nom'         => $guichetier ?? 'Inconnu',
                'caisse_nom'             => $caisse ?? 'Caisse inconnue',
                'agence_nom'             => $operation->succursale?->nom ?? 'Agence inconnue',
                'agence_adresse'         => $operation->succursale?->adresse ?? $this->config->adresse,
                'agence_telephone'       => $operation->succursale?->telephone ?? $this->config->telephone,
                'est_duplicata'          => false,
                'date_impression'        => now(),
            ]);

            // Génération du QR code
            $qrService = new QrCodeService();
            $qrData = $this->buildQrData($receipt);
            $qrCode = $qrService->generate($qrData);

            // Mettre à jour le reçu avec le QR code
            $receipt->qr_code = $qrCode;
            $receipt->save();

        Log::info('Reçu créé', ['receipt_id' => $receipt->id, 'reference' => $receipt->reference]);

        // Générer le PDF et enregistrer le chemin
        $pdfPath = $this->generatePdf($receipt);
        Log::info('PDF généré', ['path' => $pdfPath]);

        $receipt->pdf_path = $pdfPath;
        $receipt->save();
        Log::info('Chemin PDF sauvegardé', ['pdf_path' => $receipt->pdf_path]);

        DB::commit();
        Log::info('=== FIN generate ===');
        return $receipt;
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('ERREUR generate', [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        throw $e;
        }
    }

    /**
     * Génère un duplicata d'un reçu existant.
     */
    public function generateDuplicata(Receipt $original): Receipt
    {
        $duplicata = $original->replicate();
        $duplicata->reference = Receipt::genererReference();
        $duplicata->est_duplicata = true;
        $duplicata->original_receipt_id = $original->id;
        $duplicata->date_impression = now();
        $duplicata->save();

        // Générer le PDF pour le duplicata
        $pdfPath = $this->generatePdf($duplicata);
        $duplicata->pdf_path = $pdfPath;
        $duplicata->save();

        return $duplicata;
    }

    /**
     * Annule un reçu (lors d'une contrepassation).
     */
    public function cancel(Receipt $receipt, string $motif): Receipt
    {
        $receipt->statut = 'ANNULEE';
        $receipt->save();

        // Générer un nouveau reçu de contrepassation
        $counter = $receipt->replicate();
        $counter->reference = Receipt::genererReference();
        $counter->statut = 'CONTRESSEE';
        $counter->save();

        return $counter;
    }

    /**
     * Détermine si l'opération est un achat ou une vente pour le client.
     */
    private function determineOperationType(OperationChange $operation): string
    {
        // Si le client donne CDF et reçoit USD/EUR => ACHAT
        $cdfId = \App\Models\Devise::getIdByCode('CDF');
        if ($operation->devise_source_id == $cdfId) {
            return 'ACHAT';
        }
        return 'VENTE';
    }

    /**
     * Formate l'affichage du taux.
     */
    private function formatTaux(OperationChange $operation): string
    {
        $source = $operation->deviseSource->code ?? '?';
        $cible = $operation->deviseCible->code ?? '?';
        $taux = $operation->taux_vente_achat ?? $operation->taux_achat ?? 0;
        $tauxVente = $operation->taux_vente_froce ?? $operation->taux_vente ?? 0;

        if ($operation->sens == 'inverse') {
            return "1 {$source} = " . number_format($taux, 2) . " {$cible}";
        } else
        return  number_format($taux, 2) . " {$cible} = 1 {$source}";
    }

    private function buildQrData(Receipt $receipt): string
    {
        $baseUrl = config('app.verify_url', config('app.url'));
        return rtrim($baseUrl, '/') . '/' . $receipt->reference;
    }

    /**
     * Génère le fichier PDF et retourne son chemin relatif
     */
public function generatePdf(Receipt $receipt): string
{
    Log::info('=== DEBUT generatePdf ===', ['reference' => $receipt->reference]);

    $config = ReceiptConfig::getConfig();
    Log::info('Config chargée', ['config' => $config->toArray()]);

    $pdf = PDF::loadView('receipts.print', compact('receipt', 'config'));
    Log::info('Vue chargée');

    // Chemin uniforme : toujours dans receipts/
    $filename = 'receipts/' . $receipt->reference . '.pdf';
    $path = storage_path('app/public/' . $filename);
    Log::info('Chemin du PDF', ['path' => $path]);

    Log::info('Sauvegarde du PDF...');
    \Log::info('URL du logo', ['logo_url' => $config->logo_url]);
    $pdf->save($path);
    Log::info('PDF sauvegardé', ['size' => filesize($path)]);

    Log::info('=== FIN generatePdf ===');
    return $filename;
}

}