<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** A question or sign-up request sent from the public website. */
class Enquiry extends Model
{
    protected $fillable = [
        'name', 'phone', 'email', 'city', 'business_name', 'business_type',
        'topic', 'message', 'source', 'status', 'notes', 'ip',
    ];

    protected static function booted(): void
    {
        static::creating(function (Enquiry $e): void {
            if (empty($e->uuid)) {
                $e->uuid = (string) Str::uuid();
            }
        });
    }
}
