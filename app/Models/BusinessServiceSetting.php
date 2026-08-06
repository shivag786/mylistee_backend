<?php

namespace App\Models;

use App\Enums\ServiceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A business's service configuration (hasOne). `modes` lists the enabled
 * ServiceType values; absent row ⇒ pickup-only. See Business::serviceModes().
 */
class BusinessServiceSetting extends Model
{
    protected $fillable = [
        'business_id',
        'modes',
        'default_mode',
        'delivery_fee',
    ];

    protected function casts(): array
    {
        return [
            'modes' => 'array',
            'delivery_fee' => 'decimal:2',
        ];
    }

    /** Enabled modes as ServiceType enums, always including Pickup as a floor. */
    public function enabledTypes(): array
    {
        $types = [];
        foreach ((array) $this->modes as $value) {
            $type = ServiceType::tryFrom((string) $value);
            if ($type !== null) {
                $types[$type->value] = $type;
            }
        }
        $types[ServiceType::Pickup->value] ??= ServiceType::Pickup;

        return array_values($types);
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
