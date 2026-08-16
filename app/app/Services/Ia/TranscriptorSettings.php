<?php

namespace App\Services\Ia;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Accessor unico de la configuracion del transcriptor, con override en caliente.
 *
 * Precedencia de lectura:
 *   1. Fila en system_settings con clave "transcriptor.<key>"
 *   2. config("transcriptor.<key>")  — la capa env de siempre
 *   3. Default del esquema
 *
 * Borrar la fila restaura el valor de config/transcriptor.php. Esa distincion de
 * tres estados (bd / env / archivo) es lo que effective() reporta a la UI, y es
 * la razon de reutilizar system_settings en vez de una tabla nueva con columnas
 * NOT NULL: no habria forma de expresar "sin override".
 *
 * SCHEMA es la unica fuente de verdad de rangos y tipos: alimenta el accessor,
 * validationRules() y el formulario Blade. Antes cada rango vivia en dos sitios
 * y se desincronizaban (el slider de lote llegaba a 500 mientras el servidor
 * clampeaba a 200 y truncaba en silencio).
 */
class TranscriptorSettings
{
    private const CACHE_KEY = 'transcriptor:settings';
    private const CACHE_TTL_SECONDS = 60;

    /**
     * Refresco de la memo interna. CRITICO: los procesos queue:work viven horas.
     * Memoizar de por vida congelaria los valores hasta reiniciar systemd — que
     * es exactamente el bug que tenia TranscriptorApiClient leyendo los timeouts
     * en el constructor siendo singleton.
     */
    private const MEMO_TTL_SECONDS = 30;

    private const KEY_PREFIX = 'transcriptor.';

