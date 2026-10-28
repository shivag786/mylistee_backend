<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** One WhatsApp message sent through Interakt, and how far it got. */
class WhatsAppMessage extends Model
{
    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'template_id',
        'business_id',
        'campaign_id',
        'purpose',
        'phone',
        'status',
        'error',
        'interakt_message_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (WhatsAppMessage $message): void {
            $message->uuid ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<WhatsAppTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(WhatsAppTemplate::class, 'template_id');
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
