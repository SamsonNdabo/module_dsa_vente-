<?php
// app/Mail/ClientWelcomeMail.php

namespace App\Mail;

use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ClientWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public Client $client;
    public string $motDePasseTemp;

    public function __construct(Client $client, string $motDePasseTemp)
    {
        $this->client = $client;
        $this->motDePasseTemp = $motDePasseTemp;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $logoPath = public_path('images/logo-starehe.png');
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

        return $this->subject('Bienvenue sur STAREHE - Vos identifiants de connexion')
                    ->view('emails.client_welcome')
                    ->with([
                        'client' => $this->client,
                        'motDePasseTemp' => $this->motDePasseTemp,
                        'frontendUrl' => $frontendUrl,
                        'logoPath' => $logoPath,
                    ]);
    }
}