<?php

namespace Tests\Unit;

use App\Services\Ia\RecordedAt;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * change 2026-09-10-mis-avisos-program-date-filter — Task 2.4.
 *
 * Cascada de fallback: filename → file_modified_at → finished_at → null.
 */
class RecordedAtResolveTest extends TestCase
{
    public function test_returns_parsed_when_filename_matches(): void
    {
        $fm = CarbonImmutable::parse('2026-08-15 10:00:00');
        $finished = CarbonImmutable::parse('2026-08-15 11:00:00');

        $r = RecordedAt::resolve('winsport_07092026_141502.mp4', $fm, $finished);

        $this->assertNotNull($r);
        $this->assertSame('2026-09-07 14:15:02', $r->format('Y-m-d H:i:s'),
            'filename válido gana sobre los fallbacks');
    }

    public function test_falls_back_to_file_modified_at_when_filename_invalid(): void
    {
        $fm = CarbonImmutable::parse('2026-08-15 10:00:00');
        $finished = CarbonImmutable::parse('2026-08-15 11:00:00');

        $r = RecordedAt::resolve('audio_sin_patron.mp3', $fm, $finished);

        $this->assertNotNull($r);
        $this->assertSame('2026-08-15 10:00:00', $r->format('Y-m-d H:i:s'),
            'sin filename, cae a file_modified_at');
    }

    public function test_falls_back_to_finished_at_when_nothing_else(): void
    {
        $finished = CarbonImmutable::parse('2026-08-15 11:00:00');

        $r = RecordedAt::resolve('audio_sin_patron.mp3', null, $finished);

        $this->assertNotNull($r);
        $this->assertSame('2026-08-15 11:00:00', $r->format('Y-m-d H:i:s'),
            'sin filename ni file_modified_at, cae a finished_at');
    }

    public function test_returns_null_when_all_sources_empty(): void
    {
        $r = RecordedAt::resolve(null, null, null);
        $this->assertNull($r);

        $r = RecordedAt::resolve('audio.mp3', null, null);
        $this->assertNull($r);
    }
}
