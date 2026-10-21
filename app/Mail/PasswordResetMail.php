<?php
// app/Mail/PasswordResetMail.php

namespace App\Mail;

use App\Models\Utilisateur;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public $token;
    public $user;

    public function __construct($token, Utilisateur $user)
    {
        $this->token = $token;
        $this->user = $user;
    }

    public function build()
    {
        $appName = config('app.name', 'STAREHE');
        
        return $this->subject('Réinitialisation de votre mot de passe - ' . $appName)
                    ->html($this->getHtmlContent());
    }

    private function getHtmlContent(): string
    {
        $appName = config('app.name', 'STAREHE');
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $resetLink = $frontendUrl . '/reset-password?token=' . $this->token . '&email=' . urlencode($this->user->email);
        
        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Réinitialisation mot de passe - {$appName}</title>
            <style>
                body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 20px auto; background-color: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); color: white; padding: 30px; text-align: center; }
                .header h1 { margin: 0; font-size: 24px; }
                .header p { margin: 10px 0 0; opacity: 0.9; }
                .content { padding: 30px; }
                .warning-box { background-color: #fff3cd; border: 1px solid #ffecb5; color: #856404; padding: 15px; border-radius: 8px; margin: 20px 0; }
                .button { display: inline-block; background: linear-gradient(135deg, #3498db 0%, #2980b9 100%); color: white; text-decoration: none; padding: 14px 35px; border-radius: 50px; margin: 20px 0; font-weight: bold; }
                .button:hover { transform: scale(1.02); }
                .footer { background-color: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #6c757d; border-top: 1px solid #dee2e6; }
                .info { background-color: #e8f4fd; padding: 15px; border-radius: 8px; margin: 20px 0; font-size: 14px; }
                hr { border: none; border-top: 1px solid #dee2e6; margin: 20px 0; }
                code { background: #e9ecef; padding: 3px 8px; border-radius: 4px; font-family: monospace; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>🔐 Réinitialisation du mot de passe</h1>
                    <p>{$appName}</p>
                </div>
                <div class="content">
                    <p>Bonjour <strong>{$this->user->nom_complet}</strong>,</p>
                    
                    <p>Nous avons reçu une demande de réinitialisation de votre mot de passe pour votre compte {$appName}.</p>
                    
                    <div class="warning-box">
                        ⚠️ <strong>Important :</strong> Si vous n'êtes pas à l'origine de cette demande, ignorez cet email. Votre mot de passe ne sera pas modifié.
                    </div>
                    
                    <p>Pour réinitialiser votre mot de passe, cliquez sur le bouton ci-dessous :</p>
                    
                    <div style="text-align: center;">
                        <a href="{$resetLink}" class="button">🔑 Réinitialiser mon mot de passe</a>
                    </div>
                    
                    <div class="info">
                        <strong>📌 Lien direct :</strong><br>
                        <code style="word-break: break-all;">{$resetLink}</code>
                        <p style="margin-top: 10px; font-size: 12px;">Ce lien est valable pendant 60 minutes.</p>
                    </div>
                    
                    <hr>
                    
                    <p>Si vous rencontrez des difficultés, veuillez contacter notre support technique.</p>
                    
                    <p>Cordialement,<br><strong>L'équipe {$appName}</strong></p>
                </div>
                <div class="footer">
                    <p>&copy; " . date('Y') . " {$appName}. Tous droits réservés.</p>
                    <p>Cet email a été envoyé automatiquement, merci de ne pas y répondre.</p>
                </div>
            </div>
        </body>
        </html>
        HTML;
    }
}