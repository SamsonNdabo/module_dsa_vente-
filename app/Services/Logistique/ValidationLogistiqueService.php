<?php

// app/Services/Logistique/ValidationLogistiqueService.php

namespace App\Services\Logistique;

use App\Models\Approvisionnement;
use App\Models\SeuilLogistique;
use App\Models\Devise;
use App\Services\Notification\LogistiqueNotificationService;
use Illuminate\Support\Facades\Log;

class ValidationLogistiqueService
{
    protected LogistiqueNotificationService $notificationService;

    public function __construct(LogistiqueNotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Vérifier si une validation logistique nécessite une approbation supplémentaire
     * Un seul seuil : montant_max_approbation_superieur
     */
    public function verifierNiveauApprobation(Approvisionnement $demande, float $coutTotal, int $deviseId): array
    {
        $seuil = SeuilLogistique::where('devise_id', $deviseId)
            ->where('actif', true)
            ->first();

        if (!$seuil) {
            return [
                'necessite_approbation' => false,
                'message' => 'Aucun seuil configuré pour cette devise, validation directe'
            ];
        }

        if ($seuil->necessiteApprobationSuperieure($coutTotal)) {
            return [
                'necessite_approbation' => true,
                'role_approbateur' => $seuil->niveau_superieur_role,
                'seuil_approbation' => $seuil->montant_max_approbation_superieur,
                'message' => "Le montant total ({$coutTotal} {$seuil->devise->code}) dépasse le seuil d'approbation ({$seuil->montant_max_approbation_superieur} {$seuil->devise->code})"
            ];
        }

        return [
            'necessite_approbation' => false,
            'message' => "Montant inférieur ou égal au seuil d'approbation, validation directe"
        ];
    }

    /**
     * Traiter la validation logistique avec gestion des seuils
     */
    public function traiterValidationLogistique(Approvisionnement $demande, array $data, $user): array
    {
        $coutTotal = $data['cout_total'] ?? 0;
        $deviseId = $data['devise_id'];

        $devise = Devise::find($deviseId);
        if (!$devise) {
            throw new \Exception('Devise non trouvée');
        }

        $verification = $this->verifierNiveauApprobation($demande, $coutTotal, $deviseId);

        // Si approbation supérieure nécessaire
        if ($verification['necessite_approbation']) {
            return $this->traiterAvecApprobationSuperieure($demande, $data, $user, $devise, $verification);
        }

        // Validation directe (montant dans la limite du seuil)
        return $this->traiterValidationDirecte($demande, $data, $user, $devise);
    }

    /**
     * Traite une validation nécessitant une approbation supérieure
     */
    protected function traiterAvecApprobationSuperieure(
        Approvisionnement $demande,
        array $data,
        $user,
        Devise $devise,
        array $verification
    ): array {
        $this->mettreAJourChampsLogistique($demande, $data, $user, $devise, [
            'statut_operation' => 'EN_ATTENTE_APPROBATION_LOGISTIQUE',
            'statut_approbation_logistique' => 'EN_ATTENTE'
        ]);

        // Notifier le supérieur
        $this->notificationService->notifierApprobationSuperieureRequise(
            $demande,
            $user,
            $verification,
            $devise
        );

        return [
            'success' => true,
            'statut' => 'EN_ATTENTE_APPROBATION',
            'message' => $verification['message'],
            'demande' => $demande
        ];
    }

    /**
     * Traite une validation directe (sans approbation supérieure)
     */
    protected function traiterValidationDirecte(
        Approvisionnement $demande,
        array $data,
        $user,
        Devise $devise
    ): array {
        $this->mettreAJourChampsLogistique($demande, $data, $user, $devise, [
            'statut_operation' => 'VALIDE',
            'statut_approbation_logistique' => null
        ]);

        return [
            'success' => true,
            'statut' => 'VALIDE',
            'message' => 'Validation logistique effectuée avec succès',
            'demande' => $demande
        ];
    }

    /**
     * Mise à jour centralisée des champs logistiques
     */
    protected function mettreAJourChampsLogistique(
        Approvisionnement $demande,
        array $data,
        $user,
        Devise $devise,
        array $statuts
    ): void {
        $updateData = [
            'type_parcours' => $data['type_parcours'],
            'type_demande_logistique' => $data['type_demande'] ?? null,
            'mode_transport' => $data['mode_transport'],
            'devise_id' => $data['devise_id'],
            'avec_intermediaire' => $data['type_parcours'] === 'AVEC_INTERMEDIAIRE',
            'intermediaire_succursale_id' => $data['intermediaire_id'] ?? null,
            'poids_total' => $data['poids_total'] ?? null,
            'volume_total' => $data['volume_total'] ?? null,
            'valeur_totale' => $data['valeur_totale'] ?? null,
            'cout_transport' => $data['cout_transport'] ?? null,
            'cout_assurance' => $data['cout_assurance'] ?? null,
            'cout_emballage' => $data['cout_emballage'] ?? null,
            'cout_total' => $data['cout_total'] ?? 0,
            'responsable_logistique_id' => $user->id,
            'date_validation_logistique' => now()
        ];

        // Fusionner les statuts
        $updateData = array_merge($updateData, $statuts);

        // Ajouter la note de validation
        $noteComplement = $this->construireNoteValidation($data, $user, $devise, $statuts);
        $updateData['notes'] = ($demande->notes ? $demande->notes . "\n" : '') . $noteComplement;

        $demande->update($updateData);
    }

    /**
     * Construit la note de validation
     */
    protected function construireNoteValidation(array $data, $user, Devise $devise, array $statuts): string
    {
        $statutLibelle = $this->getStatutLibelle($statuts);
        $transportLibelle = Approvisionnement::MODE_TRANSPORT[$data['mode_transport']] ?? $data['mode_transport'];

        $note = "VALIDATION LOGISTIQUE ({$statutLibelle}) par {$user->nom_complet}\n";
        $note .= "Type: " . ($data['type_demande'] ?? 'Non spécifié') . "\n";
        $note .= "Transport: {$transportLibelle}\n";
        $note .= "Coût total: {$data['cout_total']} {$devise->code}";

        return $note;
    }

    /**
     * Récupère le libellé du statut
     */
    protected function getStatutLibelle(array $statuts): string
    {
        if (isset($statuts['statut_approbation_logistique']) && $statuts['statut_approbation_logistique'] === 'EN_ATTENTE') {
            return 'EN ATTENTE APPROBATION SUPERIEURE';
        }
        return $statuts['statut_operation'] ?? 'VALIDATION';
    }

    /**
     * Approuver la logistique par le supérieur
     */
    public function approuverParSuperieur(Approvisionnement $demande, $user, ?string $commentaire = null): array
    {
        if ($demande->statut_approbation_logistique !== 'EN_ATTENTE') {
            throw new \Exception('Cette demande n\'est pas en attente d\'approbation supérieure');
        }

        $demande->update([
            'statut_operation' => 'VALIDE',
            'statut_approbation_logistique' => 'APPROUVE',
            'approbateur_superieur_id' => $user->id,
            'date_approbation_superieur' => now(),
            'notes' => ($demande->notes ? $demande->notes . "\n" : '') .
                "APPROUVÉ PAR SUPÉRIEUR ({$user->nom_complet}): " . ($commentaire ?? 'Sans commentaire')
        ]);

        $this->notificationService->notifierApprobationSuperieureAccordee($demande, $user);

        return [
            'success' => true,
            'message' => 'Demande approuvée par le supérieur',
            'demande' => $demande
        ];
    }

    /**
     * Rejeter la logistique par le supérieur
     */
    public function rejeterParSuperieur(Approvisionnement $demande, $user, string $motif): array
    {
        if ($demande->statut_approbation_logistique !== 'EN_ATTENTE') {
            throw new \Exception('Cette demande n\'est pas en attente d\'approbation supérieure');
        }

        $demande->update([
            'statut_operation' => 'REJETE_LOGISTIQUE',
            'statut_approbation_logistique' => 'REJETE',
            'approbateur_superieur_id' => $user->id,
            'date_approbation_superieur' => now(),
            'motif_rejet_superieur' => $motif,
            'notes' => ($demande->notes ? $demande->notes . "\n" : '') .
                "REJETÉ PAR SUPÉRIEUR ({$user->nom_complet}): {$motif}"
        ]);

        $this->notificationService->notifierApprobationSuperieureRejetee($demande, $user, $motif);

        return [
            'success' => true,
            'message' => 'Demande rejetée par le supérieur',
            'demande' => $demande
        ];
    }

    /**
     * Mettre à jour les seuils (API Admin)
     * UN SEUL SEUIL MAINTENANT
     */
    public function mettreAJourSeuil(int $deviseId, float $montantMaxApprobation, string $roleSuperieur): SeuilLogistique
    {
        $seuil = SeuilLogistique::updateOrCreate(
            ['devise_id' => $deviseId],
            [
                'montant_max_approbation_superieur' => $montantMaxApprobation,
                'niveau_superieur_role' => $roleSuperieur,
                'actif' => true
            ]
        );

        Log::info('Seuil logistique mis à jour', [
            'devise_id' => $deviseId,
            'montant_max' => $montantMaxApprobation,
            'role_superieur' => $roleSuperieur
        ]);

        return $seuil;
    }

    /**
     * Récupérer le seuil pour une devise
     */
    public function getSeuil(int $deviseId): ?SeuilLogistique
    {
        return SeuilLogistique::where('devise_id', $deviseId)
            ->where('actif', true)
            ->first();
    }
}