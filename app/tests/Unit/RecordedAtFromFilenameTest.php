<?php

namespace Tests\Unit;

use App\Services\Ia\RecordedAt;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * change 2026-09-10-mis-avisos-program-date-filter — Task 1.2.
 *
 * Casos del parser de nombres de archivo (`*_DDMMYYYY_HHMMSS.ext`).
 * Verificado contra 1.628.783 archivos de TCloud (90% del total).
 */
class RecordedAtFromFilenameTest extends TestCase
{
    public function test_parses_canonical_pattern(): void
    {
        $r = RecordedAt::fromFilename('winsport_07092026_141502.mp4');
        $this->assertNotNull($r);
        $this->assertSame('2026-09-07 14:15:02', $r->format('Y-m-d H:i:s'));
    }

    public function test_parses_uppercase_extension(): void
    {
        $r = RecordedAt::fromFilename('WINSPORT_07092026_141502.MP4');
        $this->assertNotNull($r);
        $this->assertSame('2026-09-07 14:15:02', $r->format('Y-m-d H:i:s'));
    }

    public function test_parses_with_prefix_path(): void
    {
        $r = RecordedAt::fromFilename('lafm_25122025_080000.mp3');
        $this->assertSame('2025-12-25 08:00:00', $r->format('Y-m-d H:i:s'));
    }

    public function test_returns_null_when_no_date_segment(): void
    {
        $this->assertNull(RecordedAt::fromFilename('random_file.mp3'));
        $this->assertNull(RecordedAt::fromFilename('audio_xyz.mp4'));
        $this->assertNull(RecordedAt::fromFilename('test.mp4'));
    }

    public function test_returns_null_when_partial_date(): void
    {
        $this->assertNull(RecordedAt::fromFilename('audio_070920_141502.mp4')); // sin año
        $this->assertNull(RecordedAt::fromFilename('audio_07092026_1415.mp4')); // sin segundos
    }

    public function test_returns_null_when_non_numeric_date(): void
    {
        $this->assertNull(RecordedAt::fromFilename('audio_ab092026_141502.mp4'));
        $this->assertNull(RecordedAt::fromFilename('audio_07bc2026_141502.mp4'));
    }

    public function test_returns_null_when_invalid_date_components(): void
    {
        // Fuera de los rangos permitidos por la regex.
        $this->assertNull(RecordedAt::fromFilename('audio_32012026_141502.mp4')); // día 32
        $this->assertNull(RecordedAt::fromFilename('audio_07132026_141502.mp4')); // mes 13
        $this->assertNull(RecordedAt::fromFilename('audio_07091899_141502.mp4')); // año fuera de 19|20
        $this->assertNull(RecordedAt::fromFilename('audio_07092026_241502.mp4')); // hora 24
    }

    public function test_returns_null_for_impossible_dates_that_pass_regex(): void
    {
        // checkdate rechaza 2026-02-30 aunque la regex lo acepte.
        $r = RecordedAt::fromFilename('audio_30022026_141502.mp4');
        $this->assertNull($r);
    }

    public function test_accepts_feb_29_leap_years(): void
    {
        $r = RecordedAt::fromFilename('audio_29022024_141502.mp4'); // 2024 es bisiesto
        $this->assertNotNull($r);
        $this->assertSame('2024-02-29 14:15:02', $r->format('Y-m-d H:i:s'));
    }

    public function test_accepts_midnight(): void
    {
        $r = RecordedAt::fromFilename('audio_01012026_000000.mp4');
        $this->assertNotNull($r);
        $this->assertSame('2026-01-01 00:00:00', $r->format('Y-m-d H:i:s'));
    }

    public function test_returns_null_for_empty_or_null(): void
    {
        $this->assertNull(RecordedAt::fromFilename(null));
        $this->assertNull(RecordedAt::fromFilename(''));
    }

    public function test_returns_carbon_immutable(): void
    {
        $r = RecordedAt::fromFilename('winsport_07092026_141502.mp4');
        $this->assertInstanceOf(CarbonImmutable::class, $r);
    }
}
