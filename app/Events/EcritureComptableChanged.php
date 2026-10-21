<?php

namespace App\Events;

use App\Models\EcrituresComptable;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EcritureComptableChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $action;
    public array $ecriture;
    public string $timestamp;

    public function __construct(string $action, EcrituresComptable $ecriture)
    {
        $this->action = $action;
        $this->ecriture = $ecriture->toBroadcastArray();
        $this->timestamp = now()->toIso8601String();
    }

    public function broadcastOn(): array
    {
        return [new Channel('ecritures-comptables')];
    }

    public function broadcastAs(): string
    {
        return 'ecriture.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'ecriture' => $this->ecriture,
            'timestamp' => $this->timestamp,
        ];
    }
}