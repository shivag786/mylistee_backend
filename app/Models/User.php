<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'firebase_uid',
        'avatar_url',
        'phone',
        'dob',
        'gender',
        'anniversary',
        'pin',
        'pin_plain',
        'role',
        'status',
        'provider',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'pin',
        'pin_plain',
        'remember_token',
        'firebase_uid',
    ];

    /**
     * Bcrypt cost for PINs. Lower than passwords on purpose: a 4–8 digit PIN is
     * guarded by the sign-in lockout (5 tries, then 15 minutes), not by hash
     * cost, and every extra round made sign-in visibly slower on shared hosting.
     */
    public const PIN_ROUNDS = 10;

    /** PINs are hashed on the way in (an already-hashed value is kept). */
    protected function pin(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null || Hash::isHashed($value)
                ? $value
                : Hash::make($value, ['rounds' => self::PIN_ROUNDS]),
        );
    }

    /** A PIN stored with the old, slower cost, to be re-hashed at the next sign-in. */
    public function pinNeedsRehash(): bool
    {
        return $this->pin !== null && Hash::needsRehash($this->pin, ['rounds' => self::PIN_ROUNDS]);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'dob' => 'date',
            'anniversary' => 'date',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
        ];
    }

    /**
     * Assign a UUID on creation so the API never exposes the auto-increment id
     * (document/phase/11 §Public Identifiers).
     */
    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (empty($user->uuid)) {
                $user->uuid = (string) Str::uuid();
            }
        });
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function hasRole(UserRole $role): bool
    {
        return $this->role === $role;
    }

    /** @return HasMany<Business, $this> */
    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class, 'owner_id');
    }

    /** The owner's primary business (one per owner in the current plan), or null. */
    public function business(): ?Business
    {
        return $this->businesses()->latest('id')->first();
    }

    /** Rotating wallet tokens (Phase 7.3). @return HasMany<CustomerToken, $this> */
    public function customerTokens(): HasMany
    {
        return $this->hasMany(CustomerToken::class);
    }

    /** Orders placed by this customer (Phase 7.5). @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id');
    }

    /** @return HasMany<Reward, $this> */
    public function rewards(): HasMany
    {
        return $this->hasMany(Reward::class, 'customer_id');
    }

    /** Spins made by this customer (used by admin analytics). @return HasMany<Spin, $this> */
    public function spins(): HasMany
    {
        return $this->hasMany(Spin::class, 'customer_id');
    }

    /** People this customer celebrates (family, friends). @return HasMany<CustomerPerson, $this> */
    public function people(): HasMany
    {
        return $this->hasMany(CustomerPerson::class);
    }

    /** @return HasMany<Favorite, $this> */
    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class, 'customer_id');
    }

    /** @return HasMany<Notification, $this> */
    public function appNotifications(): HasMany
    {
        return $this->hasMany(Notification::class)->latest();
    }

    /** @return HasMany<DeviceToken, $this> */
    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    /** Listee Coins ledger entries for this customer. @return HasMany<WalletTransaction, $this> */
    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /** Current Listee Coins balance = sum of all ledger entries (never denormalized). */
    public function coinBalance(): int
    {
        return (int) $this->walletTransactions()->sum('amount');
    }
}
