<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** An AI add-on pack the admin sells: N extra AI answers for a price. */
class AiCreditPack extends Model
{
    protected $fillable = ['name', 'answers', 'price', 'is_active', 'position'];

    protected function casts(): array
    {
        return [
            'answers' => 'integer',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $pack): void {
            $pack->uuid ??= (string) Str::uuid();
        });
    }
}
