<?php

namespace App\Enums;

/**
 * Lifecycle of an owner-submitted category request (Phase 7.1). Pending until an
 * admin approves (creates the master category) or rejects it.
 */
enum CategoryRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }
}
