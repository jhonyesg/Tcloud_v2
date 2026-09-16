<?php

namespace Tests\Unit;

use App\Services\Ia\BogotaTime;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class BogotaTimeTest extends TestCase
{
    public function test_timezone_constant_is_america_bogota(): void
    {
        $this->assertSame('America/Bogota', BogotaTime::TIMEZONE);
    }

    public function test_today_start_returns_start_of_bogota_day(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 14:30:00', 'America/Bogota'));

        $today = BogotaTime::todayStart();

        $this->assertSame('2026-09-15 00:00:00', $today->format('Y-m-d H:i:s'));
        $this->assertSame('America/Bogota', $today->timezoneName);

        CarbonImmutable::setTestNow();
    }

    public function test_now_returns_current_instant_in_bogota_timezone(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 22:00:00', 'UTC'));

        $now = BogotaTime::now();

        $this->assertSame('America/Bogota', $now->timezoneName);
        $this->assertSame('2026-09-15 17:00:00', $now->format('Y-m-d H:i:s'));

        CarbonImmutable::setTestNow();
    }

    public function test_today_as_date_string_returns_yyyy_mm_dd_format(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 23:59:59', 'America/Bogota'));

        $this->assertSame('2026-09-15', BogotaTime::todayAsDateString());

        CarbonImmutable::setTestNow();
    }
}
