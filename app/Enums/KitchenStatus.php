<?php

namespace App\Enums;

/**
 * Where an accepted order is in the kitchen. Separate from OrderStatus: the
 * order can be paid before or after the food is ready, and a shop without a
 * kitchen never sets it.
 */
enum KitchenStatus: string
{
    case Queued = 'queued';
    case Preparing = 'preparing';
    case Ready = 'ready';
}
