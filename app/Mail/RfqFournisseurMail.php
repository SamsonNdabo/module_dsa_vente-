<?php

namespace App\Mail;

use App\Models\RfqFournisseur;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RfqFournisseurMail extends Mailable
{
    use Queueable, SerializesModels;

    public $rfq;
    public $fournisseur;
    public $lignes;

       public function __construct(RfqFournisseur $rfq)
    {
        $this->rfq = $rfq->load([
            'devise',
            'fournisseur',
            'lignes.produit',
        ]);
    
        $this->fournisseur = $this->rfq->fournisseur;
        $this->lignes = $this->rfq->lignes;
    }

    public function build()
    {
        $logoPath = public_path('images/logo-starehe.png');


        return $this->subject("Demande de devis #{$this->rfq->reference} - STAREHE Holding")
            ->view('emails.rfq')
            ->with([
                'rfq' => $this->rfq,
                'fournisseur' => $this->fournisseur,
                'lignes' => $this->lignes,
                'logoPath' => $logoPath,
            ]);
    }
}