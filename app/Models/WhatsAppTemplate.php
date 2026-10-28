<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A WhatsApp message template on the platform's Interakt account. Made in the
 * admin panel (and submitted for Meta's approval) or synced from Interakt.
 * Only an APPROVED template can be sent. See {@see \App\Services\WhatsApp\WhatsAppTemplateService}.
 */
class WhatsAppTemplate extends Model
{
    protected $table = 'whatsapp_templates';

    public const APPROVED = 'APPROVED';

    protected $fillable = [
        'name',
        'display_name',
        'language',
        'category',
        'header_format',
        'header',
        'header_media_url',
        'body',
        'footer',
        'buttons',
        'samples',
        'variables',
        'for_shops',
        'status',
        'rejection_reason',
        'interakt_id',
        'wa_template_id',
        'source',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'buttons' => 'array',
            'samples' => 'array',
            'variables' => 'array',
            'for_shops' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WhatsAppTemplate $template): void {
            $template->uuid ??= (string) Str::uuid();
        });
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    /** @param  Builder<WhatsAppTemplate>  $query */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', self::APPROVED);
    }

    /** How many {{n}} placeholders a piece of text has (the highest n). */
    public static function countVars(?string $text): int
    {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', (string) $text, $m);

        return $m[1] === [] ? 0 : (int) max($m[1]);
    }
}
