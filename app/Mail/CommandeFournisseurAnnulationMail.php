<?php
// app/Mail/CommandeFournisseurAnnulationMail.php

namespace App\Mail;

use App\Models\CommandeFournisseur;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CommandeFournisseurAnnulationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $commande;
    public $fournisseur;
    public $lignes;
    public $motif;

    public function __construct(CommandeFournisseur $commande, $motif = null)
    {
        $this->commande = $commande;
        $this->fournisseur = $commande->fournisseur;
        $this->lignes = $commande->lignes()->with('produit')->get();
        $this->motif = $motif ?? $commande->motif_annulation ?? 'Non spécifié';
    }

    public function build()
    {
        $logoPath = public_path('images/logo-starehe.png');

        return $this->subject("Annulation de commande #{$this->commande->reference} - STAREHE Holding")
            ->view('emails.commande_annulation')
            ->with([
                'commande' => $this->commande,
                'fournisseur' => $this->fournisseur,
                'lignes' => $this->lignes,
                'motif' => $this->motif,
                'logoPath' => $logoPath,
            ]);
    }
}