<?php

namespace App\Enums;

/**
 * Promotion lifecycle (07A §Promotion Status). Transitions are driven
 * automatically by `promotions:tick` (auto start/stop) or manually by the owner
 * (pause / resume).
 */
enum PromotionStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Running = 'running';
    case Paused = 'paused';
    case Expired = 'expired';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
