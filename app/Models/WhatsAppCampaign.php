<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** One WhatsApp offer a shop sent to a slice of its customers. */
class WhatsAppCampaign extends Model
{
    protected $table = 'whatsapp_campaigns';

    protected $fillable = [
        'business_id',
        'template_id',
        'audience',
        'values',
        'recipients',
        'sent',
        'failed',
        'finished_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'values' => 'array',
            'recipients' => 'integer',
            'sent' => 'integer',
            'failed' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WhatsAppCampaign $campaign): void {
            $campaign->uuid ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<WhatsAppTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(WhatsAppTemplate::class, 'template_id');
    }
}
