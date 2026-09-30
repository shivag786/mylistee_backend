<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses the PINs an attacker tries first.
 *
 * Sign-in locks a single number after five wrong PINs -- which does nothing
 * against the attack that matters here. Trying one common PIN, say 1234, once
 * against thousands of different numbers never trips any number's lockout, and
 * a large share of people choose one of a handful of PINs. With no OTP behind
 * it, the PIN is the whole of an account's protection, so the handful is simply
 * not allowed.
 *
 * Checked only when a PIN is chosen. Existing PINs keep working.
 */
class StrongPin implements ValidationRule
{
    /**
     * Common PINs that are neither a run nor a repeat, so the checks below miss
     * them: keypad patterns, pairs, and dates people pick.
     */
    private const COMMON = [
        '1212', '2121', '1122', '2211', '1010', '2020', '1313', '1414',
        '2580', '0852', '1379', '1397', '2468', '8642', '1470', '0741',
        '6969', '1004', '2000', '2001', '1999', '2525', '5683', '7890',
        '121212', '112233', '101010', '696969', '147258', '159753',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $pin = (string) $value;

        if (! preg_match('/^\d+$/', $pin)) {
            return; // the digits-only rule reports this one
        }

        if (preg_match('/^(\d)\1+$/', $pin)) {
            $fail('That PIN is too easy to guess -- avoid repeating one digit.');

            return;
        }

        if ($this->isRun($pin, 1) || $this->isRun($pin, -1)) {
            $fail('That PIN is too easy to guess -- avoid digits in a row like 1234.');

            return;
        }

        if (in_array($pin, self::COMMON, true)) {
            $fail('That PIN is too common. Please choose a less obvious one.');
        }
    }

    /** Every digit is the previous one plus $step -- 1234, 5678 or 9876. */
    private function isRun(string $pin, int $step): bool
    {
        for ($i = 1, $n = strlen($pin); $i < $n; $i++) {
            if ((int) $pin[$i] - (int) $pin[$i - 1] !== $step) {
                return false;
            }
        }

        return true;
    }
}
