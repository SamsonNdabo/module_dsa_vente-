<?php
namespace App\Services;

use App\Models\SmsLog;
use App\Models\SmsTemplate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KeccelSmsService
{
    private string $token;
    private string $from;
    private string $baseUrl = 'https://api.keccel.com/sms/v2/message.asp';

    public function __construct()
    {
        $this->token = config('services.keccel_sms.token');
        $this->from  = config('services.keccel_sms.from');
    }

    // ============================================================
    // Méthode principale — envoyer selon un contexte
    // ============================================================

    public function envoyer(
        string  $contexte,
        string  $destinataire,
        array   $variables,
        ?string $reference     = null,
        ?int    $succursaleId  = null
    ): bool {
        // Récupérer le template actif pour ce contexte
        $template = SmsTemplate::where('contexte', $contexte)
            ->where('actif', true)
            ->first();

        // Contexte désactivé ou inexistant → on log et on sort silencieusement
        if (!$template) {
            Log::info("SMS [{$contexte}] désactivé ou inexistant — non envoyé", [
                'destinataire' => $destinataire,
                'reference'    => $reference,
            ]);
            return false;
        }

        // Construire le message final
        $message = $template->rendu($variables);

        // Appel API Keccel
        try {
            $response = Http::timeout(15)->post($this->baseUrl, [
                'token'   => $this->token,
                'to'      => $destinataire,
                'from'    => $this->from,
                'message' => $message,
            ]);

            $data    = $response->json() ?? [];
            $statut  = strtoupper($data['status'] ?? 'FAILED');
            $estSent = $statut === 'SENT';

            SmsLog::create([
                'destinataire'  => $destinataire,
                'nom_client'    => $variables['nom'] ?? null,
                'contexte'      => $contexte,
                'message'       => $message,
                'message_id'    => $data['messageID'] ?? null,
                'statut'        => $estSent ? 'SENT' : 'REJECTED',
                'reference'     => $reference,
                'id_succursale' => $succursaleId,
                'erreur'        => $estSent ? null : ($data['description'] ?? null),
            ]);

            return $estSent;

        } catch (\Exception $e) {
            SmsLog::create([
                'destinataire'  => $destinataire,
                'nom_client'    => $variables['nom'] ?? null,
                'contexte'      => $contexte,
                'message'       => $message,
                'statut'        => 'FAILED',
                'reference'     => $reference,
                'id_succursale' => $succursaleId,
                'erreur'        => $e->getMessage(),
            ]);

            Log::error("SMS [{$contexte}] erreur connexion Keccel : " . $e->getMessage());
            return false;
        }
    }
}