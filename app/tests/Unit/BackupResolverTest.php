<?php

namespace Tests\Unit;

use App\Services\MisArchivos\BackupResolver;
use App\Services\FileRegistry;
use App\Services\MountGuard;
use App\Services\StorageSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Contrato del parseo de la carpeta-día (change `solicitud-backup-mis-archivos`).
 *
 * El árbol de backup de producción usa el MISMO nombre de carpeta que el
 * lado origen: `05112026` es el 5 de noviembre de 2026 en los dos. El parseo
 * es la pieza donde un error entregaría el material equivocado al cliente,
 * así que se fija con casos explícitos.
 *
 * La resolución contra el árbol real (anclaje en `backup_storage_id`,
 * contención, mount caído) se verifica en `tests/harness_backup_requests.php`,
 * que corre contra PostgreSQL y el NFS de verdad.
 */
class BackupResolverTest extends TestCase
{
    private BackupResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new BackupResolver(
            $this->createMock(MountGuard::class),
            new FileRegistry(),
            $this->createMock(StorageSyncService::class),
        );
    }

    public function test_day_folder_name_with_valid_day_is_recognized(): void
    {
        $this->assertTrue($this->resolver->isDayFolderName('05112026'));
        $this->assertTrue($this->resolver->isDayFolderName('01012026'));
        $this->assertTrue($this->resolver->isDayFolderName('31122026'));
    }

    public function test_day_folder_name_is_parsed_to_the_right_date(): void
    {
        $date = $this->resolver->parseDayFolder('05112026');

        $this->assertNotNull($date);
        $this->assertSame('2026-11-05', $date->toDateString());
    }

    public function test_day_folder_name_keeps_the_day_and_month_order(): void
    {
        // DDMMYYYY, no MMDDYYYY: 03/11 es 3 de noviembre, no 11 de marzo.
        $date = $this->resolver->parseDayFolder('03112026');

        $this->assertNotNull($date);
        $this->assertSame('2026-11-03', $date->toDateString());
    }

    public function test_day_folder_name_rejects_names_that_are_not_eight_digits(): void
    {
        $this->assertFalse($this->resolver->isDayFolderName(''));
        $this->assertFalse($this->resolver->isDayFolderName('051120'));
        $this->assertFalse($this->resolver->isDayFolderName('051120266'));
        $this->assertFalse($this->resolver->isDayFolderName('caracol_05112026_000201'));
        $this->assertFalse($this->resolver->isDayFolderName('Impresos'));
    }

    public function test_day_folder_name_rejects_out_of_range_day_and_month(): void
    {
        $this->assertFalse($this->resolver->isDayFolderName('32132026'));
        $this->assertFalse($this->resolver->isDayFolderName('00132026'));
        $this->assertFalse($this->resolver->isDayFolderName('00012026'));
        $this->assertFalse($this->resolver->isDayFolderName('05132026'));
    }

    public function test_day_folder_name_rejects_a_date_the_calendar_does_not_have(): void
    {
        // 31 de febrero tiene la forma DDMMYYYY pero no existe.
        $this->assertFalse($this->resolver->isDayFolderName('31022026'));
        $this->assertFalse($this->resolver->isDayFolderName('29022025'));
    }

    public function test_an_old_day_keeps_its_shape_even_if_the_backup_no_longer_has_it(): void
    {
        // 20042023 tiene forma de día válida: la DISPONIBILIDAD del backup de
        // ese día se resuelve aparte, por existencia en el árbol.
        $this->assertTrue($this->resolver->isDayFolderName('20042023'));
        $this->assertSame('2023-04-20', $this->resolver->parseDayFolder('20042023')?->toDateString());
    }
}
