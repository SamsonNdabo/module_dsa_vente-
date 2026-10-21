<?php

namespace App\Events;

use App\Models\PlanComptable;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlanComptableChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $action;
    public array $compte;
    public string $timestamp;

    public function __construct(string $action, PlanComptable $compte)
    {
        $this->action = $action;
        $this->compte = $compte->toBroadcastArray();
        $this->timestamp = now()->toIso8601String();
    }

    public function broadcastOn(): array
    {
        return [new Channel('plan-comptable')];
    }

    public function broadcastAs(): string
    {
        return 'compte.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'compte' => $this->compte,
            'timestamp' => $this->timestamp,
        ];
    }
}