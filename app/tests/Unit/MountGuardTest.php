<?php

namespace Tests\Unit;

use App\Services\MountGuard;
use PHPUnit\Framework\TestCase;

/**
 * Deteccion de montajes de red caidos.
 *
 * El parseo se prueba contra texto de ejemplo; isMounted() se prueba contra el
 * sistema real solo en casos universalmente ciertos (/ esta montada, un
 * subdirectorio recien creado no lo esta).
 */
class MountGuardTest extends TestCase
{
    private const MOUNTS = <<<'TXT'
    /dev/sda1 / ext4 rw,relatime 0 0
    proc /proc proc rw,nosuid,nodev,noexec,relatime 0 0
    192.168.0.50:/export/discoE /www/data/Disco_E nfs4 rw,relatime,vers=4.2 0 0
    192.168.0.50:/export/discoF /www/data/Disco_F nfs rw,relatime 0 0
    //srv/share /mnt/win\040share cifs rw 0 0
    tmpfs /dev/shm tmpfs rw,nosuid,nodev 0 0
    TXT;

    private function guard(array $config = []): MountGuard
    {
        return new MountGuard($config);
    }

    public function testParseaPuntosDeMontajeYTipos(): void
    {
        $m = $this->guard()->parseMounts(self::MOUNTS);

        $this->assertSame('ext4', $m['/']);
        $this->assertSame('nfs4', $m['/www/data/Disco_E']);
        $this->assertSame('nfs', $m['/www/data/Disco_F']);
        $this->assertSame('tmpfs', $m['/dev/shm']);
    }

    /** Los espacios vienen escapados como \040 en /proc/mounts. */
    public function testDesescapaEspaciosEnLaRuta(): void
    {
        $m = $this->guard()->parseMounts(self::MOUNTS);

        $this->assertArrayHasKey('/mnt/win share', $m);
        $this->assertSame('cifs', $m['/mnt/win share']);
    }

    public function testIgnoraLineasVaciasYMalformadas(): void
    {
        $m = $this->guard()->parseMounts("\n  \n/dev/sda1 / ext4 rw 0 0\nbasura\n");

        $this->assertCount(1, $m);
        $this->assertSame('ext4', $m['/']);
    }

    public function testRaizSiempreCuentaComoMontada(): void
    {
        $this->assertTrue($this->guard()->isMounted('/'));
    }

    /**
     * Un directorio normal comparte dispositivo con su padre: eso es justo lo que
     * ocurre cuando un montaje NFS se cae y queda el directorio local debajo.
     */
    public function testDirectorioNormalNoEsUnPuntoDeMontaje(): void
    {
        $tmp = sys_get_temp_dir() . '/mg_' . bin2hex(random_bytes(6));
        mkdir($tmp);

        try {
            $this->assertFalse($this->guard()->isMounted($tmp));
        } finally {
            @rmdir($tmp);
        }
    }

    /** Sin montajes esperados declarados no se puede afirmar que falte ninguno. */
    public function testSinMontajesEsperadosNuncaReportaFalta(): void
    {
        $tmp = sys_get_temp_dir() . '/mg_' . bin2hex(random_bytes(6));
        mkdir($tmp);

        try {
            $g = $this->guard(['expected' => []]);
            $this->assertFalse($g->isExpectedMountMissing($tmp));
            $this->assertNull($g->detachedAncestor($tmp));
        } finally {
            @rmdir($tmp);
        }
    }

    /** El escenario del incidente: se espera un montaje y no lo hay. */
    public function testMontajeEsperadoAusenteSeDetecta(): void
    {
        $tmp = sys_get_temp_dir() . '/mg_' . bin2hex(random_bytes(6));
        mkdir($tmp);

        try {
            $g = $this->guard(['expected' => [$tmp]]);
            $this->assertTrue($g->isExpectedMountMissing($tmp),
                'un directorio normal declarado como montaje esperado significa que el montaje se cayo');
        } finally {
            @rmdir($tmp);
        }
    }

    /** Un descendiente del montaje caido tambien queda afectado. */
    public function testDetectaElMontajeCaidoDesdeUnaRutaHija(): void
    {
        $tmp = sys_get_temp_dir() . '/mg_' . bin2hex(random_bytes(6));
        mkdir($tmp . '/sub/hondo', 0777, true);

        try {
            $g = $this->guard(['expected' => [$tmp]]);
            $this->assertSame($tmp, $g->detachedAncestor($tmp . '/sub/hondo'));
        } finally {
            @rmdir($tmp . '/sub/hondo');
            @rmdir($tmp . '/sub');
            @rmdir($tmp);
        }
    }

    public function testCentinelaAusenteMarcaElMontajeComoCaido(): void
    {
        $tmp = sys_get_temp_dir() . '/mg_' . bin2hex(random_bytes(6));
        mkdir($tmp);

        try {
            $g = $this->guard(['expected' => [$tmp], 'sentinel' => '.tcloud-mounted']);
            $this->assertTrue($g->isExpectedMountMissing($tmp));
        } finally {
            @rmdir($tmp);
        }
    }
}
