<?php

namespace App\Http\Controllers\Receipt;

use App\Http\Controllers\Controller;
use App\Models\Receipt;
use App\Models\ReceiptConfig;
use App\Services\KeccelSmsService;
use App\Services\ReceiptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ReceiptController extends Controller
{
    public function index(Request $request)
    {
        $query = Receipt::with(['deviseRemise', 'deviseRecue']);

        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }
        if ($request->filled('date_debut')) {
            $query->whereDate('created_at', '>=', $request->date_debut);
        }
        if ($request->filled('date_fin')) {
            $query->whereDate('created_at', '<=', $request->date_fin);
        }
        if ($request->filled('client')) {
            $query->where(function($q) use ($request) {
                $q->where('client_nom', 'LIKE', '%' . $request->client . '%')
                ->orWhere('client_telephone', 'LIKE', '%' . $request->client . '%');
            });
        }

        $receipts = $query->orderBy('created_at', 'desc')->paginate(20);
        return response()->json(['success' => true, 'data' => $receipts]);
    }
    public function show($id)
    {
        $receipt = Receipt::with(['operationChange', 'client', 'originalReceipt', 'deviseRemise', 'deviseRecue'])
            ->findOrFail($id);
        return response()->json(['success' => true, 'data' => $receipt]);
    }

    public function pdf($id)
    {
        Log::info('=== DEBUT pdf ===', ['receipt_id' => $id]);

        $receipt = Receipt::findOrFail($id);
        Log::info('Reçu trouvé', [
            'reference' => $receipt->reference,
            'pdf_path' => $receipt->pdf_path,
        ]);

        // Vérifier si le PDF existe déjà
        if ($receipt->pdf_path) {
            // Tester les deux chemins possibles
            $pathsToCheck = [
                $receipt->pdf_path,
                'operationchange/pdf/' . basename($receipt->pdf_path),
            ];

            foreach ($pathsToCheck as $path) {
                if (Storage::disk('public')->exists($path)) {
                    $url = asset('storage/' . $path);
                    Log::info('PDF trouvé', ['path' => $path, 'url' => $url]);
                    return response()->json([
                        'success' => true,
                        'data' => ['url' => $url],
                    ]);
                }
            }
        }

        // Si le PDF n'existe pas, le générer
        Log::info('PDF non trouvé, régénération...');
        $service = new ReceiptService();
        $pdfPath = $service->generatePdf($receipt);
        $receipt->pdf_path = $pdfPath;
        $receipt->save();

        $url = asset('storage/' . $pdfPath);
        Log::info('URL du PDF (régénéré)', ['url' => $url]);

        return response()->json([
            'success' => true,
            'data' => ['url' => $url],
        ]);
    }

    public function duplicata($id)
    {
    Log::info('=== DEBUT duplicata ===', ['original_id' => $id]);

    try {
        $original = Receipt::findOrFail($id);
        $service = new ReceiptService();

        // Créer le duplicata
        $duplicata = $service->generateDuplicata($original);
Log::info('Duplicata créé', ['duplicata_id' => $duplicata->id]);
        // Générer le PDF du duplicata
        $pdfPath = $service->generatePdf($duplicata);
        Log::info('PDF du duplicata généré', ['path' => $pdfPath]);
        $duplicata->pdf_path = $pdfPath;
        $duplicata->save();
        Log::info('=== FIN duplicata ===');
        return response()->json([
            'success' => true,
            'message' => 'Duplicata généré avec succès.',
            'data' => [
                'id' => $duplicata->id,
                'reference' => $duplicata->reference,
                'url' => asset('storage/' . $duplicata->pdf_path),
                'est_duplicata' => true,
            ],
        ]);
    } catch (\Exception $e) {
        Log::error('ERREUR duplicata', [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        throw $e;
    }
    }

    public function annuler(Request $request, $id)
    {
        $validated = $request->validate(['motif' => 'required|string']);
        $receipt = Receipt::findOrFail($id);
        $service = new ReceiptService();
        $counter = $service->cancel($receipt, $validated['motif']);

        return response()->json([
            'success' => true,
            'message' => 'Reçu annulé. Un reçu de contrepassation a été généré.',
            'data' => $counter,
        ]);
    }

    public function byOperation($operationId)
    {
        $receipt = Receipt::where('operation_change_id', $operationId)->first();
        if (!$receipt) {
            return response()->json(['success' => false, 'message' => 'Reçu non trouvé pour cette opération'], 404);
        }
        return response()->json(['success' => true, 'data' => $receipt]);
    }
    
    /**
     * Vérifier un reçu via sa référence (API publique)
     * Utilisé par le QR code pour la vérification
     */
    public function verify($reference)
    {
        $receipt = Receipt::with(['deviseRemise', 'deviseRecue', 'client'])
            ->where('reference', $reference)
            ->first();

        if (!$receipt) {
            return response()->json([
                'success' => false,
                'message' => 'Reçu introuvable. Vérifiez la référence.',
            ], 404);
        }

        // On peut aussi vérifier si le reçu est annulé ou contré
        $estValide = !in_array($receipt->statut, ['ANNULEE', 'CONTRESSEE']);

        return response()->json([
            'success' => true,
            'data' => [
                'reference'           => $receipt->reference,
                'statut'              => $receipt->statut,
                'est_valide'          => $estValide,
                'date_operation'      => $receipt->created_at->format('d/m/Y H:i:s'),
                'type_operation'      => $receipt->type_operation,
                'client' => [
                    'nom'       => $receipt->client_nom,
                    'telephone' => $receipt->client_telephone,
                ],
                'montants' => [
                    'devise_remise' => [
                        'montant' => number_format($receipt->devise_remise_montant, 2),
                        'devise'  => $receipt->devise_remise ?? '?',
                    ],
                    'devise_recue' => [
                        'montant' => number_format($receipt->devise_recue_montant, 2),
                        'devise'  => $receipt->devise_recue ?? '?',
                    ],
                    'taux' => $receipt->taux_affichage,
                    'commission' => number_format($receipt->commission, 2),
                    'montant_net' => number_format($receipt->montant_net, 2),
                ],
                'guichetier' => $receipt->guichetier_nom,
                'caisse'     => $receipt->caisse_nom,
                'agence'     => $receipt->agence_nom,
                'est_duplicata' => $receipt->est_duplicata,
                'reference_originale' => $receipt->originalReceipt?->reference ?? null,
            ],
        ]);
    }

    /**
     * Envoyer le reçu par email, WhatsApp ou SMS.
     */
    public function envoyer(Request $request, $id)
    {
        $validated = $request->validate([
            'type'         => 'required|in:email,whatsapp,sms',
            'destinataire' => 'required|string', // email ou numéro de téléphone
            'message'      => 'nullable|string', // message personnalisé (pour SMS ou WhatsApp)
        ]);

        $receipt = Receipt::with(['deviseRemise', 'deviseRecue', 'client'])->findOrFail($id);

        // Vérifier si le PDF existe
        if (!$receipt->pdf_path || !Storage::disk('public')->exists($receipt->pdf_path)) {
            // Si le PDF n'existe pas, le générer
            $service = new \App\Services\ReceiptService();
            $pdfPath = $service->generatePdf($receipt);
            $receipt->pdf_path = $pdfPath;
            $receipt->save();
        }

        $pdfUrl = asset('storage/' . $receipt->pdf_path);
        $config = ReceiptConfig::getConfig();

        switch ($validated['type']) {
            case 'email':
                return $this->envoyerParEmail($receipt, $validated['destinataire'], $validated['message'] ?? null);
            case 'whatsapp':
                return $this->envoyerParWhatsApp($receipt, $validated['destinataire'], $validated['message'] ?? null);
            case 'sms':
                return $this->envoyerParSms($receipt, $validated['destinataire'], $validated['message'] ?? null);
        }
    }

    /**
     * Envoyer le reçu par email avec le PDF en pièce jointe.
     */
    private function envoyerParEmail(Receipt $receipt, string $email, ?string $message)
    {
        try {
            $pdfPath = storage_path('app/public/' . $receipt->pdf_path);
            if (!file_exists($pdfPath)) {
                throw new \Exception('Le fichier PDF est introuvable.');
            }

            $config = ReceiptConfig::getConfig();

            // Envoyer l'email avec une vue dédiée
            Mail::send('receipts.email', [
                'receipt' => $receipt,
                'config' => $config,
                'message_texte' => $message,
            ], function ($mail) use ($email, $receipt, $pdfPath) {
                $mail->to($email)
                    ->subject('Reçu de transaction - ' . $receipt->reference)
                    ->attach($pdfPath, [
                        'as' => 'recu-' . $receipt->reference . '.pdf',
                        'mime' => 'application/pdf',
                    ]);
            });

            Log::info('Reçu envoyé par email', [
                'receipt' => $receipt->reference,
                'email' => $email,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Reçu envoyé par email à {$email}",
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur envoi email reçu', [
                'receipt' => $receipt->reference,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'envoi par email : ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Envoyer le reçu par WhatsApp (génère un lien et l'envoie par SMS pour l'instant,
     * ou utilise un service comme Twilio WhatsApp si intégré).
     */
    private function envoyerParWhatsApp(Receipt $receipt, string $numero, ?string $message)
    {
        // Générer un lien de téléchargement du PDF (ou une page de vérification)
        $lien = route('receipts.verify', ['reference' => $receipt->reference]);
        $messageFinal = $message ?? "Votre reçu {$receipt->reference} est disponible ici : {$lien}";

        // Si vous avez un service WhatsApp (Twilio, etc.), l'appeler ici.
        // Pour l'exemple, on envoie juste un SMS avec le lien.
        return $this->envoyerParSms($receipt, $numero, $messageFinal);
    }

    /**
     * Envoyer un SMS personnalisé via le service KeccelSms.
     */
    private function envoyerParSms(Receipt $receipt, string $numero, ?string $message)
    {
        try {
            // Nettoyer le numéro (enlever les espaces, etc.)
            $numero = preg_replace('/[^0-9+]/', '', $numero);

            // Message par défaut si non fourni
            $message = $message ?? "Votre reçu {$receipt->reference} est disponible. Merci d'utiliser SHOP MODERNE.";

            // Utiliser le service SMS existant
            $smsService = new KeccelSmsService();
            $smsService->envoyer(
                'RECU', // contexte, vous pouvez adapter
                $numero,
                [
                    'nom'       => $receipt->client_nom ?? 'Client',
                    'reference' => $receipt->reference,
                    'montant'   => number_format($receipt->montant_net, 2),
                    'devise'    => $receipt->deviseRecue->code ?? '',
                    'message'   => $message,
                ],
                $receipt->reference,
                $receipt->succursale_id ?? null
            );

            Log::info('SMS envoyé pour reçu', [
                'receipt' => $receipt->reference,
                'numero' => $numero,
            ]);

            return response()->json([
                'success' => true,
                'message' => "SMS envoyé à {$numero}",
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur envoi SMS reçu', [
                'receipt' => $receipt->reference,
                'numero' => $numero,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'envoi du SMS : ' . $e->getMessage(),
            ], 500);
        }
    }
}