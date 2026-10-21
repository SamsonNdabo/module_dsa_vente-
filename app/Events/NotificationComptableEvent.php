<?php

namespace App\Events;

use App\Models\NotificationComptable;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationComptableEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $notification;
    public $utilisateurId;
    public $action;
    public $timestamp;

    public function __construct($notification, $utilisateurId, $action = 'created')
    {
        $this->notification = $notification;
        $this->utilisateurId = $utilisateurId;
        $this->action = $action;
        $this->timestamp = now()->toDateTimeString();
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('notifications.' . $this->utilisateurId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'notification.comptable';
    }

    public function broadcastWith(): array
    {
        $data = [
            'event_action' => $this->action,
            'timestamp' => $this->timestamp,
        ];

        if ($this->notification && !is_array($this->notification)) {
            $data['notification'] = [
                'id' => $this->notification->id,
                'utilisateur_id' => $this->notification->utilisateur_id,
                'type' => $this->notification->type,
                'severity' => $this->notification->severity,
                'icon' => $this->notification->icon,
                'titre' => $this->notification->titre,
                'message' => $this->notification->message,
                'action_label' => $this->notification->action_label,
                'action_url' => $this->notification->action_url,
                'action_required' => $this->notification->action_required,
                'metadata' => $this->notification->metadata,
                'read' => !is_null($this->notification->read_at),
                'read_at' => $this->notification->read_at ? $this->notification->read_at->toDateTimeString() : null,
                'created_at' => $this->notification->created_at->toDateTimeString(),
                'human_date' => $this->notification->created_at->diffForHumans(),
            ];
        }

        return $data;
    }

    public function broadcastWhen(): bool
    {
        return true;
    }
}