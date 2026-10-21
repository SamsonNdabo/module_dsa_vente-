<?php
// app/Mail/TwoFactorCodeMail.php

namespace App\Mail;

use App\Models\Utilisateur;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TwoFactorCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public $code;
    public $user;

    public function __construct($code, Utilisateur $user)
    {
        $this->code = $code;
        $this->user = $user;
    }

    public function build()
    {
        $logoPath = public_path('images/logo-starehe.png');
        $appName = config('app.name', 'STAREHE');

        return $this->subject('Votre code de vérification - ' . $appName)
                    ->view('emails.two_factor')
                    ->with([
                        'code' => $this->code,
                        'user' => $this->user,
                        'appName' => $appName,
                        'logoPath' => $logoPath,
                    ]);
    }
}