<?php

namespace App\Services\GrabacionesPuntuales;

use App\Models\Canal;
use App\Models\Grabador;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Detección y adopción no destructiva de canales que existen en el grabador
 * remoto y no tienen fila en `canales`.
 *
 * Problema real (2026-10-22): el operador creó `Solicitudes_05` (id remoto 202)
 * a mano en el grabador. La fila nunca apareció en Medios Puntuales, no
 * consumía cupo y rompía la equivalencia "lo que ve el admin = lo que graba el
 * equipo". `sincronizar()` solo rellenaba `api_canal_id` de filas locales;
 * nunca importaba huérfanos.
 *
 * Reglas de clasificación (medidas contra los 129 canales reales):
 *
 *   adoptable        hijo DIRECTO del `ruta_base` de un par + prefijo compartido
 *                    con algún slot local de ese par. Ej: Solicitudes_05 bajo
 *                    .../Puntual_conf/pruebas con slots Solicitudes_01..04.
 *                    Medición: 1 candidato real (Solicitudes_05).
 *
 *   sugerido         vive bajo un `ruta_base` pero no cumple el prefijo, o está
 *                    en un subdirectorio anidado. Ej: los 11 canales de TV
 *                    (Telemedellin, Trece, CaracolInternacional...) que cuelgan
 *                    de .../Disco_I/television. NUNCA se adopta automático:
 *                    requiere selección manual del admin.
 *
 *   fuera_de_alcance no está bajo ningún `ruta_base` conocido.
 *
 * Adoptar SOLO hace INSERT local espejando el remoto. Jamás emite POST, PUT ni
 * DELETE contra el grabador.
 */
class AdopcionRemotaService
{
    private TcloudApiService $api;

    public function __construct(TcloudApiService $api)
    {
        $this->api = $api;
    }

    /**
     * Clasifica los canales remotos de un grabador contra las filas locales.
     *
     * @return array{
     *   vinculados:int, ya_ok:int, sin_match:int,
     *   remotos_detectados:array<int,array<string,mixed>>,
     *   fuera_de_alcance:int, total_remotos:int
     * }
     */
    public function detectar(Grabador $grabador): array
    {
        $remotos = $this->api->getCanalesRemotos($grabador);
        $canalesLocales = Canal::where('grabador_id', $grabador->id)->get();

        $pares = DB::table('grabador_usuario')
            ->where('grabador_id', $grabador->id)
            ->get(['user_id', 'ruta_base', 'limite_canales']);

        $porCodigo = [];
        $porNombre = [];
        $porApiId = [];

        foreach ($remotos as $remoto) {
            $codigo = $this->normalizar($remoto['codigo'] ?? null);
            $nombre = $this->normalizar($remoto['nombre'] ?? null);

            if ($codigo !== '') {
                $porCodigo[$codigo] = $remoto;
            }
            if ($nombre !== '') {
                $porNombre[$nombre] = $remoto;
            }
            if (!empty($remoto['id'])) {
                $porApiId[(int) $remoto['id']] = $remoto;
            }
        }

        $vinculados = 0;
        $yaOk = 0;
        $sinMatch = 0;
        $reclamados = [];

        foreach ($canalesLocales as $canal) {
            $codigoLocal = $this->codigoDesdeSlot($canal->slot_nombre);
            $nombreLocal = $this->normalizar($canal->slot_nombre);

            $remoto = $porApiId[(int) $canal->api_canal_id]
                ?? $porCodigo[$codigoLocal]
                ?? $porNombre[$nombreLocal]
                ?? null;

            if ($remoto === null) {
                $sinMatch++;
                continue;
            }

            $reclamados[(int) $remoto['id']] = true;

            if ((int) $canal->api_canal_id !== (int) $remoto['id']) {
                $canal->update(['api_canal_id' => (int) $remoto['id']]);
                $vinculados++;
            } else {
                $yaOk++;
            }
        }

        $detectados = [];
        $fueraDeAlcance = 0;

        foreach ($remotos as $remoto) {
            $id = (int) ($remoto['id'] ?? 0);
            if ($id === 0 || isset($reclamados[$id])) {
                continue;
            }

            $clasificacion = $this->clasificar($remoto, $pares, $canalesLocales);

            if ($clasificacion === null) {
                $fueraDeAlcance++;
                continue;
            }

            $detectados[] = $clasificacion;
        }

        return [
            'grabador_id' => $grabador->id,
            'grabador_nombre' => $grabador->nombre,
            'total_remotos' => count($remotos),
            'vinculados' => $vinculados,
            'ya_ok' => $yaOk,
            'sin_match' => $sinMatch,
            'remotos_detectados' => $detectados,
            'fuera_de_alcance' => $fueraDeAlcance,
        ];
    }

