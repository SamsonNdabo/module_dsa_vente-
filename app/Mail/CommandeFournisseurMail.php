<?php

namespace App\Mail;

use App\Models\CommandeFournisseur;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CommandeFournisseurMail extends Mailable
{
    use Queueable, SerializesModels;

    public $commande;
    public $fournisseur;
    public $lignes;

    public function __construct(CommandeFournisseur $commande)
    {
        $this->commande = $commande;
        $this->fournisseur = $commande->fournisseur;
        
        //  Chargement des lignes avec leurs champs existants
        $this->lignes = $commande->lignes()->with('produit')->get();
        
        //  Vérification
        if ($this->lignes->isEmpty()) {
            Log::warning('Commande sans lignes', ['commande_id' => $commande->id]);
        }
    }

    public function build()
    {
        $logoPath = public_path('images/logo-starehe.png');
        $logoExists = file_exists($logoPath);

        return $this->subject("Nouvelle commande #{$this->commande->reference} - STAREHE Holding")
                    ->view('emails.commande')
                    ->with([
                        'commande' => $this->commande,
                        'fournisseur' => $this->fournisseur,
                        'lignes' => $this->lignes,
                        'logoPath' => $logoExists ? $logoPath : null,
                    ]);
    }
}