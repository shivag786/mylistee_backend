<?php

namespace App\Models;

use App\Enums\CoinSource;
use App\Enums\CoinTransactionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * A single Listee Coins ledger entry (Phase 2). Append-only — the balance is
 * derived by summing `amount`, so entries are never edited or deleted in normal
 * operation. See the wallet_transactions migration.
 */
class WalletTransaction extends Model
{
    /** @use HasFactory<\Database\Factories\WalletTransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'business_id',
        'type',
        'source',
        'amount',
        'balance_after',
        'description',
        'reference_type',
        'reference_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'type' => CoinTransactionType::class,
            'source' => CoinSource::class,
            'amount' => 'integer',
            'balance_after' => 'integer',
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WalletTransaction $txn): void {
            if (empty($txn->uuid)) {
                $txn->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return MorphTo<Model, $this> */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
