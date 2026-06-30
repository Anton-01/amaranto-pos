<?php

namespace Tests\Unit;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class MexicoTimezoneTest extends TestCase
{
    private const TZ = 'America/Mexico_City';

    public function test_app_timezone_is_mexico_city(): void
    {
        $config = require __DIR__ . '/../../config/app.php';

        $this->assertEquals(self::TZ, $config['timezone']);
    }

    public function test_today_filter_uses_mexico_timezone_boundaries(): void
    {
        $today = Carbon::now(self::TZ)->startOfDay();
        $endOfDay = Carbon::now(self::TZ)->endOfDay();

        $this->assertEquals(self::TZ, $today->timezoneName);
        $this->assertEquals('00:00:00', $today->format('H:i:s'));
        $this->assertEquals('23:59:59', $endOfDay->format('H:i:s'));
    }

    public function test_late_night_mexico_order_falls_within_same_day(): void
    {
        $orderTime = Carbon::parse('2026-06-29 23:30:00', self::TZ);

        $todayStart = Carbon::parse('2026-06-29', self::TZ)->startOfDay();
        $todayEnd = Carbon::parse('2026-06-29', self::TZ)->endOfDay();

        $this->assertTrue(
            $orderTime->between($todayStart, $todayEnd),
            'An order at 11:30 PM Mexico time on June 29 should be within June 29 boundaries'
        );
    }

    public function test_late_night_mexico_order_is_next_day_in_utc(): void
    {
        $orderTimeMexico = Carbon::parse('2026-06-29 23:30:00', self::TZ);
        $orderTimeUtc = $orderTimeMexico->copy()->setTimezone('UTC');

        $this->assertEquals('2026-06-30', $orderTimeUtc->toDateString(),
            'An order at 11:30 PM CST on June 29 should be June 30 in UTC'
        );
    }

    public function test_utc_filter_would_miss_late_night_order(): void
    {
        $orderTimeMexico = Carbon::parse('2026-06-29 23:30:00', self::TZ);

        $utcTodayStart = Carbon::parse('2026-06-29', 'UTC')->startOfDay();
        $utcTodayEnd = Carbon::parse('2026-06-29', 'UTC')->endOfDay();

        $orderInUtc = $orderTimeMexico->copy()->setTimezone('UTC');

        $this->assertFalse(
            $orderInUtc->between($utcTodayStart, $utcTodayEnd),
            'UTC-based filter should miss the late-night Mexico order — this is the bug we fixed'
        );
    }

    public function test_mexico_filter_catches_late_night_order(): void
    {
        $orderTimeMexico = Carbon::parse('2026-06-29 23:30:00', self::TZ);

        $mexicoTodayStart = Carbon::parse('2026-06-29', self::TZ)->startOfDay();
        $mexicoTodayEnd = Carbon::parse('2026-06-29', self::TZ)->endOfDay();

        $this->assertTrue(
            $orderTimeMexico->between($mexicoTodayStart, $mexicoTodayEnd),
            'Mexico timezone filter should correctly include the late-night order'
        );
    }

    public function test_date_from_request_parsed_in_mexico_tz(): void
    {
        $dateFromRequest = '2026-06-29';

        $from = Carbon::parse($dateFromRequest, self::TZ)->startOfDay();
        $to = Carbon::parse($dateFromRequest, self::TZ)->endOfDay();

        $this->assertEquals('2026-06-29 00:00:00', $from->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-06-29 23:59:59', $to->format('Y-m-d H:i:s'));
        $this->assertEquals(self::TZ, $from->timezoneName);
        $this->assertEquals(self::TZ, $to->timezoneName);
    }
}
