<?php

// app/Services/Notification/LogistiqueNotificationService.php

namespace App\Services\Notification;

use App\Models\Approvisionnement;
use App\Models\Devise;
use App\Traits\Notifiable;

class LogistiqueNotificationService
{
    use Notifiable;

    /**
     * Notifier qu'une approbation supérieure est requise
     */
    public function notifierApprobationSuperieureRequise(
        Approvisionnement $demande,
        $user,
        array $verification,
        Devise $devise
    ): void {
        $this->notifierDestinatairesSpecifiques([
            'destinataires' => [
                'type' => 'role',
                'code' => $verification['role_approbateur']
            ],
            'type' => 'DEMANDE_APPROVISIONNEMENT',
            'titre' => "⚠️ Validation supérieure requise - Logistique",
            'message' => "Demande #{$demande->reference} - Coût total: {$demande->cout_total} {$devise->code} dépasse le seuil d'approbation ({$verification['seuil_approbation']} {$devise->code})",
            'donnees' => [
                'demande_id' => $demande->id,
                'reference' => $demande->reference,
                'cout_total' => $demande->cout_total,
                'devise' => $devise->code,
                'seuil_approbation' => $verification['seuil_approbation'],
                'valide_par_logistique' => $user->nom_complet,
                'source' => $demande->sourceDecidee->nom ?? 'Non spécifié',
                'destination' => $demande->destinationSuccursale->nom ?? 'Non spécifié'
            ],
            'priorite' => 'URGENTE'
        ]);

        // Notifier aussi le responsable logistique que la demande est en attente
        $this->notifierDestinatairesSpecifiques([
            'destinataires' => [
                'type' => 'utilisateur',
                'id' => $user->id
            ],
            'type' => 'APPROVISIONNEMENT_VALIDE',
            'titre' => "⏳ Demande en attente d'approbation supérieure",
            'message' => "Demande #{$demande->reference} - En attente de validation par le supérieur hiérarchique",
            'donnees' => [
                'demande_id' => $demande->id,
                'reference' => $demande->reference
            ],
            'priorite' => 'HAUTE'
        ]);
    }

    /**
     * Notifier qu'une approbation supérieure a été accordée
     */
    public function notifierApprobationSuperieureAccordee(Approvisionnement $demande, $user): void
    {
        $devise = $demande->devise;

        if ($demande->responsable_logistique_id) {
            $this->notifierDestinatairesSpecifiques([
                'destinataires' => [
                    'type' => 'utilisateur',
                    'id' => $demande->responsable_logistique_id
                ],
                'type' => 'APPROVISIONNEMENT_VALIDE',
                'titre' => " Demande approuvée par le supérieur",
                'message' => "Demande #{$demande->reference} - Approbation supérieure accordée par {$user->nom_complet}",
                'donnees' => [
                    'demande_id' => $demande->id,
                    'reference' => $demande->reference
                ],
                'priorite' => 'HAUTE'
            ]);
        }

        if ($demande->demandeur_id) {
            $this->notifierDestinatairesSpecifiques([
                'destinataires' => [
                    'type' => 'utilisateur',
                    'id' => $demande->demandeur_id
                ],
                'type' => 'APPROVISIONNEMENT_VALIDE',
                'titre' => " Validation logistique finalisée",
                'message' => "Demande #{$demande->reference} - Organisation du transport validée (coût total: {$demande->cout_total} {$devise->code})",
                'donnees' => [
                    'demande_id' => $demande->id,
                    'reference' => $demande->reference,
                    'cout_total' => $demande->cout_total,
                    'devise' => $devise->code
                ],
                'priorite' => 'HAUTE'
            ]);
        }
    }

    /**
     * Notifier qu'une approbation supérieure a été rejetée
     */
    public function notifierApprobationSuperieureRejetee(Approvisionnement $demande, $user, string $motif): void
    {
        if ($demande->responsable_logistique_id) {
            $this->notifierDestinatairesSpecifiques([
                'destinataires' => [
                    'type' => 'utilisateur',
                    'id' => $demande->responsable_logistique_id
                ],
                'type' => 'DEMANDE_APPROVISIONNEMENT',
                'titre' => "❌ Demande rejetée par le supérieur",
                'message' => "Demande #{$demande->reference} - Rejetée par {$user->nom_complet}. Motif: {$motif}",
                'donnees' => [
                    'demande_id' => $demande->id,
                    'reference' => $demande->reference,
                    'motif' => $motif
                ],
                'priorite' => 'URGENTE'
            ]);
        }

        if ($demande->demandeur_id) {
            $this->notifierDestinatairesSpecifiques([
                'destinataires' => [
                    'type' => 'utilisateur',
                    'id' => $demande->demandeur_id
                ],
                'type' => 'DEMANDE_APPROVISIONNEMENT',
                'titre' => "❌ Demande rejetée - Coût logistique trop élevé",
                'message' => "Demande #{$demande->reference} - Le coût logistique a été jugé trop élevé par la direction. Motif: {$motif}",
                'donnees' => [
                    'demande_id' => $demande->id,
                    'reference' => $demande->reference,
                    'motif' => $motif
                ],
                'priorite' => 'HAUTE'
            ]);
        }
    }
}