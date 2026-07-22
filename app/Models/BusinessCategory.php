<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class BusinessCategory extends Model
{
    /** @use HasFactory<\Database\Factories\BusinessCategoryFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'icon',
        'image_path',
        'description',
        'alt_text',
        'sort_order',
        'status',
        'show_on_homepage',
        'show_in_search',
    ];

    protected function casts(): array
    {
        return [
            'show_on_homepage' => 'boolean',
            'show_in_search' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (BusinessCategory $category): void {
            if (empty($category->uuid)) {
                $category->uuid = (string) Str::uuid();
            }
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    /** @return HasMany<Business, $this> */
    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class, 'category_id');
    }
}
