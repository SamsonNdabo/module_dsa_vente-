<?php

namespace App\Enums;

enum AgentStatut: string
{
    case ACTIF = 'ACTIF';
    case SUSPENDU = 'SUSPENDU';
    case INACTIF = 'INACTIF';
    case RESILIE = 'RESILIE';

    /** Liste fermée des transitions autorisées (cahier des charges §19). */
    public function transitions(): array
    {
        return match ($this) {
            self::ACTIF => [self::SUSPENDU, self::INACTIF, self::RESILIE],
            self::SUSPENDU => [self::ACTIF, self::INACTIF, self::RESILIE],
            self::INACTIF => [self::ACTIF, self::RESILIE],
            self::RESILIE => [],
        };
    }

    public function peutPasserA(self $cible): bool
    {
        return in_array($cible, $this->transitions(), true);
    }
}