    /**
     * Detecta en todos los grabadores activos (o en uno solo).
     *
     * @return array<int,array<string,mixed>>
     */
    public function detectarTodos(?int $grabadorId = null): array
    {
        $query = Grabador::where('activo', true);
        if ($grabadorId !== null) {
            $query->where('id', $grabadorId);
        }

        $resultados = [];
        foreach ($query->get() as $grabador) {
            $resultados[] = $this->detectar($grabador);
        }

        return $resultados;
    }

    /**
     * Adopta un canal remoto: INSERT local espejo. No toca el grabador.
     *
     * Re-verifica el `api_canal_id` contra la API antes de insertar: si el canal
     * desapareció entre la detección y la adopción, se omite con warning.
     *
     * @return array{ok:bool,codigo:string,accion:string,mensaje:string,slot_nombre?:string}
     */
    public function adoptar(Grabador $grabador, int $userId, int $apiCanalId): array
    {
        $existente = Canal::where('grabador_id', $grabador->id)
            ->where('api_canal_id', $apiCanalId)
            ->first();

        if ($existente !== null) {
            return [
                'ok' => true,
                'accion' => 'ya_ok',
                'codigo' => (string) $existente->slot_nombre,
                'slot_nombre' => $existente->slot_nombre,
                'mensaje' => 'El canal ya estaba registrado en la plataforma',
            ];
        }

        $pares = DB::table('grabador_usuario')
            ->where('grabador_id', $grabador->id)
            ->get(['user_id', 'ruta_base']);

        $par = $pares->first(fn($p) => (int) $p->user_id === $userId);
        if ($par === null) {
            return [
                'ok' => false,
                'accion' => 'sin_asignacion',
                'codigo' => '',
                'mensaje' => 'El usuario no tiene asignación en este grabador',
            ];
        }

        $remotos = $this->api->getCanalesRemotos($grabador);
        $remoto = null;
        foreach ($remotos as $candidato) {
            if ((int) ($candidato['id'] ?? 0) === $apiCanalId) {
                $remoto = $candidato;
                break;
            }
        }

        if ($remoto === null) {
            return [
                'ok' => false,
                'accion' => 'desaparecido',
                'codigo' => (string) $apiCanalId,
                'mensaje' => "El canal remoto {$apiCanalId} ya no existe en el grabador; se omitió",
            ];
        }

        $slotNombre = (string) ($remoto['nombre'] ?? $remoto['codigo'] ?? '');
        if ($slotNombre === '') {
            return [
                'ok' => false,
                'accion' => 'sin_nombre',
                'codigo' => (string) $apiCanalId,
                'mensaje' => "El canal remoto {$apiCanalId} no tiene nombre ni código; no se puede registrar",
            ];
        }

        // Nombre duplicado en el mismo grabador: no insertar, es drift a revisar.
        $conflicto = Canal::where('grabador_id', $grabador->id)
            ->where('slot_nombre', $slotNombre)
            ->first();

        if ($conflicto !== null) {
            return [
                'ok' => false,
                'accion' => 'conflicto_nombre',
                'codigo' => $slotNombre,
                'mensaje' => "Ya existe un canal local '{$slotNombre}' con otro api_canal_id (local {$conflicto->api_canal_id}); revisar manualmente",
            ];
        }

        $esRadio = $grabador->tipo === 'radio';
        $formato = $remoto['formato_salida'] ?? null;
        $formatoResuelto = $formato ?: ($esRadio ? '.mp3' : '.mp4');

        $rutaRemota = $remoto['ruta_descarga'] ?? null;
        $rutaBase = $par->ruta_base;

        $rutaDestino = $rutaRemota
            ?: ($rutaBase ? rtrim($rutaBase, '/') . '/' . $slotNombre : null);

        try {
            $canal = Canal::create([
                'grabador_id' => $grabador->id,
                'usuario_id' => $userId,
                'slot_nombre' => $slotNombre,
                'api_canal_id' => $apiCanalId,
                'link_origen' => $remoto['link_origen'] ?? null,
                'ruta_destino' => $rutaDestino,
                'detalle' => $remoto['detalle'] ?? null,
                'duracion_grabacion' => $remoto['duracion_grabacion'] ?? '00:21:00',
                'formato_salida' => $formatoResuelto,
                'ffmpeg_args_pre' => $remoto['ffmpeg_args_pre'] ?? ($esRadio ? '-re' : '-re'),
                'ffmpeg_args_post' => $remoto['ffmpeg_args_post'] ?? ($esRadio ? '-acodec libmp3lame' : '-c copy'),
                'activo' => (bool) ($remoto['activo'] ?? true),
            ]);
        } catch (\Throwable $e) {
            Log::error('canales.adopcion.failed', [
                'grabador_id' => $grabador->id,
                'user_id' => $userId,
                'api_canal_id' => $apiCanalId,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'accion' => 'error',
                'codigo' => $slotNombre,
                'mensaje' => 'No se pudo registrar el canal: ' . $e->getMessage(),
            ];
        }

        Log::info('canales.adopcion', [
            'canal_id' => $canal->id,
            'grabador_id' => $grabador->id,
            'user_id' => $userId,
            'api_canal_id' => $apiCanalId,
            'slot_nombre' => $slotNombre,
            'formato_salida_resuelto' => $formato === null || $formato === '' ? $formatoResuelto : null,
            'ruta_destino' => $rutaDestino,
        ]);

        return [
            'ok' => true,
            'accion' => 'adoptado',
            'codigo' => $slotNombre,
            'slot_nombre' => $slotNombre,
            'mensaje' => "Canal '{$slotNombre}' integrado desde el grabador",
        ];
    }

    /**
     * Clasifica un remoto sin fila local.
     *
     * @param  array<string,mixed>  $remoto
     * @param  \Illuminate\Support\Collection<int,object>  $pares
     * @param  \Illuminate\Support\Collection<int,Canal>|null  $canalesLocales
     * @return array<string,mixed>|null  null = fuera de alcance
     */
    public function clasificar(array $remoto, $pares, $canalesLocales = null): ?array
    {
        $ruta = $this->normalizarRuta($remoto['ruta_descarga'] ?? null);
        $codigo = (string) ($remoto['codigo'] ?? '');
        $nombre = (string) ($remoto['nombre'] ?? '');

        $par = null;
        $hijoDirecto = false;

        foreach ($pares as $candidato) {
            $base = $this->normalizarRuta($candidato->ruta_base ?? null);
            if ($base === '' || $ruta === '') {
                continue;
            }

            if ($ruta === $base) {
                // La ruta del canal ES la base: no es un slot, es el contenedor.
                continue;
            }

            $padre = dirname($ruta) === $base;
            $dentro = str_starts_with($ruta, $base . '/');

            if ($hijoDirecto) {
                break;
            }

            if ($padre) {
                $par = $candidato;
                $hijoDirecto = true;
                continue;
            }

            if ($dentro && $par === null) {
                $par = $candidato;
            }
        }

        if ($par === null) {
            return null;
        }

        $prefijoRemoto = $this->prefijo($nombre !== '' ? $nombre : $codigo);
        $prefijosLocales = $this->prefijosLocales($par->user_id, $canalesLocales);

        $mismaFamilia = $prefijoRemoto !== ''
            && in_array($prefijoRemoto, $prefijosLocales, true);

        $confianza = ($hijoDirecto && $mismaFamilia) ? 'adoptable' : 'sugerido';

        return [
            'api_canal_id' => (int) ($remoto['id'] ?? 0),
            'codigo' => $codigo,
            'nombre' => $nombre,
            'ruta_descarga' => $remoto['ruta_descarga'] ?? null,
            'grabador_id' => null,
            'user_id' => (int) $par->user_id,
            'ruta_base' => $par->ruta_base,
            'confianza' => $confianza,
            'hijo_directo' => $hijoDirecto,
            'misma_familia' => $mismaFamilia,
            'formato_salida' => $remoto['formato_salida'] ?? null,
            'activo' => (bool) ($remoto['activo'] ?? true),
        ];
    }

    /**
     * Prefijos alfabéticos de los slots locales de un usuario.
     *
     * `Solicitudes_01` → `solicitudes`; `tv_Siglo_05` → `tv_siglo`;
     * `01_BLU_Antioquia` → `01_blu_antioquia` (sin sufijo numérico final).
     *
     * @return array<int,string>
     */
    private function prefijosLocales(int $userId, $canalesLocales): array
    {
        if ($canalesLocales === null) {
            $canalesLocales = Canal::where('usuario_id', $userId)->get(['slot_nombre']);
        } else {
            $canalesLocales = $canalesLocales->where('usuario_id', $userId);
        }

        $prefijos = [];
        foreach ($canalesLocales as $canal) {
            $p = $this->prefijo((string) $canal->slot_nombre);
            if ($p !== '') {
                $prefijos[$p] = true;
            }
        }

        return array_keys($prefijos);
    }

    /**
     * Prefijo estable de un nombre de slot, sin el sufijo numérico final.
     *
     * `Solicitudes_05` → `solicitudes`
     * `Puntual_10`     → `puntual`
     * `Telemedellin`   → `telemedellin`
     */
    public function prefijo(string $nombre): string
    {
        $n = $this->normalizar($nombre);
        $sinNumero = preg_replace('/[_\-\s]?\d+$/', '', $n);

        return trim((string) $sinNumero, "_- \t");
    }

    /**
     * Código que TcloudApiService genera para un slot local:
     * minúsculas y espacios/guiones bajos colapsados.
     */
    public function codigoDesdeSlot(string $slotNombre): string
    {
        return $this->normalizar(str_replace([' ', '-'], '_', $slotNombre));
    }

    private function normalizar(?string $valor): string
    {
        return trim(mb_strtolower((string) $valor));
    }

    private function normalizarRuta(?string $ruta): string
    {
        if ($ruta === null) {
            return '';
        }

        return rtrim(trim($ruta), '/');
    }
}
