<?php

namespace App\Enums;

/**
 * Lifecycle of a business listing (document/phase/02 §Admin Responsibilities —
 * approve/suspend). Businesses self-serve as Active in M4; the admin panel
 * (Milestone 14) can move them to Suspended.
 */
enum BusinessStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
    case Suspended = 'suspended';
    case Rejected = 'rejected';

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
