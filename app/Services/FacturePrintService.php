<?php
// app/Services/FacturePrintService.php

namespace App\Services;

use App\Models\Facture;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FacturePrintService
{
    /**
     * Formats d'impression disponibles
     */
    const FORMAT_A4 = 'a4';
    const FORMAT_80MM = '80mm';
    const FORMAT_TICKET = 'ticket';

    /**
     * Types de sortie
     */
    const OUTPUT_HTML = 'html';
    const OUTPUT_PDF_STREAM = 'stream';
    const OUTPUT_PDF_DOWNLOAD = 'download';
    const OUTPUT_PDF_INLINE = 'inline';

    /**
     * Dimensions du ticket (95 mm de largeur)
     * DomPDF travaille en points (1 mm ≈ 2.83465 pt)
     * 95 mm = 95 * 2.83465 ≈ 269.29 pt
     */
    const TICKET_WIDTH_PT = 269.29;
    const TICKET_HEIGHT_PT = 2000; // Hauteur automatique (suffisamment longue)

    /**
     * Impression principale - délègue selon le format
     */
    public function imprimer(Facture $facture, string $format = self::FORMAT_A4, string $output = self::OUTPUT_HTML)
    {
        $this->validateFacture($facture);

        $data = $this->prepareData($facture);

        // Choisir la vue selon le format
        if ($format === self::FORMAT_80MM || $format === self::FORMAT_TICKET) {
            $view = 'factures.impression-80mm';
        } else {
            $view = 'factures.impression-a4';
        }

        // Générer la sortie demandée
        if ($output === self::OUTPUT_HTML) {
            return $this->renderHtml($view, $data);
        }

        if ($output === self::OUTPUT_PDF_STREAM) {
            return $this->renderPdfStream($view, $data);
        }

        if ($output === self::OUTPUT_PDF_DOWNLOAD) {
            return $this->renderPdfDownload($view, $data, $facture);
        }

        if ($output === self::OUTPUT_PDF_INLINE) {
            return $this->renderPdfInline($view, $data);
        }

        throw new \InvalidArgumentException("Format de sortie non supporté: {$output}");
    }

    /**
     * Impression HTML (A4)
     */
    public function imprimerA4(Facture $facture, string $output = self::OUTPUT_HTML)
    {
        return $this->imprimer($facture, self::FORMAT_A4, $output);
    }

    /**
     * Impression ticket 80mm
     */
    public function imprimerTicket(Facture $facture, string $output = self::OUTPUT_HTML)
    {
        return $this->imprimer($facture, self::FORMAT_80MM, $output);
    }

    /**
     * Génération PDF A4
     */
    public function pdfA4(Facture $facture, string $output = self::OUTPUT_PDF_STREAM)
    {
        return $this->imprimer($facture, self::FORMAT_A4, $output);
    }

    /**
     * Téléchargement PDF A4
     */
    public function downloadPdf(Facture $facture)
    {
        return $this->imprimer($facture, self::FORMAT_A4, self::OUTPUT_PDF_DOWNLOAD);
    }

    /**
     * Impression PDF inline
     */
    public function inlinePdf(Facture $facture)
    {
        return $this->imprimer($facture, self::FORMAT_A4, self::OUTPUT_PDF_INLINE);
    }

    /**
     * Valider que la facture est imprimable
     */
    protected function validateFacture(Facture $facture): void
    {
        if (!$facture->exists) {
            throw new \InvalidArgumentException('La facture n\'existe pas.');
        }

        if (in_array($facture->statut, ['BROUILLON', 'ANNULEE'])) {
            throw new \InvalidArgumentException("La facture est en statut '{$facture->statut}' et ne peut pas être imprimée.");
        }
    }

    /**
     * Préparer les données pour les vues
     */
    protected function prepareData(Facture $facture): array
    {
        // Charger toutes les relations nécessaires
        $facture->load([
            'client',
            'lignes.produit',
            'succursale',
            'utilisateur',
            'paiements',
            'devise',
            'caisse'
        ]);

        // Calculs à partir des données existantes
        $montantRegle = (float) $facture->montant_regle;
        $totalTTC = (float) $facture->total_ttc;
        $resteAPayer = max(0, $totalTTC - $montantRegle);

        // Informations de l'entreprise
        $entreprise = $facture->succursale?->entite ?? null;

        return [
            'facture' => $facture,
            'client' => $facture->client,
            'lignes' => $facture->lignes,
            'succursale' => $facture->succursale,
            'utilisateur' => $facture->utilisateur,
            'paiements' => $facture->paiements,
            'devise' => $facture->devise,
            'caisse' => $facture->caisse,
            'entreprise' => $entreprise,

            // Totaux déjà calculés
            'sous_total' => (float) $facture->sous_total,
            'remise_globale' => (float) $facture->remise_globale,
            'total_remise' => (float) $facture->total_remise,
            'total_ht' => (float) $facture->total_ht,
            'total_tva' => (float) $facture->total_tva,
            'total_ttc' => $totalTTC,
            'montant_regle' => $montantRegle,
            'reste_a_payer' => $resteAPayer,
            'taux_tva' => (float) ($facture->taux_tva ?? 16),

            // Métadonnées
            'date_impression' => now()->format('d/m/Y H:i:s'),
            // 'montant_en_lettres' => $this->convertirMontantEnLettres($totalTTC),

            // Logo (version base64 pour plus de fiabilité)
            'logo_url' => $this->getLogoBase64(),
            
            // QR Code
            'qr_code' => $this->getQrCodeBase64(),
            
            // Code-barres
            'code_barres' => $this->generateBarcode($facture),
        ];
    }

    /**
     * Rendre la vue HTML
     */
    protected function renderHtml(string $view, array $data): string
    {
        return View::make($view, $data)->render();
    }

    /**
     * Rendre le PDF en stream
     */
    protected function renderPdfStream(string $view, array $data)
    {
        $pdf = Pdf::loadView($view, $data);
        
        // 🔥 Configuration automatique du papier selon la vue
        $this->configurePdfPaper($pdf, $view);
        
        $pdf->setOptions([
            'defaultFont' => 'DejaVu Sans',
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => true,
            'isPhpEnabled' => true,
            'dpi' => 96,
            'defaultMediaType' => 'print',
        ]);

        return $pdf->stream();
    }

    /**
     * Rendre le PDF en téléchargement
     */
    protected function renderPdfDownload(string $view, array $data, Facture $facture)
    {
        $pdf = Pdf::loadView($view, $data);
        
        // 🔥 Configuration automatique du papier selon la vue
        $this->configurePdfPaper($pdf, $view);
        
        $pdf->setOptions([
            'defaultFont' => 'DejaVu Sans',
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => true,
            'isPhpEnabled' => true,
            'dpi' => 96,
            'defaultMediaType' => 'print',
        ]);

        $filename = sprintf(
            'facture_%s_%s.pdf',
            $facture->reference,
            now()->format('Ymd_His')
        );

        return $pdf->download($filename);
    }

    /**
     * Rendre le PDF en inline
     */
    protected function renderPdfInline(string $view, array $data)
    {
        $pdf = Pdf::loadView($view, $data);
        
        // 🔥 Configuration automatique du papier selon la vue
        $this->configurePdfPaper($pdf, $view);
        
        $pdf->setOptions([
            'defaultFont' => 'DejaVu Sans',
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => true,
            'isPhpEnabled' => true,
            'dpi' => 96,
            'defaultMediaType' => 'print',
        ]);

        return $pdf->inline();
    }

    /**
     * 🔥 Configurer le format papier selon la vue
     */
    protected function configurePdfPaper($pdf, string $view): void
    {
        if ($view === 'factures.impression-80mm') {
            // Ticket 95 mm de largeur
            $pdf->setPaper([
                0,
                0,
                self::TICKET_WIDTH_PT,
                self::TICKET_HEIGHT_PT
            ], 'portrait');
            return;
        }

        // Par défaut : A4
        $pdf->setPaper('a4', 'portrait');
    }

    /**
     * Convertir un montant en lettres
     */
    // protected function convertirMontantEnLettres(float $montant): string
    // {
    //     $entier = floor($montant);
    //     $centimes = round(($montant - $entier) * 100);
        
    //     $devise = $this->getDeviseLibelle() ?? 'Francs Congolais';
        
    //     $lettres = $this->nombreEnLettres($entier) . ' ' . $devise;
        
    //     if ($centimes > 0) {
    //         $lettres .= ' et ' . $this->nombreEnLettres($centimes) . ' centimes';
    //     }
        
    //     return ucfirst($lettres);
    // }

    /**
     * Convertir un nombre en lettres (version simplifiée)
     */
    // protected function nombreEnLettres(int $nombre): string
    // {
    //     $units = ['', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf'];
    //     $teens = ['dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf'];
    //     $tens = ['', 'dix', 'vingt', 'trente', 'quarante', 'cinquante', 'soixante', 'soixante-dix', 'quatre-vingt', 'quatre-vingt-dix'];
        
    //     if ($nombre < 10) {
    //         return $units[$nombre];
    //     }
        
    //     if ($nombre < 20) {
    //         return $teens[$nombre - 10];
    //     }
        
    //     if ($nombre < 100) {
    //         $dizaine = floor($nombre / 10);
    //         $unite = $nombre % 10;
    //         $resultat = $tens[$dizaine];
    //         if ($unite > 0) {
    //             if ($dizaine == 7 || $dizaine == 9) {
    //                 $resultat .= '-' . $teens[$unite + 10];
    //             } else {
    //                 $resultat .= '-' . $units[$unite];
    //             }
    //         }
    //         return $resultat;
    //     }
        
    //     return number_format($nombre, 0, '', ' ');
    // }

    /**
     * Récupérer le logo en base64 (plus fiable pour PDF)
     */
    protected function getLogoBase64(): ?string
    {
        $logoPath = public_path('images/logo-starehe.png');
        
        if (!file_exists($logoPath) || is_dir($logoPath)) {
            return null;
        }
        
        $mime = mime_content_type($logoPath);
        $data = base64_encode(file_get_contents($logoPath));
        
        return 'data:' . $mime . ';base64,' . $data;
    }

    /**
     * Récupérer le QR Code en base64
     */
    protected function getQrCodeBase64(): ?string
    {
        $qrPath = public_path('QR/stareheqr.png');
        
        if (!file_exists($qrPath) || is_dir($qrPath)) {
            return null;
        }
        
        $mime = mime_content_type($qrPath);
        $data = base64_encode(file_get_contents($qrPath));
        
        return 'data:' . $mime . ';base64,' . $data;
    }

    /**
     * Générer un code-barres
     */
    protected function generateBarcode(Facture $facture): ?string
    {
        // À implémenter avec une librairie comme Milon\Barcode
        // Pour l'instant, on retourne null
        return null;
    }

    /**
     * Récupérer le libellé de la devise
     */
    protected function getDeviseLibelle(): string
    {
        return 'Francs Congolais (CDF)';
    }
}