    /**
     * type: int | bool | str
     * group: ritmo | descubrimiento | confiabilidad | api | workers | ui
     */
    private const SCHEMA = [
        // === Ritmo / dispatch ===
        'dispatch_paused' => [
            'type' => 'bool', 'group' => 'ritmo', 'default' => false,
            'env_key' => 'TRANSCRIPTOR_DISPATCH_PAUSED',
            'label' => 'Pausar envio',
            'help' => 'Freno de emergencia. El descubrimiento sigue corriendo (no se pierde nada), pero deja de encolarse trabajo y los botones de envio devuelven 423.',
        ],
        'tick_interval_minutes' => [
            'type' => 'int', 'group' => 'ritmo', 'default' => 2, 'min' => 1, 'max' => 60,
            'env_key' => 'TRANSCRIPTOR_TICK_INTERVAL_MINUTES',
            'label' => 'Intervalo de la tarea (min)',
            'help' => 'Cada cuantos minutos la tarea programada escanea y encola.',
        ],
        'dispatch_stagger_ms' => [
            'type' => 'int', 'group' => 'ritmo', 'default' => 0, 'min' => 0, 'max' => 5000,
            'env_key' => 'TRANSCRIPTOR_DISPATCH_STAGGER_MS',
            'label' => 'Goteo entre envios (ms)',
            'help' => 'Separacion entre cada job del lote. 0 = todos de golpe. Con 200ms un lote de 145 se reparte en ~29s en vez de arrancar 145 ffmpeg al mismo tiempo.',
        ],
        'target_redis_queue' => [
            'type' => 'int', 'group' => 'ritmo', 'default' => 140, 'min' => 10, 'max' => 2000,
            'env_key' => 'TRANSCRIPTOR_TARGET_REDIS_QUEUE',
            'label' => 'Objetivo de cola Redis',
            'help' => 'Profundidad de cola por encima de la cual el regulador frena. El transcriptor tiene 2 workers GPU.',
        ],
        'min_batch' => [
            'type' => 'int', 'group' => 'ritmo', 'default' => 10, 'min' => 0, 'max' => 500,
            'env_key' => 'TRANSCRIPTOR_MIN_BATCH',
            'label' => 'Lote minimo',
            'help' => 'Piso del lote, aplicado solo cuando existe margen real bajo el objetivo.',
        ],
        'max_batch' => [
            'type' => 'int', 'group' => 'ritmo', 'default' => 200, 'min' => 1, 'max' => 1000,
            'env_key' => 'TRANSCRIPTOR_MAX_BATCH',
            'label' => 'Lote maximo',
            'help' => 'Techo del lote por ciclo.',
        ],
        'runway' => [
            'type' => 'int', 'group' => 'ritmo', 'default' => 5, 'min' => 0, 'max' => 200,
            'env_key' => 'TRANSCRIPTOR_RUNWAY',
            'label' => 'Margen sobre el objetivo',
            'help' => 'Sobrante que se envia siempre para que el transcriptor no quede idle con la cola justo en el objetivo.',
        ],
        'scope' => [
            'type' => 'str', 'group' => 'ritmo', 'default' => 'current_day',
            'options' => ['current_day', 'unbounded'],
            'env_key' => 'TRANSCRIPTOR_SCOPE',
            'label' => 'Alcance del dispatcher',
            'help' => 'current_day: la tarea solo encola archivos de hoy. unbounded: recuperacion manual desde la UI.',
        ],
        'inflight_max' => [
            'type' => 'int', 'group' => 'ritmo', 'default' => 0, 'min' => 0, 'max' => 48,
            'env_key' => 'TRANSCRIPTOR_INFLIGHT_MAX',
            'label' => 'Maximo ffmpeg simultaneos',
            'help' => '0 = desactivado. Limita la concurrencia REAL con independencia del numero de workers: con 11 workers e inflight_max=4, los sobrantes esperan en el semaforo sin tocar systemd.',
        ],

        // === Descubrimiento ===
        'scan_batch' => [
            'type' => 'int', 'group' => 'descubrimiento', 'default' => 100, 'min' => 1, 'max' => 1000,
            'env_key' => 'TRANSCRIPTOR_SCAN_BATCH',
            'label' => 'Archivos por storage por ciclo',
            'help' => 'Cuantos archivos recientes sin transcripcion toma el scanner en cada storage.',
        ],
        'scan_min_age_seconds' => [
            'type' => 'int', 'group' => 'descubrimiento', 'default' => 60, 'min' => 0, 'max' => 3600,
            'env_key' => 'TRANSCRIPTOR_SCAN_MIN_AGE_SECONDS',
            'label' => 'Edad minima del archivo (s)',
            'help' => 'Tiempo sin modificarse para considerar que la grabacion termino de escribirse.',
        ],
        'scan_days_back' => [
            'type' => 'int', 'group' => 'descubrimiento', 'default' => 0, 'min' => 0, 'max' => 30,
            'env_key' => 'TRANSCRIPTOR_SCAN_DAYS_BACK',
            'label' => 'Dias hacia atras',
            'help' => 'Dias ademas de hoy que escanea el comando por defecto. 0 = solo hoy.',
        ],
        'scan_max_dispatch_per_cycle' => [
            'type' => 'int', 'group' => 'descubrimiento', 'default' => 200, 'min' => 1, 'max' => 2000,
            'env_key' => 'TRANSCRIPTOR_SCAN_MAX_DISPATCH_PER_CYCLE',
            'label' => 'Tope de encolado por ejecucion',
            'help' => 'Techo absoluto de scan-and-submit. Sustituye al antiguo scan_batch x numero de storages, que encolaba 3100 jobs de golpe.',
        ],

        // === Confiabilidad ===
        'stale_after_minutes' => [
            'type' => 'int', 'group' => 'confiabilidad', 'default' => 30, 'min' => 5, 'max' => 1440,
            'env_key' => 'TRANSCRIPTOR_STALE_AFTER_MINUTES',
            'label' => 'Antiguedad para considerar atascado (min)',
            'help' => 'Un pending sin job_id mas viejo que esto se reenvia.',
        ],
        'stale_resend_limit' => [
            'type' => 'int', 'group' => 'confiabilidad', 'default' => 50, 'min' => 0, 'max' => 500,
            'env_key' => 'TRANSCRIPTOR_STALE_RESEND_LIMIT',
            'label' => 'Reenvios atascados por ciclo',
            'help' => 'Cada reenvio corre ffmpeg + POST sincronos, cada minuto, en paralelo con los workers. Bajarlo alivia los picos.',
        ],
        'poll_limit' => [
            'type' => 'int', 'group' => 'confiabilidad', 'default' => 140, 'min' => 10, 'max' => 1000,
            'env_key' => 'TRANSCRIPTOR_POLL_LIMIT',
            'label' => 'Jobs consultados por ciclo',
            'help' => 'Deberia ir al menos al nivel del objetivo de cola, o el poll no alcanza al dispatch.',
        ],
        'poll_max_age_hours' => [
            'type' => 'int', 'group' => 'confiabilidad', 'default' => 48, 'min' => 2, 'max' => 720,
            'env_key' => 'TRANSCRIPTOR_POLL_MAX_AGE_HOURS',
            'label' => 'Antiguedad maxima en queued (horas)',
            'help' => 'Pasado este plazo una transcripcion en queued/processing se cierra como dead en vez de sondearse indefinidamente. Es la red que evita backlogs zombis que consumen los slots del poll.',
        ],
        'max_retries' => [
            'type' => 'int', 'group' => 'confiabilidad', 'default' => 3, 'min' => 1, 'max' => 10,
            'env_key' => 'TRANSCRIPTOR_MAX_RETRIES',
            'label' => 'Reintentos antes de dead',
            'help' => 'Fallos consecutivos tras los que una transcripcion se promueve a dead.',
        ],
        'corrections_chunk' => [
            'type' => 'int', 'group' => 'confiabilidad', 'default' => 500, 'min' => 50, 'max' => 5000,
            'env_key' => 'TRANSCRIPTOR_CORRECTIONS_CHUNK',
            'label' => 'Chunk de correcciones retroactivas',
            'help' => 'Tamano de bloque al aplicar el diccionario sobre segmentos existentes.',
        ],
        'srt_max_segment_chars' => [
            'type' => 'int', 'group' => 'confiabilidad', 'default' => 3000, 'min' => 0, 'max' => 20000,
            'env_key' => 'TRANSCRIPTOR_SRT_MAX_SEGMENT_CHARS',
            'label' => 'Longitud maxima de segmento (chars)',
            'help' => 'Por encima de esto el texto del segmento se corta y deja de ser buscable. 0 = sin limite. Los segmentos largos son habla continua real (emisoras de radio), no basura.',
        ],

        // === API ===
        'submit_timeout' => [
            'type' => 'int', 'group' => 'api', 'default' => 60, 'min' => 10, 'max' => 600,
            'env_key' => 'TRANSCRIPTOR_SUBMIT_TIMEOUT',
            'label' => 'Timeout de envio (s)',
            'help' => 'Debe quedar por debajo del timeout del job (600s).',
        ],
        'get_timeout' => [
            'type' => 'int', 'group' => 'api', 'default' => 30, 'min' => 5, 'max' => 300,
            'env_key' => 'TRANSCRIPTOR_GET_TIMEOUT',
            'label' => 'Timeout de consulta (s)',
            'help' => 'Para los GET de estado, SRT y stats.',
        ],
        'submit_max_attempts' => [
            'type' => 'int', 'group' => 'api', 'default' => 3, 'min' => 1, 'max' => 5,
            'env_key' => 'TRANSCRIPTOR_SUBMIT_MAX_ATTEMPTS',
            'label' => 'Intentos del POST de envio',
            'help' => 'Solo reintenta ante fallo de conexion o 5xx. Un 4xx es permanente y no se reintenta.',
        ],
        'submit_retry_base_ms' => [
            'type' => 'int', 'group' => 'api', 'default' => 500, 'min' => 100, 'max' => 10000,
            'env_key' => 'TRANSCRIPTOR_SUBMIT_RETRY_BASE_MS',
            'label' => 'Espera base entre intentos (ms)',
            'help' => 'Retardo antes de reintentar un envio fallido.',
        ],
        'language' => [
            'type' => 'str', 'group' => 'api', 'default' => 'es',
            'options' => ['es', 'en'],
            'env_key' => 'TRANSCRIPTOR_LANGUAGE',
            'label' => 'Idioma',
            'help' => 'Idioma enviado a la API del transcriptor.',
        ],
        'lang_fix' => [
            'type' => 'str', 'group' => 'api', 'default' => 'async',
            'options' => ['off', 'async', 'auto'],
            'env_key' => 'TRANSCRIPTOR_LANG_FIX',
            'label' => 'Correccion de idioma',
            'help' => 'Modo de correccion automatica de idioma en el transcriptor.',
        ],
        'audio_output_format' => [
            'type' => 'str', 'group' => 'api', 'default' => 'wav',
            'options' => ['wav', 'opus'],
            'env_key' => 'TRANSCRIPTOR_AUDIO_OUTPUT_FORMAT',
            'label' => 'Formato de envio',
            'help' => 'Formato al que ffmpeg convierte antes de subir. wav = pcm_s16le 16kHz mono, sin perdida (~115 MB/hora). opus = libopus 64k (~29 MB/hora, con perdida). La API acepta ambos.',
        ],
        'min_shm_free_bytes' => [
            'type' => 'int', 'group' => 'api', 'default' => 200_000_000, 'min' => 10_000_000, 'max' => 4_000_000_000,
            'env_key' => 'TRANSCRIPTOR_MIN_SHM_FREE_BYTES',
            'label' => 'Minimo libre en /dev/shm (bytes)',
            'help' => 'Si /dev/shm tiene menos de esto, el submit se aborta antes de invocar ffmpeg y el job se reencola para el siguiente ciclo. Default 200 MB cubre ~5 WAVs en vuelo + reintentos.',
        ],
        'requeue_after_minutes' => [
            'type' => 'int', 'group' => 'api', 'default' => 5, 'min' => 1, 'max' => 60,
            'env_key' => 'TRANSCRIPTOR_REQUEUE_AFTER_MINUTES',
            'label' => 'Minutos hasta reintento tras rebote',
            'help' => 'Cuando un job rebota por pre-flight (tmpfs sin espacio), el tick lo ignora durante este tiempo. Pasado el plazo se reencola normalmente.',
        ],
        'shm_warn_percent' => [
            'type' => 'int', 'group' => 'api', 'default' => 80, 'min' => 50, 'max' => 99,
            'env_key' => 'TRANSCRIPTOR_SHM_WARN_PERCENT',
            'label' => 'Umbral de WARNING en /dev/shm (%)',
            'help' => 'Por encima de este porcentaje, transcription:check-shm-health emite un Log::warning. Default 80 detecta la fuga antes de que /dev/shm llegue al 100%.',
        ],

        // === Workers ===
        'worker_min' => [
            'type' => 'int', 'group' => 'workers', 'default' => 3, 'min' => 0, 'max' => 12,
            'env_key' => 'TRANSCRIPTOR_WORKER_MIN',
            'label' => 'Workers minimo',
            'help' => 'Piso del pool calculado automaticamente.',
        ],
        'worker_max' => [
            'type' => 'int', 'group' => 'workers', 'default' => 12, 'min' => 1, 'max' => 12,
            'env_key' => 'TRANSCRIPTOR_WORKER_MAX',
            'label' => 'Workers maximo',
            'help' => 'Techo del pool. Se acota ademas al numero de units systemd realmente instaladas.',
        ],
        'worker_ratio' => [
            'type' => 'int', 'group' => 'workers', 'default' => 6, 'min' => 1, 'max' => 50,
            'env_key' => 'TRANSCRIPTOR_WORKER_RATIO',
            'label' => 'Medios por worker',
            'help' => 'workers = medios_totales / este valor, acotado entre minimo y maximo.',
        ],
        'worker_override' => [
            'type' => 'int', 'group' => 'workers', 'default' => 0, 'min' => 0, 'max' => 12,
            'env_key' => 'TRANSCRIPTOR_WORKER_OVERRIDE',
            'label' => 'Workers forzados',
            'help' => '0 = automatico. Con un valor distinto de 0 el tuner lo usa tal cual, ignorando la formula.',
        ],

        // === UI ===
        'ui_max_parallel_sends' => [
            'type' => 'int', 'group' => 'ui', 'default' => 3, 'min' => 1, 'max' => 12,
            'env_key' => 'TRANSCRIPTOR_UI_MAX_PARALLEL_SENDS',
            'label' => 'Envios simultaneos desde el navegador',
            'help' => 'Cada envio corre ffmpeg + POST sincronos dentro de php-fpm. Sin tope, seleccionar 200 archivos levantaba 200 procesos.',
        ],
        'ui_batch_max' => [
            'type' => 'int', 'group' => 'ui', 'default' => 200, 'min' => 10, 'max' => 1000,
            'env_key' => 'TRANSCRIPTOR_UI_BATCH_MAX',
            'label' => 'Tope del slider de lote',
            'help' => 'Alinea el maximo del slider con el clamp del servidor para que no trunque en silencio.',
        ],
        'min_file_size_bytes' => [
            'type' => 'int', 'group' => 'confiabilidad', 'default' => 1024, 'min' => 0, 'max' => 1048576,
            'env_key' => 'TRANSCRIPTOR_MIN_FILE_SIZE_BYTES',
            'label' => 'Tamano minimo del audio (bytes)',
            'help' => 'Archivos por debajo de este tamano se descartan sin pasar por ffmpeg. Evita ciclos de retry sobre radios truncados (0 bytes). 0 = desactivar.',
        ],

        // === Coherencia IA (pase de correccion de spanglish residual) ===
        'ai_coherence_enabled' => [
            'type' => 'bool', 'group' => 'ia', 'default' => true,
            'env_key' => 'TRANSCRIPTOR_AI_COHERENCE_ENABLED',
            'label' => 'Pase de coherencia IA',
            'help' => 'Corrige con LLM los segmentos con ingles residual que el diccionario no cubre, dejando la transcripcion en espanol coherente.',
        ],
        'ai_coherence_threshold' => [
            'type' => 'float', 'group' => 'ia', 'default' => 0.4, 'min' => 0.0, 'max' => 1.0,
            'env_key' => 'TRANSCRIPTOR_AI_COHERENCE_THRESHOLD',
            'label' => 'Umbral de ingles residual',
            'help' => 'Score minimo (en/(en+es)) para considerar un segmento con ingles residual y enviarlo al LLM. Mismo umbral que el detector.',
        ],
        'ai_coherence_max_segments' => [
            'type' => 'int', 'group' => 'ia', 'default' => 20, 'min' => 1, 'max' => 200,
            'env_key' => 'TRANSCRIPTOR_AI_COHERENCE_MAX_SEGMENTS',
            'label' => 'Tope de segmentos por transcripcion',
            'help' => 'Maximo de segmentos a corregir con IA por transcripcion. Controla costo/latencia en transcripciones con mucho ingles (ej. musica).',
        ],
        'ai_coherence_model' => [
            'type' => 'str', 'group' => 'ia', 'default' => '',
            'env_key' => 'TRANSCRIPTOR_AI_COHERENCE_MODEL',
            'label' => 'Modelo de coherencia IA',
            'help' => 'Modelo LLM a usar. Vacio = usa el de llm-correction.model.',
        ],
    ];

