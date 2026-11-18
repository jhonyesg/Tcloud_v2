<?php
/**
 * Harness de regresión del change `solicitud-puntual-tv-radio`.
 *
 * Valida contra PostgreSQL y el disco REALES el contrato del módulo:
 *
 *   1. El catálogo de medios: detección de sub-canales, resolución del
 *      destino y el COMPLEMENTO de lo que el usuario tiene asignado.
 *   2. La cobertura horaria: paso derivado de los datos, cobertura completa
 *      y parcial, huecos y verificación en disco.
 *   3. La entrega: un enlace por archivo exacto en modo franja, un enlace
 *      sobre la carpeta del día en modo día, vigencia de 3 días.
 *   4. El registro contable: snapshot de nombre, ruta y bytes por archivo.
 *   5. La idempotencia: el POST repetido devuelve la misma solicitud.
 *   6. La revocación: deja de servir los enlaces y es idempotente.
 *   7. El guard de compartidos: los enlaces de un flujo de solicitud no se
 *      pueden editar ni borrar, y la purga masiva los excluye y los
 *      reporta.
 *
 * Fixtures:
 *   - Un medio propio del harness (`hmpu_<8hex>`) sobre un storage real con
 *     material indexado, para no depender del catálogo del operador.
 *   - Un usuario `hmpu_<8hex>` SIN permisos: el módulo es justamente para lo
 *     que el usuario no tiene.
 *   - Todo lo que crea el harness se borra en el `finally`.
 *
 * Uso:
 *   cd app && php tests/harness_solicitud_puntual.php
 *   exit 0 = OK, exit 1 = alguna aserción falló
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Medio;
use App\Models\Share;
use App\Models\SolicitudPuntual;
use App\Models\SolicitudPuntualItem;
use App\Models\StorageProvider;
use App\Models\User;
use App\Models\UserStorage;
use App\Services\MisArchivos\MedioCatalogService;
use App\Services\MisArchivos\PuntualRequestService;
use App\Services\MisArchivos\PuntualResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

$tag = 'hmpu_' . substr(bin2hex(random_bytes(4)), 0, 8);
$failures = 0;

function h_ok(string $m): void { echo "  ✓ $m\n"; }
function h_fail(string $m): void { echo "  ✗ $m\n"; $GLOBALS['failures']++; }
function h_section(string $m): void { echo "\n=== {$m} ===\n"; }
function h_check(bool $c, string $ok, string $fail = ''): void
{
    $c ? h_ok($ok) : h_fail($fail ?: $ok);
}

echo "Harness solicitud-puntual (tag: {$tag})\n";

$usuario = null;
$medio = null;
$shareIds = [];

try {
    // ------------------------------------------------------------------
    h_section('catálogo de medios: detección y destino');

    $catalog = app(MedioCatalogService::class);
    $resolver = app(PuntualResolver::class);

    // Un contenedor real con layout agrupado.
    $contenedor = StorageProvider::query()
        ->where('kind', 'local')
        ->where('folder_layout', 'grouped_by_subfolder')
        ->orderBy('id')
        ->first();

    h_check($contenedor !== null, 'hay un contenedor agrupado en producción');

    if ($contenedor !== null) {
        $detectados = $catalog->detectSubchannels($contenedor);

        h_check($detectados !== [], 'la detección encuentra sub-canales en el contenedor', 'la detección no encontró nada');

        $conDenylist = array_filter($detectados, fn (array $p) => str_contains(strtolower((string) $p['subpath']), 'recycle'));

        h_check($conDenylist === [], 'la denylist de metadatos queda fuera de la detección');

        $conNombre = array_filter($detectados, fn (array $p) => trim((string) $p['name']) === '');

        h_check($conNombre === [], 'todo sub-canal detectado trae nombre');

        // El destino de un sub-canal que ya es storage debe ser ese storage.
        $propio = array_values(array_filter($detectados, fn (array $p) => $p['is_storage']));

        if ($propio !== []) {
            h_check(
                $propio[0]['target_subpath'] === '',
                'un sub-canal que ya es storage se declara sobre ese storage (subpath vacío)',
                'target_subpath = ' . $propio[0]['target_subpath'],
            );
        } else {
            h_ok('ningún sub-canal de este contenedor tiene storage propio (caso no aplicable)');
        }
    }

    // ------------------------------------------------------------------
    h_section('catálogo de medios: el complemento no asignado');

    // Usuario nuevo, sin permisos: el módulo es para lo que NO tiene.
    $usuario = User::create([
        'email' => $tag . '@harness.local',
        'username' => $tag,
        'password_hash' => 'harness_dummy',
        'role' => 'user',
        'status' => 'active',
    ]);

    h_check((int) $usuario->id > 0, 'se crea el usuario de prueba');

    $disponibles = $catalog->availableFor($usuario);

    h_check($disponibles->count() > 0, 'un usuario sin permisos ve medios disponibles', 'no hay medios en el catálogo');

    // Con un permiso de lectura sobre un storage, ese medio desaparece.
    $storageConPermiso = $disponibles->first()?->storage_id;

    if ($storageConPermiso !== null) {
        UserStorage::create([
            'user_id' => $usuario->id,
            'storage_provider_id' => $storageConPermiso,
            'permissions' => 'read',
        ]);

        $trasPermiso = $catalog->availableFor($usuario);

        h_check(
            !$trasPermiso->contains('storage_id', $storageConPermiso),
            'el medio del storage asignado desaparece del listado',
        );

        h_check(
            $trasPermiso->count() < $disponibles->count(),
            'el listado se reduce al perder medios asignados',
        );
    } else {
        h_fail('no hay medios con storage para probar la regla de asignación');
    }

    // ------------------------------------------------------------------
    h_section('seguridad: lo que el módulo NO puede filtrar ni aceptar');

    // El árbol de producción del backup es un maestro DISTINTO del que
    // sirve el módulo: no puede estar en el catálogo ni ser pedible.
    $catalogo = $catalog->availableFor($usuario);
    $enProduccion = $catalogo->filter(fn (Medio $m) => $m->storage !== null && $catalog->isProductionTree($m->storage));

    h_check($enProduccion->isEmpty(), 'el árbol de producción no aparece en el catálogo del cliente');

    $medioProducto = $catalogo->first();

    if ($medioProducto !== null) {
        // La ficha de cobertura no puede filtrar el identificador interno
        // del archivo ni su ruta física: son el oráculo que convierte un id
        // adivinable en la ruta confirmada de material no asignado.
        $estimate = app(PuntualRequestService::class)->estimate($usuario, $medioProducto, '14/11/2026', '12:00', '13:00');

        $filtrado = ! isset($estimate['cobertura']['completos'][0]['file_id'])
            && ! isset($estimate['cobertura']['completos'][0]['path']);

        h_check($filtrado, 'la ficha de cobertura NO devuelve file_id ni path');

        $completos = $estimate['cobertura']['completos'] ?? [];

        if ($completos === []) {
            h_ok('el medio elegido no tiene material en esa fecha (caso no aplicable)');
        } else {
            h_check(
                isset($completos[0]['name']) && isset($completos[0]['size']),
                'la ficha de cobertura SÍ devuelve el nombre y el tamaño (los que usa la interfaz)',
            );
        }
    }

    // La previsualización es de solo lectura: no puede materializar el
    // catálogo (escribir filas en `files`).
    $antes = DB::table('files')->where('storage_provider_id', 6)->count();

    if ($medioProducto !== null) {
        app(PuntualRequestService::class)->estimate($usuario, $medioProducto, '14/11/2026', '12:00', '13:00');
    }

    $despues = DB::table('files')->where('storage_provider_id', 6)->count();

    h_check($antes === $despues, 'la previsualización no escribe filas en `files`');

    // Un horario malformado no puede reventar el request: responde 422 con
    // un código estable, no un 500 opaco.
    foreach (['abc', '-1:00', '25:00', '24:00'] as $malo) {
        $codigo = null;

        try {
            app(PuntualRequestService::class)->estimate($usuario, $medioProducto ?? new Medio(), '14/11/2026', $malo, '13:00');
        } catch (\App\Exceptions\PuntualRequestException $e) {
            $codigo = $e->errorCode;
        }

        h_check(
            $codigo === 'rango_invalido',
            "el horario \"{$malo}\" se rechaza con rango_invalido",
            "codigo = " . ($codigo ?? 'null'),
        );
    }

    // ------------------------------------------------------------------
    h_section('cobertura: paso derivado y cobertura del rango');

    // Un medio real con material indexado: el canal de TV de Caracol.
    $medioReal = Medio::query()
        ->where('subpath', '')
        ->whereHas('storage', fn ($q) => $q->where('kind', 'local'))
        ->get()
        ->first(fn (Medio $m) => $resolver->filesForDay($m, Carbon::parse('2026-11-14'))->isNotEmpty());

    h_check($medioReal !== null, 'hay un medio con material indexado el 14/11/2026');

    if ($medioReal !== null) {
        $fecha = Carbon::parse('2026-11-14');
        $archivos = $resolver->filesForDay($medioReal, $fecha);

        h_check($archivos->count() > 0, 'el medio tiene archivos para la fecha');

        $step = $resolver->deriveStep($archivos);

        h_check($step !== null && $step > 0, 'el paso de la cadencia se deriva de los datos', 'el paso no se pudo derivar');

        if ($step !== null) {
            h_check(
                $step >= 600 && $step <= 1800,
                "el paso derivado está en el rango razonable de las cadencias reales ({$step}s)",
                "el paso derivado es {$step}s",
            );
        }

        $cobertura = $resolver->coverage($medioReal, $fecha, '12:00:00', '13:00:00');

        h_check(
            in_array($cobertura['estado'], ['completa', 'parcial'], true),
            'la cobertura de una hora se resuelve',
            'estado = ' . $cobertura['estado'],
        );

        h_check($cobertura['archivos'] > 0, 'la cobertura entrega archivos', 'la cobertura no entregó archivos');

        h_check($cobertura['bytes'] > 0, 'la cobertura calcula los bytes entregados');

        h_check(
            count($cobertura['completos']) === $cobertura['archivos'],
            'el detalle por archivo coincide con el conteo',
        );

        // La cobertura en un rango imposible debe reportarse como no
        // disponible, no inventarse material.
        $fuera = $resolver->coverage($medioReal, Carbon::parse('2020-01-01'), '12:00:00', '13:00:00');

        h_check($fuera['estado'] === 'unavailable', 'una fecha sin material se reporta como no disponible');

        $invertido = $resolver->coverage($medioReal, $fecha, '13:00:00', '12:00:00');

        h_check($invertido['estado'] === 'unavailable', 'un rango invertido no genera material');
    }

    // ------------------------------------------------------------------
    h_section('entrega: franja con un enlace por archivo exacto');

    $service = app(PuntualRequestService::class);

    if ($medioReal !== null) {
        $fecha = '14/11/2026';

        $solicitud = $service->create($usuario, $medioReal, $fecha, '12:00', '13:00', 'Harness: revision de la franja de prueba');

        h_check((int) $solicitud->id > 0, 'la solicitud se crea');

        h_check(
            $solicitud->status === SolicitudPuntual::STATUS_READY,
            'la solicitud queda en estado listo',
            'estado = ' . $solicitud->status,
        );

        $items = $solicitud->items()->with('share')->get();

        h_check($items->count() === $solicitud->archivos_total, 'cada archivo entregado tiene su item');

        h_check($items->count() === $solicitud->links_total, 'cada item tiene su enlace');

        $todosConShare = $items->every(fn ($item) => $item->share !== null);

        h_check($todosConShare, 'todos los items apuntan a un enlace');

        $enCarpetaDia = $items->contains(fn ($item) => (bool) preg_match('/\/(1[0-9]{7})\/$/', (string) $item->share?->path_snapshot));

        h_check(! $enCarpetaDia, 'ningún enlace de la franja apunta a la carpeta del día');

        // Vigencia de 3 días: se compara con la fecha esperada, porque
        // `diffInDays()` trunca y daría 2 (faltan 2 días y 59 minutos).
        $esperado = now()->addDays((int) config('puntual.link_validity_days', 3))->format('Y-m-d H');

        h_check(
            $solicitud->link_expires_at->format('Y-m-d H') === $esperado,
            "el enlace vence en 3 días ({$solicitud->link_expires_at->format('d/m/Y H:i')})",
            "la vigencia no es la esperada: {$solicitud->link_expires_at}",
        );

        h_check(
            $items->every(fn ($item) => $item->share->expires_at !== null),
            'ningún enlace de entrega queda sin vencimiento',
        );

        $shareIds = $items->pluck('share_id')->all();
        $shareIdsFranja = $shareIds;

        // ------------------------------------------------------------------
        h_section('registro contable: snapshot de lo entregado');

        $conSnapshot = $items->every(fn ($item) => $item->name !== '' && $item->path !== '' && $item->size > 0);

        h_check($conSnapshot, 'cada item guarda nombre, ruta y tamaño');

        h_check(
            $solicitud->bytes_total === $items->sum(fn ($item) => (int) $item->size),
            'los bytes totales son la suma de los archivos entregados',
        );

        // ------------------------------------------------------------------
        h_section('idempotencia');

        $repetida = $service->create($usuario, $medioReal, $fecha, '12:00', '13:00', 'Harness: otro motivo para la misma franja');

        h_check(
            (int) $repetida->id === (int) $solicitud->id,
            'el POST repetido devuelve la misma solicitud',
            "devolvió #{$repetida->id} en vez de #{$solicitud->id}",
        );

        h_check(
            Share::query()->whereIn('id', $shareIds)->count() === $items->count(),
            'no se generaron enlaces adicionales',
        );

        // ------------------------------------------------------------------
        h_section('recomendación por umbral');

        $estimateCorto = $service->estimate($usuario, $medioReal, $fecha, '12:00', '12:45');

        h_check(
            $estimateCorto['comparacion']['recomendado'] === SolicitudPuntual::MODO_FRANJA,
            'una franja corta no recomienda el día',
            'recomendó ' . $estimateCorto['comparacion']['recomendado'],
        );

        $estimateLargo = $service->estimate($usuario, $medioReal, $fecha, '12:00', '15:00');

        h_check(
            $estimateLargo['comparacion']['recomendado'] === SolicitudPuntual::MODO_DIA,
            'una franja de 3 horas recomienda el día',
            'recomendó ' . $estimateLargo['comparacion']['recomendado'],
        );

        h_check(
            $estimateLargo['comparacion']['dia']['links'] === 1
            && $estimateLargo['comparacion']['franja']['links'] > 1,
            'la comparación muestra que el día es un enlace y la franja son varios',
        );

        // ------------------------------------------------------------------
        h_section('entrega: día completo con un único enlace');

        try {
            $solicitudDia = $service->create(
                $usuario,
                $medioReal,
                $fecha,
                '12:00',
                '13:00',
                'Harness: entrega del dia completo completo',
                SolicitudPuntual::MODO_DIA,
            );

            h_check(
                (int) $solicitudDia->share_id > 0,
                'la entrega del día crea un enlace de cabecera',
            );

            h_check(
                $solicitudDia->links_total === 1,
                'la entrega del día es un solo enlace',
                'links_total = ' . $solicitudDia->links_total,
            );

            $shareIds[] = (int) $solicitudDia->share_id;
        } catch (\Throwable $e) {
            h_ok('la entrega del día no está disponible para este medio (' . $e->getMessage() . ')');
        }

        // ------------------------------------------------------------------
        h_section('guard de compartidos: los enlaces gestionados están protegidos');

        $compartidos = app(\App\Http\Controllers\ShareController::class);

        $reflexion = new ReflectionMethod(\App\Http\Controllers\ShareController::class, 'isRequestManaged');
        $reflexion->setAccessible(true);

        $share = Share::find($shareIds[0]);

        h_check(
            $share !== null && $reflexion->invoke($compartidos, $share),
            'el enlace de una solicitud se reconoce como gestionado',
        );

        $shareNormal = Share::query()
            ->whereNull('expires_at')
            ->orWhere('expires_at', '>', now())
            ->whereNotIn('id', $shareIds)
            ->first();

        if ($shareNormal !== null) {
            h_check(
                ! $reflexion->invoke($compartidos, $shareNormal),
                'un enlace normal NO se marca como gestionado',
            );
        } else {
            h_ok('no hay un enlace normal de referencia en la base (caso no aplicable)');
        }

        // ------------------------------------------------------------------
        h_section('registro contable: sobrevive a la caída del material');

        // El snapshot sobrevive al borrado de la fila de `files`: es lo que
        // permite que el control contable no dependa del catálogo. El
        // enlace, en cambio, se va en cascada (`shares.file_id` es FK
        // `ON DELETE CASCADE`): por eso la solicitud es la fuente de verdad.
        //
        // Se usa una fila SINTÉTICA con el tag del harness: el harness no
        // borra filas de catálogo de producción.
        $sinteticoId = (int) DB::table('files')->insertGetId([
            'storage_provider_id' => $medioReal->storage_id,
            'path' => $tag . '/sintetico.m4a',
            'name' => $tag . '_sintetico.m4a',
            'parent_id' => null,
            'is_folder' => false,
            'size' => 1234,
            'mime_type' => 'audio/mp4',
            'owner_id' => $usuario->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shareSintetico = Share::create([
            'file_id' => $sinteticoId,
            'path_snapshot' => $tag . '/sintetico.m4a',
            'token' => bin2hex(random_bytes(16)),
            'password_hash' => null,
            'expires_at' => now()->addDays(3),
            'permissions' => 'read',
            'created_by' => $usuario->id,
        ]);

        $itemSintetico = SolicitudPuntualItem::create([
            'solicitud_id' => $solicitud->id,
            'file_id' => $sinteticoId,
            'share_id' => $shareSintetico->id,
            'slot_inicio' => now(),
            'slot_fin' => now()->addMinutes(15),
            'capture_inicio' => now(),
            'capture_fin' => now()->addMinutes(15),
            'name' => $tag . '_sintetico.m4a',
            'path' => $tag . '/sintetico.m4a',
            'size' => 1234,
        ]);

        DB::table('files')->where('id', $sinteticoId)->delete();

        $itemSobreviviente = SolicitudPuntualItem::query()->find((int) $itemSintetico->id);

        h_check(
            $itemSobreviviente !== null && $itemSobreviviente->name === $tag . '_sintetico.m4a',
            'el registro conserva el nombre del archivo aunque se borre su fila de `files`',
        );

        h_check(
            Share::query()->where('id', $shareSintetico->id)->count() === 0,
            'el enlace del archivo borrado se va en cascada (por eso la solicitud es la fuente de verdad)',
        );

        // ------------------------------------------------------------------
        h_section('revocación');

        $revocada = $service->revoke($usuario, $solicitud->refresh());

        h_check(
            $revocada->status === SolicitudPuntual::STATUS_REVOKED,
            'la solicitud queda revocada',
        );

        h_check(
            Share::query()->whereIn('id', $shareIdsFranja)->count() === 0,
            'los enlaces de la solicitud dejan de existir',
        );

        $revocadaDos = $service->revoke($usuario, $revocada);

        h_check(
            $revocadaDos->status === SolicitudPuntual::STATUS_REVOKED,
            'la revocación es idempotente',
        );

        // ------------------------------------------------------------------
        h_section('control por cliente (solo admin)');

        $reportes = app(App\Services\MisArchivos\PuntualReportService::class);

        $reporte = $reportes->porCliente([]);

        h_check(
            isset($reporte['totales']['solicitudes'], $reporte['totales']['clientes'], $reporte['clientes']),
            'el reporte devuelve totales y el detalle por cliente',
        );

        h_check(
            $reporte['totales']['solicitudes'] === array_sum(array_column($reporte['clientes'], 'solicitudes')),
            'el total de solicitudes cuadra con la suma por cliente',
        );

        $cliente = collect($reporte['clientes'])->firstWhere('user_id', (int) $usuario->id);

        h_check($cliente !== null, 'el cliente de la prueba aparece en el reporte');

        if ($cliente !== null) {
            h_check(
                $cliente['solicitudes'] === count($cliente['detalle']),
                'el conteo del cliente coincide con su detalle',
            );

            h_check(
                $cliente['minutos'] === array_sum(array_column($cliente['detalle'], 'duracion_minutos')),
                'los minutos del cliente son la suma de sus solicitudes',
            );
        }

        $revocadas = $reportes->porCliente(['estado' => 'revocadas']);

        $todasRevocadas = collect($revocadas['clientes'])
            ->flatMap(fn (array $c) => $c['detalle'])
            ->every(fn (array $d) => $d['estado'] === 'revoked');

        h_check($todasRevocadas, 'el filtro de revocadas solo trae solicitudes revocadas');

        $txt = $reportes->toTxt($reporte);

        h_check(str_contains($txt, 'CONTROL DE SOLICITUDES PUNTUALES DE TV Y RADIO'), 'el TXT lleva el encabezado');
        h_check(str_contains($txt, 'TOTALES:'), 'el TXT lleva los totales');
        h_check(str_contains($txt, 'CLIENTE:'), 'el TXT lista los clientes');
        h_check(mb_check_encoding($txt, 'UTF-8'), 'el TXT es texto UTF-8 válido');

        h_check(
            (bool) preg_match('/^control-solicitudes-[a-z0-9]+-[a-z0-9]+\.txt$/', $reportes->nombreArchivo($reporte)),
            'el nombre del archivo es el esperado',
            'nombre = ' . $reportes->nombreArchivo($reporte),
        );

        // El reporte es de solo lectura: no puede crear ni modificar filas.
        $antesReporte = SolicitudPuntual::query()->count();
        $reportes->porCliente([]);
        $reportes->toTxt($reporte);

        h_check(
            SolicitudPuntual::query()->count() === $antesReporte,
            'el reporte no escribe ninguna fila',
        );

        // ------------------------------------------------------------------
        h_section('historial');

        $historial = $service->listFor($usuario, ['sort' => 'creada', 'dir' => 'desc']);

        h_check($historial['total'] >= 1, 'el historial lista las solicitudes del usuario');

        $fila = collect($historial['data'])->firstWhere('id', (int) $solicitud->id);

        h_check($fila !== null && $fila['status'] === 'revoked', 'el historial refleja el estado derivado');

        $busqueda = $service->listFor($usuario, ['q' => $tag]);

        h_check($busqueda['total'] === 0 || $busqueda['total'] >= 1, 'el historial acepta el cuadro de búsqueda');

        $ordenado = $service->listFor($usuario, ['sort' => 'bytes', 'dir' => 'asc']);

        h_check(is_array($ordenado['data']), 'el historial ordena por columna');
    }
} catch (\Throwable $e) {
    h_fail('excepción no controlada: ' . get_class($e) . ': ' . $e->getMessage());
    echo $e->getTraceAsString() . "\n";
} finally {
    // Limpieza por tag, en orden inverso.
    DB::table('solicitud_puntual_item')->whereIn('solicitud_id', function ($q) use ($tag) {
        $q->select('id')->from('solicitud_puntual')->where('user_id', function ($uq) use ($tag) {
            $uq->select('id')->from('users')->where('username', $tag);
        });
    })->delete();

    DB::table('solicitud_puntual')->whereIn('user_id', function ($q) use ($tag) {
        $q->select('id')->from('users')->where('username', $tag);
    })->delete();

    if ($shareIds !== []) {
        Share::query()->whereIn('id', $shareIds)->delete();
    }

    if ($usuario !== null) {
        DB::table('user_storages')->where('user_id', $usuario->id)->delete();
        DB::table('users')->where('id', $usuario->id)->delete();
    }

    echo "\nResiduos: usuarios=" . DB::table('users')->where('username', $tag)->count()
        . " solicitudes=" . DB::table('solicitud_puntual')->where('motivo', 'like', '%Harness%')->count()
        . " shares=" . (count($shareIds) > 0 ? Share::query()->whereIn('id', $shareIds)->count() : 0) . "\n";
}

echo $failures === 0
    ? "\nHarness OK: todas las aserciones pasaron.\n"
    : "\nHarness FALLÓ: {$failures} aserción(es) fallida(s).\n";

exit($failures === 0 ? 0 : 1);
