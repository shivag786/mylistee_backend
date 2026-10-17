<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One personal-link package: trial, online purchase, admin free grant or admin
 * payment link. Written only by {@see \App\Services\PersonalLinkService}.
 */
class PersonalLinkGrant extends Model
{
    public const SOURCES = ['trial', 'online', 'admin_free', 'admin_link'];

    protected $fillable = [
        'business_id', 'source', 'status', 'pack', 'days', 'amount', 'payment_id',
        'link_id', 'link_url', 'note', 'granted_by', 'expires_before', 'expires_after', 'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'days' => 'integer',
            'amount' => 'decimal:2',
            'expires_before' => 'datetime',
            'expires_after' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PersonalLinkGrant $grant): void {
            $grant->uuid ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->uuid,
            'source' => $this->source,
            'status' => $this->status,
            'pack' => $this->pack,
            'days' => $this->days,
            'amount' => (float) $this->amount,
            'linkUrl' => $this->status === 'pending' ? $this->link_url : null,
            'note' => $this->note,
            'grantedBy' => $this->relationLoaded('grantedBy') ? $this->grantedBy?->name : null,
            'expiresBefore' => $this->expires_before?->toIso8601String(),
            'expiresAfter' => $this->expires_after?->toIso8601String(),
            'appliedAt' => $this->applied_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