    /** @var array<string,string> */
    private array $memo = [];

    private float $memoLoadedAt = 0.0;

    // ---------------------------------------------------------------- lectura

    public function get(string $key): mixed
    {
        $spec = self::SCHEMA[$key] ?? null;
        if ($spec === null) {
            return config(self::KEY_PREFIX . $key);
        }

        $persisted = $this->map()[self::KEY_PREFIX . $key] ?? null;
        $raw = $persisted ?? config(self::KEY_PREFIX . $key, $spec['default']);

        $value = $this->coerce($raw, $spec);

        // Un override guardado en BD fuera de rango se recorta en silencio y el
        // operador cree estar corriendo con el valor que escribio. Paso con
        // min_file_size_bytes = 100MB, recortado a 1MB por el max del schema:
        // durante dias mando cientos de ficheros/dia a dead sin explicacion.
        if ($persisted !== null && (string) $value !== (string) $persisted) {
            $this->warnClamped($key, (string) $persisted, (string) $value);
        }

        return $value;
    }

    /**
     * Avisa una sola vez por clave y por proceso: `get()` se llama en bucles
     * dentro de workers que viven horas.
     *
     * @var array<string,true>
     */
    private static array $clampWarned = [];

    private function warnClamped(string $key, string $stored, string $effective): void
    {
        if (isset(self::$clampWarned[$key])) {
            return;
        }
        self::$clampWarned[$key] = true;

        Log::warning('TranscriptorSettings: override fuera de rango, valor efectivo distinto del guardado', [
            'key' => self::KEY_PREFIX . $key,
            'stored' => $stored,
            'effective' => $effective,
            'min' => self::SCHEMA[$key]['min'] ?? null,
            'max' => self::SCHEMA[$key]['max'] ?? null,
        ]);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function float(string $key): float
    {
        return (float) $this->get($key);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function str(string $key): string
    {
        return (string) $this->get($key);
    }

    /**
     * Lote a encolar dado el estado actual de la cola.
     *
     * El deficit se evalua ANTES del clamp: aplicar min_batch primero volvia
     * inalcanzable el freno (con min_batch=10 el resultado nunca era <=0), y el
     * tick seguia inyectando 10 jobs cada ciclo sobre una cola saturada.
     *
     * Fuente unica para TranscriptionTickCommand y ScanAndSubmitCommand, de modo
     * que el camino manual nunca pueda exceder al automatico.
     */
    public function computeDispatchBatch(int $current): int
    {
        $deficit = $this->int('target_redis_queue') - $current + $this->int('runway');

        if ($deficit <= 0) {
            return 0;
        }

        return max($this->int('min_batch'), min($this->int('max_batch'), $deficit));
    }

    // --------------------------------------------------------------- escritura

    /**
     * @param  array<string,mixed>  $values
     * @return array<string,mixed> mapa efectivo tras aplicar
     *
     * @throws ValidationException
     */
    public function set(array $values): array
    {
        [$clean, $errors] = $this->validate($values);

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        foreach ($clean as $key => $value) {
            SystemSetting::set(
                self::KEY_PREFIX . $key,
                self::SCHEMA[$key]['type'] === 'bool' ? ($value ? '1' : '0') : (string) $value
            );
        }

        $this->flush();

        return $this->effectiveValues();
    }

    /**
     * Valida sin persistir. Separado de set() para que la validacion sea
     * comprobable y reutilizable sin tocar la BD.
     *
     * @param  array<string,mixed>  $values
     * @return array{0: array<string,mixed>, 1: array<string,list<string>>} [limpios, errores]
     */
    public function validate(array $values): array
    {
        $clean = [];
        $errors = [];

        foreach ($values as $key => $value) {
            $spec = self::SCHEMA[$key] ?? null;
            if ($spec === null) {
                $errors[$key] = ["La clave '{$key}' no existe."];
                continue;
            }

            $error = $this->validateOne($key, $value, $spec);
            if ($error !== null) {
                $errors[$key] = [$error];
                continue;
            }

            $clean[$key] = $this->coerce($value, $spec);
        }

        // Invariantes cruzadas: se evaluan sobre el estado RESULTANTE, no solo
        // sobre lo enviado, para que actualizar una sola clave no pueda dejar
        // el sistema en un estado incoherente — ni que enviar dos claves
        // coherentes entre si falle por comparar cada una contra el estado viejo.
        foreach ($this->crossFieldErrors($clean) as $key => $message) {
            $errors[$key] = [$message];
        }

        return [$clean, $errors];
    }

    /**
     * @param  list<string>  $keys  vacio = todas
     */
    public function reset(array $keys = []): array
    {
        $keys = $keys ?: array_keys(self::SCHEMA);

        $prefixed = array_map(
            fn (string $k) => self::KEY_PREFIX . $k,
            array_values(array_intersect($keys, array_keys(self::SCHEMA)))
        );

        if ($prefixed) {
            SystemSetting::whereIn('key', $prefixed)->delete();
        }

        $this->flush();

        return $this->effectiveValues();
    }

    // ------------------------------------------------------------ introspeccion

    /**
     * Reglas de validacion derivadas del esquema. Las consume el controller para
     * que el formulario y la validacion no puedan desincronizarse.
     *
     * @return array<string,string>
     */
    public function validationRules(): array
    {
        $rules = [];

        foreach (self::SCHEMA as $key => $spec) {
            $r = ['sometimes'];

            if ($spec['type'] === 'bool') {
                $r[] = 'boolean';
            } elseif ($spec['type'] === 'int') {
                $r[] = 'integer';
                if (isset($spec['min'])) $r[] = 'min:' . $spec['min'];
                if (isset($spec['max'])) $r[] = 'max:' . $spec['max'];
            } else {
                $r[] = 'string';
                if (isset($spec['options'])) $r[] = 'in:' . implode(',', $spec['options']);
            }

            $rules[$key] = implode('|', $r);
        }

        return $rules;
    }

    /**
     * Valor efectivo, default y ORIGEN de cada clave.
     *
     * source distingue 'bd' (hay override), 'env' (la variable esta definida en
     * el entorno) y 'archivo' (literal de config/transcriptor.php). Es lo que
     * permite a la UI ofrecer "restaurar" con sentido.
     */
    public function effective(): array
    {
        $map = $this->map();
        $out = [];

        foreach (self::SCHEMA as $key => $spec) {
            $hasDbRow = array_key_exists(self::KEY_PREFIX . $key, $map);
            $envValue = isset($spec['env_key']) ? env($spec['env_key']) : null;

            $value = $this->get($key);

            // Un override guardado fuera de rango se recorta al leerlo. Sin
            // exponer el valor crudo, la UI mostraba el efectivo como si fuera
            // lo que el operador escribio: min_file_size_bytes figuraba en
            // 100MB mientras el pipeline aplicaba 1MB.
            $stored = $hasDbRow ? $map[self::KEY_PREFIX . $key] : null;
            $clamped = $stored !== null && (string) $stored !== (string) $value;

            $out[$key] = [
                'key' => $key,
                'value' => $value,
                'stored' => $stored,
                'clamped' => $clamped,
                'default' => $this->coerce(config(self::KEY_PREFIX . $key, $spec['default']), $spec),
                'schema_default' => $spec['default'],
                'source' => $hasDbRow ? 'bd' : ($envValue !== null ? 'env' : 'archivo'),
                'type' => $spec['type'],
                'group' => $spec['group'],
                'min' => $spec['min'] ?? null,
                'max' => $spec['max'] ?? null,
                'options' => $spec['options'] ?? null,
                'label' => $spec['label'],
                'help' => $spec['help'],
            ];
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function effectiveValues(): array
    {
        $out = [];
        foreach (array_keys(self::SCHEMA) as $key) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::SCHEMA);
    }

    public function has(string $key): bool
    {
        return isset(self::SCHEMA[$key]);
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->memo = [];
        $this->memoLoadedAt = 0.0;
    }

    // ---------------------------------------------------------------- internos

    /**
     * Mapa clave->valor crudo de los overrides en BD.
     *
     * Una lectura de Redis por proceso cada 30s y una de BD cada 60s. El store
     * de cache es Redis, compartido entre php-fpm y los workers CLI, asi que el
     * flush() desde la UI propaga a todos los procesos.
     *
     * @return array<string,string>
     */
    private function map(): array
    {
        if ($this->memo !== [] && (microtime(true) - $this->memoLoadedAt) < self::MEMO_TTL_SECONDS) {
            return $this->memo;
        }

        try {
            $this->memo = Cache::remember(
                self::CACHE_KEY,
                self::CACHE_TTL_SECONDS,
                fn () => SystemSetting::query()
                    ->where('key', 'like', self::KEY_PREFIX . '%')
                    ->pluck('value', 'key')
                    ->all()
            );
        } catch (\Throwable $e) {
            // Sin BD ni cache seguimos operando con config/env. Que artisan siga
            // funcionando con la base caida es deliberado.
            $this->memo = [];
        }

        $this->memoLoadedAt = microtime(true);

        return $this->memo;
    }

    /**
     * Coercion + clamp. El clamp se aplica TAMBIEN en lectura para que una fila
     * corrupta o un rango que se estrecho despues no puedan producir un valor
     * fuera de rango en runtime.
     */
    private function coerce(mixed $raw, array $spec): mixed
    {
        if ($spec['type'] === 'bool') {
            return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $spec['default'];
        }

        if ($spec['type'] === 'int') {
            $v = (int) $raw;
            if (isset($spec['min'])) $v = max($spec['min'], $v);
            if (isset($spec['max'])) $v = min($spec['max'], $v);

            return $v;
        }

        $v = (string) $raw;
        if (isset($spec['options']) && !in_array($v, $spec['options'], true)) {
            return $spec['default'];
        }

        return $v;
    }

    private function validateOne(string $key, mixed $value, array $spec): ?string
    {
        if ($spec['type'] === 'bool') {
            if (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null) {
                return "'{$key}' debe ser booleano.";
            }

            return null;
        }

        if ($spec['type'] === 'int') {
            if (!is_numeric($value) || (int) $value != $value) {
                return "'{$key}' debe ser un entero.";
            }
            $v = (int) $value;
            if (isset($spec['min']) && $v < $spec['min']) {
                return "'{$key}' debe ser >= {$spec['min']}.";
            }
            if (isset($spec['max']) && $v > $spec['max']) {
                return "'{$key}' debe ser <= {$spec['max']}.";
            }

            return null;
        }

        $v = (string) $value;
        if (isset($spec['options']) && !in_array($v, $spec['options'], true)) {
            return "'{$key}' debe ser uno de: " . implode(', ', $spec['options']) . '.';
        }

        return null;
    }

    /**
     * Invariantes entre claves, evaluadas sobre el estado resultante.
     *
     * @param  array<string,mixed>  $incoming
     * @return array<string,string>
     */
    private function crossFieldErrors(array $incoming): array
    {
        $val = fn (string $k) => array_key_exists($k, $incoming) ? $incoming[$k] : $this->get($k);

        $errors = [];

        if ((int) $val('min_batch') > (int) $val('max_batch')) {
            $errors['min_batch'] = 'El lote minimo no puede superar al maximo.';
        }

        if ((int) $val('worker_min') > (int) $val('worker_max')) {
            $errors['worker_min'] = 'El minimo de workers no puede superar al maximo.';
        }

        if ((int) $val('runway') > (int) $val('target_redis_queue')) {
            $errors['runway'] = 'El margen no puede superar al objetivo de cola.';
        }

        // El POST de envio debe caber dentro del timeout del job, o el worker
        // muere a mitad de peticion y la transcripcion queda sin job_id.
        $jobTimeout = 600;
        if ((int) $val('submit_timeout') >= $jobTimeout) {
            $errors['submit_timeout'] = "El timeout de envio debe ser menor que el del job ({$jobTimeout}s).";
        }

        return $errors;
    }
}
