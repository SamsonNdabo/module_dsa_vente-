<?php
// app/Traits/Auditable.php

namespace App\Traits;

use App\Models\AuditSysteme;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Log;

trait Auditable
{
    /**
     * Enregistrer une action dans l'audit
     */
    public function logAudit($action, $oldValues = null, $newValues = null, $importance = 'INFO')
    {
        if (!auth()->check()) {
            Log::info('Audit ignoré: utilisateur non authentifié', ['action' => $action]);
            return;
        }

        try {
            // Raccourcir l'action si nécessaire
            $actionShort = $this->shortenAction($action);

            $audit = AuditSysteme::create([
                'table_affectee' => $this->getTable(),
                'type_action' => $actionShort,
                'id_enregistrement' => $this->id ?? null,
                'ancienne_valeur' => $oldValues ? json_encode($oldValues) : null,
                'nouvelle_valeur' => $newValues ? json_encode($newValues) : null,
                'id_utilisateur' => auth()->id(),
                'adresse_ip' => Request::ip(),
                'user_agent' => Request::userAgent(),
                'description' => $this->generateAuditDescription($action), // ✅ CORRIGÉ
                'niveau_importance' => $importance
            ]);


        } catch (\Exception $e) {
            Log::error('Erreur audit: ' . $e->getMessage(), [
                'action' => $action,
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Raccourcir les actions pour éviter les problèmes de longueur
     */
    private function shortenAction($action)
    {
        $mapping = [
            'CONNEXION' => 'LOGIN',
            'DECONNEXION' => 'LOGOUT',
            'DECONNEXION_TOTALE' => 'LOGOUT_ALL',
            'CREATION' => 'CREATE',
            'MODIFICATION' => 'UPDATE',
            'SUPPRESSION' => 'DELETE',
            'CHANGEMENT_MDP' => 'PWD_CHANGE',
            'COMPTE_VERROUILLE' => 'LOCK',
            '2FA_ACTIVEE' => '2FA_ON',
            '2FA_DESACTIVEE' => '2FA_OFF',
            'OUVERTURE' => 'OPEN',
            'FERMETURE' => 'CLOSE',
            'APPROVISIONNEMENT' => 'FUND',
            'RETRAIT' => 'WITHDRAW',
            'TRANSFERT' => 'TRANSFER',
            'BLOCAGE' => 'BLOCK',
            'DEBLOCAGE' => 'UNBLOCK',
            'ACTIVATION' => 'ACTIVATE',
            'DESACTIVATION' => 'DEACTIVATE'
        ];

        return $mapping[$action] ?? substr($action, 0, 20);
    }

    /**
     * Générer une description pour l'audit
     */
    protected function generateAuditDescription($action) 
    {
        $modelName = class_basename($this);
        $identifier = $this->getAuditIdentifier();
        
        return "{$action} sur {$modelName} - {$identifier}";
    }

    /**
     * Obtenir un identifiant pour l'audit
     */
    protected function getAuditIdentifier() 
    {
        if (isset($this->nom_complet)) {
            return $this->nom_complet;
        }
        
        if (isset($this->nom)) {
            return $this->nom;
        }
        
        if (isset($this->code)) {
            return $this->code;
        }
        
        if (isset($this->matricule)) {
            return $this->matricule;
        }
        
        return "ID: {$this->id}";
    }

    /**
     * Relation avec les audits
     */
    public function audits()
    {
        return $this->morphMany(AuditSysteme::class, 'auditable');
    }
}