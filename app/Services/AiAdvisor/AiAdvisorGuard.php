<?php

namespace App\Services\AiAdvisor;

use App\Models\AiSuggestion;

/**
 * The safety rules (config/ai_advisor.php `rules`) applied to everything the
 * AI suggests, and again to the owner's edits before approving. A suggestion
 * is fixed when it can be (a 40% offer becomes 30%, a too-cheap combo is
 * priced up) and dropped when it cannot (no valid products left). Every fix
 * is listed in `adjustments` so the owner sees what changed and why.
 */
class AiAdvisorGuard
{
    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public const SEGMENTS = ['everyone', 'followers', 'customers', 'lapsed'];

    /**
     * @param  array<string, mixed>  $raw  one suggestion as the AI (or the owner) wrote it
     * @param  array<int, array{id: int, name: string, price: float}>  $catalog  keyed by product id
     * @return array<string, mixed>|null the safe suggestion, or null to drop it
     */
    public function sales(array $raw, array $catalog): ?array
    {
        $type = $this->str($raw['type'] ?? '', 20);
        $type = ['percentage' => 'percent', 'happyhour' => 'happy_hour', 'happy hour' => 'happy_hour', 'buy_x_get_y' => 'bogo'][$type] ?? $type;
        if (! in_array($type, AiSuggestion::SALES_TYPES, true)) {
            return null;
        }

        $max = $this->maxPercent();
        $notes = [];
        $ids = $this->productIds($raw['product_ids'] ?? $raw['productIds'] ?? [], $catalog);
        $value = is_numeric($raw['value'] ?? null) ? (float) $raw['value'] : null;
        $minOrder = is_numeric($raw['min_order'] ?? $raw['minOrder'] ?? null) ? min(100000.0, max(0.0, (float) ($raw['min_order'] ?? $raw['minOrder']))) : null;
        [$from, $to] = $this->window($raw['time_window'] ?? $raw['timeWindow'] ?? null, $raw['time_from'] ?? $raw['timeFrom'] ?? null, $raw['time_to'] ?? $raw['timeTo'] ?? null);

        $out = [
            'type' => $type,
            'title' => $this->str($raw['title'] ?? '', 120),
            'message' => $this->str($raw['message'] ?? '', 300),
            'reasoning' => $this->str($raw['reasoning'] ?? '', 600),
            'expectedImpact' => $this->str($raw['expected_impact'] ?? $raw['expectedImpact'] ?? '', 200),
            'productIds' => $ids,
            'value' => null,
            'minOrder' => $minOrder,
            'days' => $this->days($raw['days'] ?? []),
            'timeFrom' => $from,
            'timeTo' => $to,
            'validityDays' => $this->validity($raw['validity_days'] ?? $raw['validityDays'] ?? $raw['validity'] ?? null),
            'buyQty' => null,
            'getQty' => null,
        ];
        if ($out['title'] === '') {
            return null;
        }

        switch ($type) {
            case 'percent':
            case 'happy_hour':
                if ($type === 'happy_hour' && ($ids === [] || $from === null)) {
                    return null; // a happy hour needs items and a time window
                }
                $value ??= $type === 'happy_hour' ? 15.0 : null;
                if ($value === null || $value <= 0) {
                    return null;
                }
                if ($value > $max) {
                    $notes[] = "Discount lowered from {$this->n($value)}% to {$max}% (the safe limit).";
                    $value = (float) $max;
                }
                $out['value'] = round($value, 2);
                break;

            case 'flat':
                if ($value === null || $value <= 0) {
                    return null;
                }
                if ($ids !== []) {
                    // ₹ off each item: never more than the safe share of the cheapest one.
                    $cheapest = min(array_map(fn ($id) => $catalog[$id]['price'], $ids));
                    $cap = floor($cheapest * $max / 100);
                    if ($cap < 1) {
                        return null;
                    }
                    if ($value > $cap) {
                        $notes[] = "₹ off lowered from ₹{$this->n($value)} to ₹{$this->n($cap)} ({$max}% of the cheapest item).";
                        $value = $cap;
                    }
                } else {
                    // ₹ off the bill: the bill must be big enough that it stays within the limit.
                    $needed = ceil($value * 100 / $max / 10) * 10;
                    if ($out['minOrder'] === null || $out['minOrder'] < $needed) {
                        $notes[] = "Minimum bill set to ₹{$this->n($needed)} so ₹{$this->n($value)} off stays within {$max}%.";
                        $out['minOrder'] = (float) $needed;
                    }
                }
                $out['value'] = round($value, 2);
                break;

            case 'combo':
                // Quantities: {"items":[{"id":5,"qty":2}]} from the AI, or {"quantities":{"5":2}} after saving.
                $qty = [];
                foreach (is_array($raw['items'] ?? null) ? $raw['items'] : [] as $it) {
                    $id = is_array($it) ? (int) ($it['id'] ?? $it['product_id'] ?? 0) : 0;
                    if (isset($catalog[$id])) {
                        $qty[$id] = max(1, min(5, (int) ($it['qty'] ?? $it['quantity'] ?? 1)));
                        if (! in_array($id, $ids, true)) {
                            $ids[] = $id;
                        }
                    }
                }
                foreach (is_array($raw['quantities'] ?? null) ? $raw['quantities'] : [] as $id => $q) {
                    if (in_array((int) $id, $ids, true)) {
                        $qty[(int) $id] = max(1, min(5, (int) $q));
                    }
                }
                $ids = array_slice($ids, 0, 5);
                $pieces = array_sum(array_map(fn ($id) => $qty[$id] ?? 1, $ids));
                if ($pieces < 2) {
                    return null;
                }
                $out['productIds'] = $ids;
                $out['quantities'] = array_map(fn ($id) => $qty[$id] ?? 1, array_combine($ids, $ids));
                $total = array_sum(array_map(fn ($id) => $catalog[$id]['price'] * ($qty[$id] ?? 1), $ids));
                $floor = ceil($total * $this->comboRatio());
                $price = is_numeric($raw['combo_price'] ?? $raw['comboPrice'] ?? null) ? (float) ($raw['combo_price'] ?? $raw['comboPrice']) : $value;
                if ($price === null || $price <= 0 || $price >= $total) {
                    $new = max($floor, floor($total * 0.9));
                    if ($price !== null && $price > 0) {
                        $notes[] = "Combo price ₹{$this->n($price)} was not below the items’ total ₹{$this->n($total)}, so it is set to ₹{$this->n($new)}.";
                    }
                    $price = $new;
                } elseif ($price < $floor) {
                    $notes[] = "Combo price raised from ₹{$this->n($price)} to ₹{$this->n($floor)} (at least ".round($this->comboRatio() * 100)."% of ₹{$this->n($total)}).";
                    $price = $floor;
                }
                $out['value'] = (float) $price;
                $out['itemsTotal'] = round($total, 2);
                break;

            case 'bogo':
                if ($ids === []) {
                    return null;
                }
                $buy = max(1, min(10, (int) ($raw['buy_qty'] ?? $raw['buyQty'] ?? 1)));
                $get = max(1, min(5, (int) ($raw['get_qty'] ?? $raw['getQty'] ?? 1)));
                $was = "{$buy}+{$get}";
                // The free share (get ÷ (buy + get)) must stay within the limit.
                while ($get / ($buy + $get) * 100 > $max) {
                    $buy++;
                }
                if ("{$buy}+{$get}" !== $was) {
                    $notes[] = "Changed to Buy {$buy} Get {$get} free so the free share stays within {$max}%.";
                }
                $out['buyQty'] = $buy;
                $out['getQty'] = $get;
                break;
        }

        // What the offer can carry: combos and bill coupons have no hours or
        // weekdays, item offers have no minimum bill.
        $billLevel = in_array($type, ['percent', 'flat'], true) && $out['productIds'] === [];
        if (($type === 'combo' || $billLevel) && ($out['timeFrom'] !== null || $out['days'] !== [])) {
            $notes[] = ($type === 'combo' ? 'Combos' : 'Bill offers').' run all day, every day — the time / day limit was removed.';
            [$out['timeFrom'], $out['timeTo'], $out['days']] = [null, null, []];
        }
        if (! $billLevel && $out['minOrder'] !== null) {
            $out['minOrder'] = null;
        }

        // Keep the customer text in step with a changed price or percentage.
        $original = is_numeric($raw['value'] ?? null) ? (float) $raw['value'] : null;
        if ($original !== null && $out['value'] !== null && abs($original - (float) $out['value']) > 0.001) {
            $old = $this->n($original);
            $new = $this->n((float) $out['value']);
            $out['message'] = preg_replace(['/₹\s?'.preg_quote($old, '/').'\b/u', '/\b'.preg_quote($old, '/').'\s?%/u'], ['₹'.$new, $new.'%'], $out['message']) ?? $out['message'];
        }

        $out['adjustments'] = $notes;

        return $out;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    public function growth(array $raw): ?array
    {
        $type = $this->str($raw['type'] ?? '', 30);
        if (! in_array($type, AiSuggestion::GROWTH_TYPES, true)) {
            return null;
        }
        $segment = $this->str($raw['segment'] ?? $raw['target_segment'] ?? $raw['targetSegment'] ?? '', 20);
        if (! in_array($segment, self::SEGMENTS, true)) {
            $segment = ['win_back' => 'lapsed', 'first_order' => 'followers', 'review_request' => 'customers'][$type] ?? 'everyone';
        }

        $out = [
            'type' => $type,
            'title' => $this->str($raw['title'] ?? '', 120),
            'pushTitle' => $this->str($raw['push_title'] ?? $raw['pushTitle'] ?? $raw['title'] ?? '', 60),
            'message' => $this->str($raw['message'] ?? '', 180),
            'reasoning' => $this->str($raw['reasoning'] ?? '', 600),
            'expectedResult' => $this->str($raw['expected_result'] ?? $raw['expectedResult'] ?? '', 200),
            'segment' => $segment,
            'sendTime' => $this->time($raw['best_send_time'] ?? $raw['bestSendTime'] ?? $raw['send_time'] ?? $raw['sendTime'] ?? null),
            'offer' => null,
            'adjustments' => [],
        ];
        if ($out['title'] === '' || $out['message'] === '') {
            return null;
        }

        $offer = $raw['offer'] ?? null;
        if (is_array($offer) && is_numeric($offer['value'] ?? null) && (float) $offer['value'] > 0) {
            $kind = in_array($offer['type'] ?? $offer['kind'] ?? '', ['flat', 'rupees', 'amount'], true) ? 'flat' : 'percent';
            $value = (float) $offer['value'];
            $min = is_numeric($offer['min_order'] ?? $offer['minOrder'] ?? null) ? max(0.0, (float) ($offer['min_order'] ?? $offer['minOrder'])) : null;
            $max = $this->maxPercent();
            if ($kind === 'percent' && $value > $max) {
                $out['adjustments'][] = "Offer lowered from {$this->n($value)}% to {$max}%.";
                $value = (float) $max;
            }
            if ($kind === 'flat') {
                $needed = ceil($value * 100 / $max / 10) * 10;
                if ($min === null || $min < $needed) {
                    $out['adjustments'][] = "Minimum bill set to ₹{$this->n($needed)} for ₹{$this->n($value)} off.";
                    $min = (float) $needed;
                }
            }
            $out['offer'] = [
                'kind' => $kind,
                'value' => round($value, 2),
                'minOrder' => $min,
                'validityDays' => $this->validity($offer['validity_days'] ?? $offer['validityDays'] ?? null),
            ];
        }

        return $out;
    }

    public function maxPercent(): int
    {
        return max(1, min(90, (int) config('ai_advisor.rules.max_discount_percent', 30)));
    }

    private function comboRatio(): float
    {
        return max(0.5, min(1.0, (float) config('ai_advisor.rules.min_combo_price_ratio', 0.8)));
    }

    /**
     * Only ids of this shop's visible products; anything else is dropped.
     *
     * @param  array<int, array<string, mixed>>  $catalog
     * @return list<int>
     */
    private function productIds(mixed $ids, array $catalog): array
    {
        $ids = is_array($ids) ? $ids : [$ids];
        $clean = [];
        foreach ($ids as $id) {
            $id = is_string($id) ? (int) preg_replace('/\D/', '', $id) : (is_int($id) ? $id : 0);
            if ($id > 0 && isset($catalog[$id]) && ! in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }

        return array_slice($clean, 0, max(1, (int) config('ai_advisor.rules.max_products_per_offer', 10)));
    }

    /** @return list<string> */
    private function days(mixed $days): array
    {
        $out = [];
        foreach (is_array($days) ? $days : [$days] as $d) {
            $d = substr(strtolower(trim((string) $d)), 0, 3);
            if (in_array($d, self::DAYS, true) && ! in_array($d, $out, true)) {
                $out[] = $d;
            }
        }

        return count($out) === 7 ? [] : array_values(array_intersect(self::DAYS, $out));
    }

    /** @return array{0: ?string, 1: ?string} */
    private function window(mixed $window, mixed $from, mixed $to): array
    {
        if (is_string($window) && preg_match('/(\d{1,2}(?::\d{2})?\s*(?:am|pm)?)\s*(?:-|–|to)\s*(\d{1,2}(?::\d{2})?\s*(?:am|pm)?)/iu', $window, $m)) {
            [$from, $to] = [$m[1], $m[2]];
        } elseif (is_array($window)) {
            $from ??= $window['from'] ?? $window['start'] ?? null;
            $to ??= $window['to'] ?? $window['end'] ?? null;
        }
        $from = $this->time($from);
        $to = $this->time($to);

        return $from !== null && $to !== null && $from < $to ? [$from, $to] : [null, null];
    }

    /** "4 pm", "16", "16:30" → "16:30"; null when unreadable. */
    private function time(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        if (! preg_match('/^\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\s*$/i', (string) $value, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
        $ampm = strtolower($m[3] ?? '');
        if ($ampm === 'pm' && $h < 12) {
            $h += 12;
        }
        if ($ampm === 'am' && $h === 12) {
            $h = 0;
        }

        return $h <= 23 && $min <= 59 ? sprintf('%02d:%02d', $h, $min) : null;
    }

    private function validity(mixed $days): int
    {
        $min = (int) config('ai_advisor.rules.min_validity_days', 1);
        $max = (int) config('ai_advisor.rules.max_validity_days', 30);

        return is_numeric($days) ? max($min, min($max, (int) $days)) : min($max, max($min, 7));
    }

    private function str(mixed $v, int $max): string
    {
        return is_scalar($v) ? mb_substr(trim(preg_replace('/\s+/', ' ', (string) $v) ?? ''), 0, $max) : '';
    }

    private function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
