<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BilanChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $action;
    public array $bilan;
    public string $timestamp;

    public function __construct(string $action, array $bilan)
    {
        $this->action = $action;
        $this->bilan = $bilan;
        $this->timestamp = now()->toIso8601String();
    }

    public function broadcastOn(): array
    {
        return [new Channel('bilan-updates')];
    }

    public function broadcastAs(): string
    {
        return 'bilan.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'bilan' => $this->bilan,
            'timestamp' => $this->timestamp,
        ];
    }
}