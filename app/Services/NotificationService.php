<?php

namespace App\Services;

use App\Models\NotificationComptable;
use App\Models\Utilisateur;
use App\Events\NotificationComptableEvent;
use App\Mail\GenericNotificationMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    const ROLES_EMAIL = ['CHEF-COMPTABLE'];

    // =========================================================================
    // MÉTHODES PUBLIQUES
    // =========================================================================

    public function notifierSysteme(int $utilisateurId, string $titre, string $message, array $options = []): ?NotificationComptable
    {
        return $this->creerNotification($utilisateurId, $titre, $message, $options);
    }

    /**
     * Notification système pour l'utilisateur connecté + Email à TOUS les CHEF-COMPTABLE
     */
    public function notifierAvecEmail(int $utilisateurId, string $titre, string $message, array $options = []): ?NotificationComptable
    {
        Log::info('📢 notifierAvecEmail appelé', ['user_id' => $utilisateurId, 'titre' => $titre]);

        // 1. Notification système
        $notification = $this->creerNotification($utilisateurId, $titre, $message, $options);

        // 2. Email à l'utilisateur connecté (s'il est CHEF-COMPTABLE)
        $this->envoyerEmailUtilisateur($utilisateurId, $titre, $message);

        // 3. Email aux AUTRES CHEF-COMPTABLE
        $this->envoyerEmailAuxRoles($titre, $message, $utilisateurId);

        return $notification;
    }

    /**
     * Email UNIQUEMENT à TOUS les CHEF-COMPTABLE
     */
    public function notifierEmailSeulement(string $titre, string $message): void
    {
        Log::info('📢 notifierEmailSeulement appelé', ['titre' => $titre]);
        $this->envoyerEmailAuxRoles($titre, $message, null);
    }

    public function notifierParRole(string $roleCode, string $titre, string $message, array $options = []): void
    {
        $utilisateurs = Utilisateur::whereHas('role', fn($q) => $q->where('code', $roleCode))->where('statut', 'ACTIF')->get();
        foreach ($utilisateurs as $user) { 
            $this->creerNotification($user->id, $titre, $message, $options); 
        }
    }

    public function notifierParRoleAvecEmail(string $roleCode, string $titre, string $message, array $options = []): void
    {
        $utilisateurs = Utilisateur::whereHas('role', fn($q) => $q->where('code', $roleCode))->where('statut', 'ACTIF')->get();
        foreach ($utilisateurs as $user) { 
            $this->creerNotification($user->id, $titre, $message, $options); 
            $this->envoyerEmail($user, $titre, $message); 
        }
    }

    public function notifierPrevisionEnAttente(int $utilisateurId, string $rubrique, string $mois, float $montant): void
    {
        $titre = 'Prévision à valider';
        $message = "{$rubrique} - {$mois} : " . number_format($montant, 2, ',', ' ') . " $";
        $exists = NotificationComptable::where('utilisateur_id', $utilisateurId)
            ->where('titre', $titre)
            ->where('message', $message)
            ->whereNull('read_at')
            ->where('created_at', '>', now()->subHours(1))
            ->exists();

        if (!$exists) {
            $this->creerNotification($utilisateurId, $titre, $message, [
                'type' => 'budget_alert', 
                'severity' => 'warning', 
                'icon' => '⏳', 
                'action_label' => 'Voir le détail', 
                'action_url' => '/suivi-budgetaire#planification', 
                'action_required' => true, 
                'metadata' => ['rubrique' => $rubrique, 'mois' => $mois, 'montant' => $montant]
            ]);
        }
    }

    public function notifierEchecEcriture(int $utilisateurId, string $raison, float $montant, string $libelle = ''): void
    {
        $msg = $libelle ? "{$raison} - {$libelle} - " . number_format($montant, 2, ',', ' ') . " $" : "{$raison} - " . number_format($montant, 2, ',', ' ') . " $";
        $this->creerNotification($utilisateurId, "Échec d'écriture", $msg, ['type' => 'anomalie', 'severity' => 'danger', 'icon' => '❌']);
    }

    public function notifierValidationPrevision(int $utilisateurId, string $rubrique, string $mois, float $montant): void
    {
        $this->creerNotification($utilisateurId, 'Prévision validée', "{$rubrique} - {$mois} : " . number_format($montant, 2, ',', ' ') . " $", ['type' => 'budget_alert', 'severity' => 'success', 'icon' => '✅']);
    }

    public function notifierCreationEcriture(int $utilisateurId, string $libelle, string $reference, float $montant): void
    {
        $this->creerNotification($utilisateurId, 'Nouvelle écriture comptable', "{$libelle} - Réf: {$reference} - " . number_format($montant, 2, ',', ' ') . " $", ['type' => 'ecriture', 'severity' => 'info', 'icon' => '📝', 'action_label' => 'Voir', 'action_url' => '/ecritures']);
    }

    // =========================================================================
    // MÉTHODES PRIVÉES
    // =========================================================================

    private function creerNotification(int $utilisateurId, string $titre, string $message, array $options = []): ?NotificationComptable
    {
        try {
            $notification = NotificationComptable::create([
                'utilisateur_id' => $utilisateurId,
                'type' => $options['type'] ?? 'system_alert',
                'severity' => $options['severity'] ?? 'info',
                'icon' => $options['icon'] ?? '📌',
                'titre' => $titre,
                'message' => $message,
                'action_label' => $options['action_label'] ?? null,
                'action_url' => $options['action_url'] ?? null,
                'action_required' => $options['action_required'] ?? false,
                'metadata' => $options['metadata'] ?? null,
            ]);
            broadcast(new NotificationComptableEvent($notification, $utilisateurId, 'created'))->toOthers();
            Log::info('📬 Notification créée', ['user_id' => $utilisateurId, 'type' => $notification->type, 'titre' => $titre]);
            return $notification;
        } catch (\Exception $e) {
            Log::error('❌ Erreur création notification', ['error' => $e->getMessage(), 'user_id' => $utilisateurId]);
            return null;
        }
    }

    private function envoyerEmailUtilisateur(int $utilisateurId, string $titre, string $message): void
    {
        $user = Utilisateur::with('role')->find($utilisateurId);
        if ($user && $user->email && $this->peutRecevoirEmail($user)) {
            Log::info('📧 Email à l\'utilisateur connecté (CHEF-COMPTABLE)', ['user_id' => $user->id, 'email' => $user->email]);
            $this->envoyerEmail($user, $titre, $message);
        } else {
            Log::info('ℹ️ Utilisateur connecté n\'est pas CHEF-COMPTABLE, pas d\'email direct', ['user_id' => $utilisateurId]);
        }
    }

    private function envoyerEmailAuxRoles(string $titre, string $message, ?int $exceptUserId = null): void
    {
        Log::info('🔍 Envoi email à tous les CHEF-COMPTABLE', ['except_user_id' => $exceptUserId]);

        $utilisateurs = Utilisateur::with('role')
            ->whereHas('role', fn($q) => $q->whereIn('code', self::ROLES_EMAIL))
            ->where('statut', 'ACTIF')
            ->whereNotNull('email')
            ->when($exceptUserId, fn($q) => $q->where('id', '!=', $exceptUserId))
            ->get();

        Log::info('📊 CHEF-COMPTABLE trouvés', ['count' => $utilisateurs->count(), 'emails' => $utilisateurs->pluck('email')->toArray()]);

        if ($utilisateurs->isEmpty()) {
            Log::warning('⚠️ Aucun CHEF-COMPTABLE avec email trouvé');
            return;
        }

        foreach ($utilisateurs as $user) {
            $this->envoyerEmail($user, $titre, $message);
        }
    }

    private function envoyerEmail(Utilisateur $user, string $titre, string $message): void
    {
        try {
            // Passe l'objet Utilisateur complet et les variables au Mailable
            Mail::to($user->email)->send(new GenericNotificationMail($titre, $message, $user));
            Log::info('📧 Email envoyé', ['user_id' => $user->id, 'email' => $user->email, 'titre' => $titre]);
        } catch (\Exception $e) {
            Log::error('❌ Erreur envoi email', ['error' => $e->getMessage(), 'user_id' => $user->id, 'email' => $user->email]);
        }
    }

    private function peutRecevoirEmail(Utilisateur $user): bool
    {
        return $user->role && in_array($user->role->code, self::ROLES_EMAIL);
    }
}