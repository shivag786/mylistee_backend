<?php

namespace App\Enums;

/**
 * Account lifecycle state. Only Active users may authenticate; Suspended is
 * used by the admin panel (Milestone 14) to disable an account without
 * deleting it (document/phase/02 §Account Status).
 */
enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Blocked = 'blocked';
    case Pending = 'pending';

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
