<?php

namespace App\Models;

/**
 * Wording and arithmetic shared by every % / ₹ reward rule, so the shop page,
 * the cart and the owner's preview all say the same thing.
 */
final class RewardRule
{
    /** "20% off up to ₹100", "₹50 off". */
    public static function label(string $type, float $value, ?float $maxOff = null): string
    {
        $v = self::money($value);
        if ($type === 'percent') {
            return $maxOff !== null && $maxOff > 0 ? "{$v}% off up to ₹".self::money($maxOff) : "{$v}% off";
        }

        return "₹{$v} off";
    }

    /** What a rule takes off `$amount`, capped and never more than the amount. */
    public static function discount(array $rule, float $amount): float
    {
        $value = (float) ($rule['value'] ?? 0);
        $off = ($rule['type'] ?? 'flat') === 'percent' ? $amount * min(100.0, $value) / 100 : $value;
        $max = $rule['max_off'] ?? null;
        if ($max !== null && (float) $max > 0) {
            $off = min($off, (float) $max);
        }

        return round(max(0.0, min($off, $amount)), 2);
    }

    public static function money(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    /** 1st, 2nd, 3rd, 4th … 11th, 12th, 13th, 21st. */
    public static function ordinal(int $n): string
    {
        if (in_array($n % 100, [11, 12, 13], true)) {
            return "{$n}th";
        }

        return $n.(['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
    }
}
