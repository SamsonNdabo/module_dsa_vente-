<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BudgetAlertEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $alert;
    public $utilisateurId;

    /**
     * Create a new event instance.
     */
    public function __construct($alert, $utilisateurId)
    {
        $this->alert = $alert;
        $this->utilisateurId = $utilisateurId;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('budget-alerts.' . $this->utilisateurId),
            new PrivateChannel('comptables'),
        ];
    }

    /**
     * Nom de l'événement
     */
    public function broadcastAs(): string
    {
        return 'budget.alert';
    }

    /**
     * Données à diffuser
     */
    public function broadcastWith(): array
    {
        return [
            'alert' => $this->alert,
            'timestamp' => now()->toDateTimeString(),
        ];
    }
}