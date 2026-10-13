<?php

namespace Tests\Unit;

use App\Models\Business;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * "Open now" is judged against the shop's local wall clock (Asia/Kolkata), not
 * the UTC app clock — a 5½-hour skew made shops show open/closed wrongly.
 */
class BusinessOpenNowTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{string, string, ?string, ?string, bool}> */
    public static function cases(): array
    {
        return [
            // IST time, opens, closes, expected
            'morning just after opening' => ['08:05', '08:00', '22:00', true],
            'before opening' => ['07:30', '08:00', '22:00', false],
            'late night after closing' => ['23:00', '08:00', '22:00', false],
            'at closing minute' => ['22:00', '08:00', '22:00', false],
            'overnight, after midnight' => ['01:00', '18:00', '02:00', true],
            'overnight, afternoon' => ['15:00', '18:00', '02:00', false],
            'closes at midnight, evening' => ['23:30', '09:00', '00:00', true],
            'open 24 hours' => ['04:00', '00:00', '00:00', true],
            'no hours set' => ['12:00', null, null, false],
        ];
    }

    #[DataProvider('cases')]
    public function test_open_now_uses_shop_local_time(string $ist, ?string $open, ?string $close, bool $expected): void
    {
        config(['app.business_timezone' => 'Asia/Kolkata']);
        Carbon::setTestNow(Carbon::parse("2026-10-13 {$ist}:00", 'Asia/Kolkata')->utc());

        $business = new Business(['opening_time' => $open, 'closing_time' => $close]);

        $this->assertSame($expected, $business->isOpenNow());
    }
}
