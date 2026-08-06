<?php

namespace App\Models;

use App\Enums\BannerPlacement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A homepage advertisement banner (admin-managed). "Live" = active and within
 * its optional schedule window.
 */
class Banner extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'image_path',
        'link_url',
        'placement',
        'position',
        'starts_at',
        'ends_at',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'placement' => BannerPlacement::class,
            'position' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Banner $banner): void {
            if (empty($banner->uuid)) {
                $banner->uuid = (string) Str::uuid();
            }
        });
    }

    /** Active and within its schedule window right now. @param Builder<Banner> $q */
    public function scopeLive(Builder $q): Builder
    {
        return $q->where('is_active', true)
            ->where(fn (Builder $s) => $s->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $s) => $s->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }
}
