<?php

namespace App\Mail;

use App\Models\Utilisateur;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class GenericNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $titre;
    public string $messageTexte;
    public Utilisateur $user;

    public function __construct(string $titre, string $messageTexte, Utilisateur $user)
    {
        $this->titre = $titre;
        $this->messageTexte = $messageTexte;
        $this->user = $user;
    }

    public function build()
    {
        // Recherche du fichier image sur le serveur
        $logoPath = public_path('Starehe logo/Asset912x.png');
        if (!file_exists($logoPath)) {
            $logoPath = public_path('Starehe logo/Asset 10@24x.png');
        }

        return $this
            ->from('contact@stareheholding.com', 'Starehe Holding')
            ->replyTo('contact@stareheholding.com', 'Starehe Holding')
            ->subject('Notification : ' . $this->titre)
            ->view('emails.notification')
            ->with([
                'titre' => $this->titre,
                'messageTexte' => $this->messageTexte,
                'user' => $this->user,
                'logoExiste' => file_exists($logoPath),
                'logoPath' => $logoPath,
            ]);
    }
}