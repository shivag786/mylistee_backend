<?php

namespace App\Enums;

/**
 * Lifecycle of a business subscription (document/phase/14 §Subscription
 * Management). The free plan sits in Active with no end date.
 */
enum SubscriptionStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
