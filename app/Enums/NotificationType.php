<?php

namespace App\Enums;

/**
 * Categories of in-app / push notification (document/phase/07 §Notifications,
 * phase/10 §notifications). Drives the icon + deep link the client renders.
 */
enum NotificationType: string
{
    case RewardWon = 'reward_won';
    case RewardRedeemed = 'reward_redeemed';
    case SpinActivity = 'spin_activity';
    case OfferExpiring = 'offer_expiring';
    case OrderPlaced = 'order_placed';
    case OrderUpdate = 'order_update';
    case System = 'system';
}
