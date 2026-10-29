<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Someone a customer celebrates — family or a friend — with their dates. */
class CustomerPerson extends Model
{
    protected $table = 'customer_people';

    protected $fillable = [
        'user_id', 'name', 'relation', 'gender', 'dob', 'anniversary',
        'dob_reminded_year', 'anniversary_reminded_year',
    ];

    protected function casts(): array
    {
        return [
            'dob' => 'date',
            'anniversary' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CustomerPerson $person): void {
            $person->uuid ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'relation' => $this->relation,
            'gender' => $this->gender,
            'dob' => $this->dob?->toDateString(),
            'anniversary' => $this->anniversary?->toDateString(),
        ];
    }
}
