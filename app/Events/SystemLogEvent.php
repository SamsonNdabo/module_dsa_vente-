<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SystemLogEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $log;
    public $utilisateurId;

    public function __construct($log, $utilisateurId)
    {
        $this->log = $log;
        $this->utilisateurId = $utilisateurId;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('system-logs.' . $this->utilisateurId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'system.log';
    }

    public function broadcastWith(): array
    {
        return [
            'log' => [
                'id' => $this->log->id,
                'action' => $this->log->action,
                'description' => $this->log->description,
                'ip_address' => $this->log->ip_address,
                'date' => $this->log->created_at->format('d/m/Y H:i:s'),
                'human_date' => $this->log->created_at->diffForHumans(),
            ],
            'timestamp' => now()->toDateTimeString(),
        ];
    }
}