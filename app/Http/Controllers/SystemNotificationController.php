<?php

namespace App\Http\Controllers;

use App\Models\NotificationComptable;
use App\Models\SysLogComptable;
use App\Models\EcrituresComptable;
use App\Models\EchecEcriture;
use App\Models\BudgetPrevision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Events\NotificationComptableEvent;
use App\Events\SystemLogEvent;

class SystemNotificationController extends Controller
{
    // =========================================================================
    // 📡 GET NOTIFICATIONS (PAGINÉES - 10 PAR PAGE)
    // =========================================================================

    public function getNotifications(Request $request)
    {
        $utilisateurId = Auth::id();
        $today = Carbon::now();
        $page = (int) $request->input('page', 1);
        $perPage = 10;

        // 0. NETTOYAGE AUTO
        $cleanedCount = $this->cleanOldNotifications($utilisateurId);
        if ($cleanedCount > 0) {
            broadcast(new NotificationComptableEvent(['count' => $cleanedCount], $utilisateurId, 'cleaned'))->toOthers();
        }

        // 1. Notifications paginées
        $query = NotificationComptable::where('utilisateur_id', $utilisateurId)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc');

        $total = $query->count();
        $notifications = $query->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get()
            ->map(function ($notif) {
                return [
                    'id' => $notif->id,
                    'type' => $notif->type,
                    'severity' => $notif->severity,
                    'icon' => $notif->icon,
                    'titre' => $notif->titre,
                    'message' => $notif->message,
                    'action_label' => $notif->action_label,
                    'action_url' => $notif->action_url,
                    'action_required' => $notif->action_required,
                    'metadata' => $notif->metadata,
                    'read' => !is_null($notif->read_at),
                    'read_at' => $notif->read_at ? $notif->read_at->toDateTimeString() : null,
                    'created_at' => $notif->created_at->toDateTimeString(),
                    'human_date' => $notif->created_at->diffForHumans(),
                ];
            });

        // 2. Notification virtuelle pour les prévisions en attente
        $totalPrevisionsAttente = BudgetPrevision::enAttente()->count();
        if ($totalPrevisionsAttente > 0 && $page === 1) {
            $notifications->prepend([
                'id' => 'previsions_attente',
                'type' => 'budget_alert',
                'severity' => 'warning',
                'icon' => '⏳',
                'titre' => 'Prévisions en attente de validation',
                'message' => $totalPrevisionsAttente . ' prévision(s) budgétaire(s) en attente de validation',
                'action_label' => 'Voir le détail',
                'action_url' => '/suivi-budgetaire/planification',
                'action_required' => true,
                'metadata' => ['total_previsions' => $totalPrevisionsAttente],
                'read' => false,
                'read_at' => null,
                'created_at' => $today->toDateTimeString(),
                'human_date' => 'À l\'instant',
            ]);
        }

        // 3. Écritures récentes
        $ecritures = $this->getRecentEcritures($utilisateurId);

        // 4. Échecs d'écriture
        $echecsEcritures = $this->getRecentEchecs($utilisateurId);

        // 5. Compteurs
        $totalEcritures = EcrituresComptable::where('id_utilisateur', $utilisateurId)->count();
        $totalEchecs = EchecEcriture::enAttente()->where('utilisateur_id', $utilisateurId)->count();
        $totalUnreadBdd = NotificationComptable::where('utilisateur_id', $utilisateurId)
            ->whereNull('read_at')->whereNull('deleted_at')->count();
        $totalUnread = $totalUnreadBdd + ($totalPrevisionsAttente > 0 ? 1 : 0);

        return response()->json([
            'success' => true,
            'data' => [
                'notifications' => $notifications,
                'recent_ecritures' => $ecritures,
                'echecs_ecritures' => $echecsEcritures,
                'total_unread' => $totalUnread,
                'total_ecritures' => $totalEcritures,
                'total_echecs' => $totalEchecs,
                'total_previsions_attente' => $totalPrevisionsAttente,
                'cleaned_count' => $cleanedCount,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'has_more' => ($page * $perPage) < $total,
                ],
                'last_update' => $today->toDateTimeString(),
            ]
        ]);
    }

    // =========================================================================
    // 📝 ÉCRITURES RÉCENTES
    // =========================================================================

    private function getRecentEcritures($utilisateurId)
    {
        return EcrituresComptable::with(['plan_comptable', 'entite'])
            ->where('id_utilisateur', $utilisateurId)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($ecriture) {
                return [
                    'id' => $ecriture->id,
                    'date_ecriture' => $ecriture->date_ecriture_formatee,
                    'reference' => $ecriture->reference,
                    'libelle' => $ecriture->libelle,
                    'debit' => number_format($ecriture->debit, 2, ',', ' ') . ' $',
                    'credit' => number_format($ecriture->credit, 2, ',', ' ') . ' $',
                    'compte' => $ecriture->plan_comptable ? $ecriture->plan_comptable->nom : $ecriture->compte_numero,
                    'entite' => $ecriture->entite ? $ecriture->entite->nom : 'N/A',
                    'statut' => 'success',
                    'created_at' => $ecriture->created_at ? $ecriture->created_at->diffForHumans() : 'N/A',
                ];
            });
    }

    // =========================================================================
    // ❌ ÉCHECS D'ÉCRITURE
    // =========================================================================

    private function getRecentEchecs($utilisateurId)
    {
        return EchecEcriture::with(['entite'])
            ->where('utilisateur_id', $utilisateurId)
            ->enAttente()
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($echec) {
                return [
                    'id' => $echec->id,
                    'date_ecriture' => $echec->date_ecriture_formatee,
                    'reference' => $echec->reference,
                    'libelle' => $echec->libelle,
                    'montant' => number_format($echec->montant, 2, ',', ' ') . ' $',
                    'raison_echec' => $echec->raison_echec,
                    'statut' => 'echec',
                    'statut_icone' => $echec->statut_icone,
                    'entite' => $echec->entite ? $echec->entite->code : 'N/A',
                    'created_at' => $echec->created_at ? $echec->created_at->diffForHumans() : 'N/A',
                ];
            });
    }

    // =========================================================================
    // 📋 LOGS SYSTÈME (PAGINÉS - 10 PAR PAGE)
    // =========================================================================

    public function getSystemLogs(Request $request)
    {
        $utilisateurId = Auth::id();
        $page = (int) $request->input('page', 1);
        $perPage = 10;

        $query = SysLogComptable::where('utilisateur_id', $utilisateurId)
            ->orderBy('created_at', 'desc');

        $total = $query->count();
        $logs = $query->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'description' => $log->description,
                    'ip_address' => $log->ip_address,
                    'user_agent' => $log->user_agent,
                    'utilisateur_nom' => $log->utilisateur
                        ? ($log->utilisateur->nom_complet ?? $log->utilisateur->nom_utilisateur ?? 'N/A')
                        : 'Système',
                    'date' => $log->created_at->format('d/m/Y H:i:s'),
                    'human_date' => $log->created_at->diffForHumans(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $logs,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => ($page * $perPage) < $total,
            ],
        ]);
    }

    // =========================================================================
    // 🗑️ SUPPRESSION AVEC CASCADE + BROADCAST TEMPS RÉEL
    // =========================================================================

    public function deleteNotification($id)
    {
        $notification = NotificationComptable::findOrFail($id);
        $utilisateurId = Auth::id();

        if ($notification->metadata && isset($notification->metadata['echec_id'])) {
            EchecEcriture::where('id', $notification->metadata['echec_id'])->delete();
        }

        $notification->delete();

        // LOG + BROADCAST (uniquement si suppression effective)
        $this->logAndBroadcast($utilisateurId, 'suppression_notification', "Notification supprimée");

        return response()->json(['success' => true]);
    }

    public function deleteMultipleNotifications(Request $request)
    {
        $ids = $request->input('ids', []);
        $utilisateurId = Auth::id();

        if (empty($ids)) {
            return response()->json(['success' => false, 'message' => 'Aucun ID fourni']);
        }

        $count = 0;
        $notifications = NotificationComptable::whereIn('id', $ids)->get();

        foreach ($notifications as $notification) {
            if ($notification->metadata && isset($notification->metadata['echec_id'])) {
                EchecEcriture::where('id', $notification->metadata['echec_id'])->delete();
            }
            $notification->delete();
            $count++;
        }

        // LOG + BROADCAST (uniquement si > 0)
        if ($count > 0) {
            $this->logAndBroadcast($utilisateurId, 'suppression_multiple_notifications', "{$count} notification(s) supprimée(s)");
        }

        return response()->json([
            'success' => true,
            'message' => "{$count} notification(s) supprimée(s)",
            'count' => $count,
        ]);
    }

    public function deleteAllEchecs()
    {
        $utilisateurId = Auth::id();
        $count = EchecEcriture::where('utilisateur_id', $utilisateurId)->enAttente()->delete();

        // LOG + BROADCAST (uniquement si > 0)
        if ($count > 0) {
            $this->logAndBroadcast($utilisateurId, 'suppression_tous_echecs', "{$count} échec(s) supprimé(s)");
        }

        return response()->json(['success' => true, 'message' => "{$count} échec(s) supprimé(s)", 'count' => $count]);
    }

    public function deleteAllLogs()
    {
        $utilisateurId = Auth::id();
        $count = SysLogComptable::where('utilisateur_id', $utilisateurId)->delete();

        // LOG + BROADCAST (uniquement si > 0)
        if ($count > 0) {
            $this->logAndBroadcast($utilisateurId, 'suppression_tous_logs', "{$count} log(s) supprimé(s)");
        }

        return response()->json(['success' => true, 'message' => "{$count} log(s) supprimé(s)", 'count' => $count]);
    }

    public function deleteAllRead()
    {
        $utilisateurId = Auth::id();
        $count = NotificationComptable::where('utilisateur_id', $utilisateurId)
            ->whereNotNull('read_at')
            ->delete();

        // LOG + BROADCAST (uniquement si > 0)
        if ($count > 0) {
            $this->logAndBroadcast($utilisateurId, 'nettoyage_notifications', "{$count} notification(s) supprimée(s)");
        }

        return response()->json(['success' => true, 'message' => "{$count} notification(s) supprimée(s)", 'count' => $count]);
    }

    // =========================================================================
    // 🗑️ NETTOYAGE AUTO
    // =========================================================================

    private function cleanOldNotifications($utilisateurId)
    {
        return NotificationComptable::where('utilisateur_id', $utilisateurId)
            ->whereNotNull('read_at')
            ->where('read_at', '<', Carbon::now()->subDays(5))
            ->delete();
    }

    // =========================================================================
    // ✅ MARQUER COMME LU
    // =========================================================================

    public function markAsRead(Request $request, $id)
    {
        $notification = NotificationComptable::findOrFail($id);
        $notification->markAsRead();
        $utilisateurId = Auth::id();

        // BROADCAST NOTIFICATION
        broadcast(new NotificationComptableEvent($notification, $utilisateurId, 'read'))->toOthers();

        return response()->json(['success' => true]);
    }

    public function markAllAsRead()
    {
        $utilisateurId = Auth::id();
        $count = NotificationComptable::where('utilisateur_id', $utilisateurId)
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now()]);

        // NE RIEN FAIRE SI 0 NOTIFICATION
        if ($count > 0) {
            // BROADCAST NOTIFICATION
            broadcast(new NotificationComptableEvent(['count' => 0], $utilisateurId, 'all_read'))->toOthers();

            // LOG + BROADCAST LOG
            $this->logAndBroadcast($utilisateurId, 'lecture_toutes_notifications', "{$count} notification(s) marquée(s) comme lue(s)");
        }

        return response()->json(['success' => true, 'count' => $count]);
    }

    // =========================================================================
    // 🔥 LOG + BROADCAST HELPER
    // =========================================================================

    private function logAndBroadcast($utilisateurId, $action, $description)
    {
        try {
            $log = SysLogComptable::create([
                'utilisateur_id' => $utilisateurId,
                'action' => $action,
                'description' => $description,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'created_at' => now(),
            ]);
            broadcast(new SystemLogEvent($log, $utilisateurId))->toOthers();
        } catch (\Exception $e) {
            \Log::error('❌ Erreur log + broadcast', ['error' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // 📤 EXPORT
    // =========================================================================

    public function exportNotifications(Request $request)
    {
        return response()->json(['success' => true, 'message' => 'Export généré']);
    }
}