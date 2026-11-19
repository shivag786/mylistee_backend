<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One idea from the AI Sales Manager (kind `sales`) or the AI Customer Growth
 * Advisor (kind `growth`), waiting for the owner to approve or dismiss it.
 *
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $stats
 * @property array<string, mixed>|null $result
 */
class AiSuggestion extends Model
{
    public const KIND_SALES = 'sales';

    public const KIND_GROWTH = 'growth';

    public const SALES_TYPES = ['percent', 'flat', 'combo', 'bogo', 'happy_hour'];

    public const GROWTH_TYPES = ['win_back', 'first_order', 'loyalty', 'review_request', 'slow_hour_push', 'item_highlight'];

    protected $fillable = [
        'business_id', 'kind', 'batch', 'type', 'title', 'message', 'reasoning', 'payload', 'stats',
        'status', 'result', 'send_at', 'sent_at', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'stats' => 'array',
            'result' => 'array',
            'send_at' => 'datetime',
            'sent_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AiSuggestion $s): void {
            $s->uuid ??= (string) Str::uuid();
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
