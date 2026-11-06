<?php

namespace Tests\Unit;

use App\Support\FilesystemTime;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Regresion del desfase +5 h en `files.file_modified_at`.
 *
 * `1793929344` es el `mtime` del archivo real reportado por el operador:
 *   disco  : 2026-11-05 20:42:24 -05
 *   UTC    : 2026-11-06 01:42:24
 * El valor naive de Carbon lo dejaba como "2026-11-06 01:42" en Bogota.
 */
class FilesystemTimeTest extends TestCase
{
    private const MTIME = 1793929344;

    public function test_from_timestamp_returns_carbon_in_app_timezone(): void
    {
        $instant = FilesystemTime::fromTimestamp(self::MTIME);

        $this->assertSame(FilesystemTime::zone(), $instant->timezoneName);
        $this->assertSame('2026-11-05 20:42:24', $instant->format('Y-m-d H:i:s'));
    }

    public function test_from_timestamp_preserves_the_real_instant(): void
    {
        $instant = FilesystemTime::fromTimestamp(self::MTIME);

        $this->assertSame(self::MTIME, $instant->timestamp);
        $this->assertSame('2026-11-05T20:42:24-05:00', $instant->toIso8601String());
    }

    public function test_naive_create_from_timestamp_produces_a_wall_clock_five_hours_ahead(): void
    {
        $naive = CarbonImmutable::createFromTimestamp(self::MTIME);
        $correct = FilesystemTime::fromTimestamp(self::MTIME);

        $this->assertSame('+00:00', $naive->format('P'), 'Carbon sin zona devuelve el instante en UTC.');

        // El binding naive se escribe con la hora de pared UTC ("2026-11-06
        // 01:42:24") y PostgreSQL la interpreta en la sesion Bogota: el valor
        // persistido queda 5 h adelantado respecto del mtime real.
        $shiftedWallClock = CarbonImmutable::parse($naive->format('Y-m-d H:i:s'), 'America/Bogota');
        $this->assertSame(
            5 * 3600,
            $shiftedWallClock->timestamp - $correct->timestamp,
            'La conversion sin zona adelanta 5 h: es exactamente el bug reportado.'
        );
    }

    public function test_null_and_empty_return_null(): void
    {
        $this->assertNull(FilesystemTime::fromTimestamp(null));
        $this->assertNull(FilesystemTime::fromTimestamp(''));
    }

    public function test_from_path_reads_disk_mtime_in_app_timezone(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'fst_');
        file_put_contents($file, 'x');
        touch($file, self::MTIME);
        clearstatcache(true, $file);

        try {
            $instant = FilesystemTime::fromPath($file);

            $this->assertNotNull($instant);
            $this->assertSame(FilesystemTime::zone(), $instant->timezoneName);
            $this->assertSame(self::MTIME, $instant->timestamp);
            $this->assertSame('2026-11-05 20:42:24', $instant->format('Y-m-d H:i:s'));
        } finally {
            @unlink($file);
        }
    }

    public function test_from_path_returns_null_for_missing_file(): void
    {
        $this->assertNull(FilesystemTime::fromPath('/tmp/__does_not_exist__' . bin2hex(random_bytes(4))));
        $this->assertNull(FilesystemTime::fromPath(null));
        $this->assertNull(FilesystemTime::fromPath(''));
    }
}
