<?php

namespace App\Models\Concerns;

use App\Models\Utilisateur;
use Illuminate\Database\Eloquent\Builder;

trait PerimetreAgent
{
    public function scopeVisiblePour(Builder $q, Utilisateur $u): Builder
    {
        if ($u->hasRole(['SUPER_ADMIN', 'ADMIN_DSA'])) {
            return $q;
        }

        if ($u->hasRole('SUPERVISEUR_DSA')) {
            return $q->whereHas('agent', function ($a) use ($u) {
                $a->where('superviseur_id', $u->id)
                  ->orWhereHas('zone', fn ($z) => $z->where('superviseur_id', $u->id));
            });
        }

        // AGENT_DSA : uniquement ses propres demandes
        if ($u->agentDsa) {
            return $q->where('agent_dsa_id', $u->agentDsa->id);
        }

        return $q->whereRaw('1 = 0');
    }
}