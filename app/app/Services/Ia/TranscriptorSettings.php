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
            'help' => 'Freno de emergencia global. El descubrimiento sigue corriendo (no se pierde nada), pero el tick deja de inyectar trabajo en la cola y los endpoints de envio devuelven HTTP 423.',
            'icon' => 'fa-hand',
            'scope' => 'local',
            'state' => 'live',
            'detail' => [
                'alcance' => 'Tick planner (transcription:tick) y endpoints de envio manual (POST /ia/api-transcriptor/jobs/bulk-dispatch).',
                'cuando_tocar' => 'Subir a true si la API upstream esta en problemas y queres dejar de alimentar la cola. No apaga el escaneo, asi que no se pierden archivos.',
                'riesgos' => 'Si lo activas y te olvidas, el operador ve "0 encolados" en el panel y puede pensar que el sistema esta caido. Reset a false siempre deja todo en marcha sin mas.',
            ],
        ],
        'tick_interval_minutes' => [
            'type' => 'int', 'group' => 'ritmo', 'default' => 3, 'min' => 1, 'max' => 60,
            'env_key' => 'TRANSCRIPTOR_TICK_INTERVAL_MINUTES',
            'label' => 'Intervalo de la tarea (min)',
            'help' => 'Cada cuantos minutos corre la tarea programada transcription:tick que escanea storages y encola pendientes.',
            'icon' => 'fa-clock',
            'scope' => 'local',
            'state' => 'live',
        ],
        'target_pg_queue' => [
            'type' => 'int', 'group' => 'ritmo', 'default' => 140, 'min' => 10, 'max' => 2000,
            'env_key' => 'TRANSCRIPTOR_TARGET_PG_QUEUE',
            'label' => 'Objetivo de cola PG',
            'help' => 'Profundidad objetivo de la cola nativa (transcriptions.state=pending). Por encima de este valor el regulador frena el despacho.',
            'icon' => 'fa-layer-group',
            'scope' => 'local',
            'state' => 'live',
            'detail' => [
                'alcance' => 'Tabla transcriptions (cola nativa PG), regulador del tick (computeDispatchBatch).',
                'cuando_tocar' => 'Subir si tenés menos de ~20 audios/hora encolándose y querés más concurrencia. Bajar si la GPU remota se queja de que la cola va muy llena.',
                'riesgos' => 'Subir demasiado puede hipersaturar la API upstream y disparar el circuit breaker. El corte por seguridad lo hace el circuit_breaker, no este setting.',
            ],
        ],
        // === Burst-dispatch (controlado por cola API remota) ===
        //
        // El transcriptor-pg-native-queue introdujo el worker PG que consume
        // directamente de `transcriptions`. Ese worker hace ffmpeg local + POST
        // individual, lo que satura CPU y ramdisk cuando hay backlog grande.
        //
        // El burst-dispatcher es un daemon que mira la cola de la API remota
        // (http://...:9000/api/metrics/overview) y dispara rafagas controladas
        // de ffmpeg+POST, manteniendo el equilibrio:
        //   - Si upstream.queue.queued + processing >= max_upstream_queue → pausa
        //   - Si hay headroom → dispara lote de hasta batch_size con
        //     parallel_ffmpeg simultaneos
        'burst_max_upstream_queue' => [
            'type' => 'int', 'group' => 'burst', 'default' => 180, 'min' => 10, 'max' => 1000,
            'env_key' => 'TRANSCRIPTOR_BURST_MAX_UPSTREAM_QUEUE',
            'label' => 'Techo de cola upstream antes de pausar',
            'help' => 'Si la API remota tiene queue.queued + processing >= este valor, el burst-dispatcher pausa. Validado contra /api/metrics/overview cada poll_interval_seconds.',
            'icon' => 'fa-bolt',
            'scope' => 'mixto',
            'state' => 'manual',
            'detail' => [
                'alcance' => 'Comando manual transcription:burst-dispatch (NO esta en el cron automatico).',
                'cuando_tocar' => 'Subir si la API remota se satura con frecuencia. Bajar si la API puede absorber mas y querés throughput.',
                'riesgos' => 'Si el cron automatico nunca corre, este knob es irrelevante en operacion normal. Sirve solo cuando alguien lanza el dispatcher manual.',
            ],
        ],
        'burst_batch_size' => [
            'type' => 'int', 'group' => 'burst', 'default' => 6, 'min' => 1, 'max' => 200,
            'env_key' => 'TRANSCRIPTOR_BURST_BATCH_SIZE',
            'label' => 'Tamano del lote (goteo)',
            'help' => 'Cantidad de audios que toma el burst-dispatcher por ciclo cuando hay headroom. Default 6: bloques pequenos para mantener CPU bajo y envio constante en serie. Subir (20-40) solo para drenaje agresivo de backlog extremo.',
            'icon' => 'fa-burst',
            'scope' => 'mixto',
            'state' => 'manual',
        ],
        'burst_parallel_ffmpeg' => [
            'type' => 'int', 'group' => 'burst', 'default' => 1, 'min' => 1, 'max' => 16,
            'env_key' => 'TRANSCRIPTOR_BURST_PARALLEL_FFMPEG',
            'label' => 'ffmpeg/POST simultaneos dentro del lote',
            'help' => 'Cantidad de procesos paralelos (proc_open) que procesan audios del lote. Default 1 = serie puro (goteo constante, CPU bajo). 2-3 = trickle con algo de paralelismo. 8+ = agresivo (legacy, no recomendado en operacion normal). Cada proceso Laravel usa ~100MB RAM.',
            'icon' => 'fa-bars-staggered',
            'scope' => 'local',
            'state' => 'manual',
        ],
        'burst_poll_interval_seconds' => [
            'type' => 'int', 'group' => 'burst', 'default' => 2, 'min' => 1, 'max' => 60,
            'env_key' => 'TRANSCRIPTOR_BURST_POLL_INTERVAL_SECONDS',
            'label' => 'Intervalo de verificacion de cola upstream (s)',
            'help' => 'Cuando no hay headroom o candidatos, el daemon espera este tiempo antes de re-verificar /api/metrics/overview. Default 2s = respuesta rapida cuando la GPU remota drena.',
            'icon' => 'fa-clock',
            'scope' => 'mixto',
            'state' => 'manual',
        ],
        'min_batch' => [
            'type' => 'int', 'group' => 'ritmo', 'default' => 10, 'min' => 0, 'max' => 500,
            'env_key' => 'TRANSCRIPTOR_MIN_BATCH',
            'label' => 'Lote minimo',
            'help' => 'Piso del lote, aplicado solo cuando existe margen real bajo el objetivo.',
            'icon' => 'fa-boxes-stacked',
            'scope' => 'local',
            'state' => 'live',
        ],
        'max_batch' => [
            'type' => 'int', 'group' => 'ritmo', 'default' => 200, 'min' => 1, 'max' => 1000,
            'env_key' => 'TRANSCRIPTOR_MAX_BATCH',
            'label' => 'Lote maximo',
            'help' => 'Techo del lote por ciclo.',
            'icon' => 'fa-boxes-stacked',
            'scope' => 'local',
            'state' => 'live',
        ],
        'runway' => [
            'type' => 'int', 'group' => 'ritmo', 'default' => 5, 'min' => 0, 'max' => 200,
            'env_key' => 'TRANSCRIPTOR_RUNWAY',
            'label' => 'Margen sobre el objetivo',
            'help' => 'Sobrante que se envia siempre para que el transcriptor no quede idle con la cola justo en el objetivo.',
            'icon' => 'fa-arrow-right-long',
            'scope' => 'local',
            'state' => 'live',
        ],
        'submit_with_idempotency_key' => [
            'type' => 'bool', 'group' => 'saturacion', 'default' => true,
            'env_key' => 'TRANSCRIPTOR_SUBMIT_WITH_IDEMPOTENCY_KEY',
            'label' => 'Enviar idempotency_key al upstream',
            'help' => 'Calcula hash SHA256 del wav enviado y lo manda al upstream; la API deduplica si recibe el mismo hash en su ventana. Recomendado para evitar dobles transcripciones en reenvios.',
            'icon' => 'fa-fingerprint',
            'scope' => 'remoto',
            'state' => 'live',
        ],
        'submit_with_callback' => [
            'type' => 'bool', 'group' => 'webhook', 'default' => false,
            'env_key' => 'TRANSCRIPTOR_SUBMIT_WITH_CALLBACK',
            'label' => 'Enviar callback_url al upstream',
            'help' => 'Off por defecto. Cuando se activa, el upstream notifica via webhook (Fase D) en vez de esperar al polling. Requiere TCLOUD_CALLBACK_URL configurado.',
            'icon' => 'fa-bell-concierge',
            'scope' => 'mixto',
            'state' => 'experimental',
            'detail' => [
                'alcance' => 'TranscriptorApiClient::submit() agrega header callback_url. TranscriptionWebhookController expone POST /webhooks/transcription para recibir.',
                'cuando_tocar' => 'Solo si tu upstream ya implementa Fase D y tenés un endpoint publico accesible. Coordinar con el equipo de la API antes de activar.',
                'riesgos' => 'Si webhook_secret no coincide con el que conoce el upstream, los webhooks se rechazan y el polling normal los recoge igual. Si el endpoint publico no responde 200 rapido, el upstream puede hacer backoff agresivo.',
            ],
        ],
        'webhook_secret' => [
            'type' => 'str', 'group' => 'webhook', 'default' => '',
            'env_key' => 'TCLOUD_WEBHOOK_SECRET',
            'label' => 'Secreto HMAC webhook',
            'help' => 'Secreto compartido con el upstream para firmar los webhooks entrantes. Generar con: openssl rand -hex 32',
            'icon' => 'fa-key',
            'scope' => 'mixto',
            'state' => 'experimental',
        ],
        'max_backoff_seconds' => [
            'type' => 'int', 'group' => 'saturacion', 'default' => 300, 'min' => 5, 'max' => 3600,
            'env_key' => 'TRANSCRIPTOR_MAX_BACKOFF_SECONDS',
            'label' => 'Backoff maximo (503)',
            'help' => 'Si el upstream responde 503 sin header Retry-After, el sistema espera este numero de segundos antes de reintentar.',
            'icon' => 'fa-hourglass-end',
            'scope' => 'remoto',
            'state' => 'live',
        ],
        'circuit_breaker_threshold' => [
            'type' => 'int', 'group' => 'saturacion', 'default' => 3, 'min' => 1, 'max' => 50,
            'env_key' => 'TRANSCRIPTOR_CIRCUIT_BREAKER_THRESHOLD',
            'label' => 'Strikes para abrir circuit',
            'help' => 'Cantidad de respuestas 4xx/5xx en una ventana de 5 min para que el circuit breaker se abra y el tick frene con reason=upstream_circuit_open.',
            'icon' => 'fa-plug-circle-bolt',
            'scope' => 'remoto',
            'state' => 'live',
        ],
        'circuit_breaker_open_seconds' => [
            'type' => 'int', 'group' => 'saturacion', 'default' => 60, 'min' => 5, 'max' => 600,
            'env_key' => 'TRANSCRIPTOR_CIRCUIT_BREAKER_OPEN_SECONDS',
            'label' => 'Duracion del circuit abierto (s)',
            'help' => 'Tiempo que el break queda abierto antes de pasar a half-open. Durante este periodo el tick no encola.',
            'icon' => 'fa-stopwatch',
            'scope' => 'remoto',
            'state' => 'live',
        ],
        'scope' => [
            'type' => 'str', 'group' => 'ritmo', 'default' => 'current_day',
            'options' => ['current_day', 'unbounded'],
            'env_key' => 'TRANSCRIPTOR_SCOPE',
            'label' => 'Alcance del dispatcher',
            'help' => 'current_day: la tarea solo encola archivos de hoy. unbounded: recuperacion manual desde la UI.',
            'icon' => 'fa-calendar-day',
            'scope' => 'local',
            'state' => 'live',
            'detail' => [
                'alcance' => 'TranscriptionTickCommand (cron automatico) y ScanAndSubmitCommand (manual).',
                'cuando_tocar' => 'Dejar en current_day salvo rescate manual. Para rescuatar dias pasados, usar la opcion unbounded desde el boton "Escanear storages" de la UI.',
                'riesgos' => 'Si el operador lo deja en unbounded y reinicia el cron, se pueden encolar dias enteros de golpe. Aplica solo el momento del escaneo, no es persistente.',
            ],
        ],
        'regulator_mode' => [
            'type' => 'str', 'group' => 'ritmo', 'default' => 'remote_aware',
            'options' => ['local_only', 'remote_aware', 'hybrid'],
            'env_key' => 'TRANSCRIPTOR_REGULATOR_MODE',
            'label' => 'Modo del regulador',
            'help' => 'Senal que usa el tick para frenar. remote_aware (default): la cola PG local NO frena; solo las señales remotas (RAM, ramdisk, cola remota) + shm + inflight + circuit. local_only: además frena por target_pg_queue. hybrid: cualquiera de las senales dispara freno (sin freno por cola PG).',
            'icon' => 'fa-sliders',
            'scope' => 'mixto',
            'state' => 'live',
            'detail' => [
                'alcance' => 'TranscriptorSettings::decideDispatch() (regulador del tick). Combina 4 senales: cola PG, shm libre, GPU remota, RAM remota.',
                'cuando_tocar' => 'Si tenes una API upstream potente pero pocos audios: subir a remote_aware/hybrid para no frenar por cola local cuando la GPU aguanta. Si tenes muchas radios locales: dejar en local_only.',
                'riesgos' => 'remote_aware gasta una llamada HTTP cada tick; si la API upstream esta caida, el regulador falla open (no dispara freno), por eso hybrid es mas conservador.',
            ],
        ],
        'regulator_remote_cache_seconds' => [
            'type' => 'int', 'group' => 'ritmo', 'default' => 15, 'min' => 1, 'max' => 300,
            'env_key' => 'TRANSCRIPTOR_REGULATOR_REMOTE_CACHE_SECONDS',
            'label' => 'Cache de /api/info (s)',
            'help' => 'TTL en el store de caché de la lectura de /api/info. Bajo para no castigar al nodo ASR; alto para menos latencia.',
            'icon' => 'fa-database',
            'scope' => 'mixto',
            'state' => 'live',
        ],
        'regulator_remote_timeout_ms' => [
            'type' => 'int', 'group' => 'ritmo', 'default' => 800, 'min' => 100, 'max' => 5000,
            'env_key' => 'TRANSCRIPTOR_REGULATOR_REMOTE_TIMEOUT_MS',
            'label' => 'Timeout /api/info (ms)',
            'help' => 'Si la API no responde en este plazo la senal se considera unknown (fail-open, no dispara freno por GPU).',
            'icon' => 'fa-stopwatch',
            'scope' => 'mixto',
            'state' => 'live',
        ],
        'regulator_remote_info_path' => [
            'type' => 'str', 'group' => 'ritmo', 'default' => '/api/metrics/overview',
            'env_key' => 'TRANSCRIPTOR_REGULATOR_REMOTE_INFO_PATH',
            'label' => 'Path de telemetria remota',
            'help' => 'Endpoint que expone workers, ramdisk_pct, vram_used_pct de la API upstream. /api/metrics/overview es el dedicado, plano y sin auth; /api/info es alternativo.',
            'icon' => 'fa-satellite-dish',
            'scope' => 'mixto',
            'state' => 'live',
        ],
        'regulator_remote_saturation_pct' => [
            'type' => 'int', 'group' => 'ritmo', 'default' => 80, 'min' => 10, 'max' => 100,
            'env_key' => 'TRANSCRIPTOR_REGULATOR_REMOTE_SATURATION_PCT',
            'label' => 'Saturacion remota (%)',
            'help' => 'Ocupacion de la GPU remota por encima de la cual el regulador dispara remote_gpu_saturated.',
            'icon' => 'fa-microchip',
            'scope' => 'mixto',
            'state' => 'live',
        ],
        'remote_ramdisk_pressure_pct' => [
            'type' => 'int', 'group' => 'confiabilidad', 'default' => 85, 'min' => 50, 'max' => 99,
            'env_key' => 'TRANSCRIPTOR_REMOTE_RAMDISK_PRESSURE_PCT',
            'label' => 'Presion ramdisk remoto (%)',
            'help' => 'Si /api/metrics/overview reporta ramdisk.pct >= este umbral, el regulador frena con reason=remote_ramdisk_pressure. Ramdisk es donde el ASR escribe los WAV intermedios.',
            'icon' => 'fa-memory',
            'scope' => 'mixto',
            'state' => 'live',
        ],
        'remote_ram_pressure_pct' => [
            'type' => 'int', 'group' => 'confiabilidad', 'default' => 90, 'min' => 50, 'max' => 99,
            'env_key' => 'TRANSCRIPTOR_REMOTE_RAM_PRESSURE_PCT',
            'label' => 'Presion RAM host remoto (%)',
            'help' => 'Si /api/metrics/overview reporta ram.pct >= este umbral, el regulador frena con reason=remote_ram_pressure. RAM alta mata el contenedor antes que el disco.',
            'icon' => 'fa-memory',
            'scope' => 'mixto',
            'state' => 'live',
        ],
        'target_remote_queue' => [
            'type' => 'int', 'group' => 'ritmo', 'default' => 180, 'min' => 10, 'max' => 1000,
            'env_key' => 'TRANSCRIPTOR_TARGET_REMOTE_QUEUE',
            'label' => 'Cola remota objetivo (max)',
            'help' => 'Maximo de jobs que la API upstream puede tener en cola antes de frenar el despacho. Si queue.by_state_corrected["queued/0"] >= este valor, decision=skipped.',
            'icon' => 'fa-satellite-dish',
            'scope' => 'mixto',
            'state' => 'live',
            'detail' => [
                'alcance' => 'TranscriptorSettings::decideDispatch() + TranscriptorApiClient::getStats() consultan queue.by_state_corrected["queued/0"].',
                'cuando_tocar' => 'Si la API upstream suele estar saturada (cola > 200): bajar el target. Si la API esta ociosa y queres throughput: subirlo.',
                'riesgos' => 'Muy bajo puede hacer que el cron envie 0 jobs casi siempre. Muy alto puede acumular backlog en la API.',
            ],
        ],
        'floor_remote_queue' => [
            'type' => 'int', 'group' => 'ritmo', 'default' => 30, 'min' => 0, 'max' => 500,
            'env_key' => 'TRANSCRIPTOR_FLOOR_REMOTE_QUEUE',
            'label' => 'Piso de cola remota (pulso completo)',
            'help' => 'Si la cola remota <= este valor el regulador envia un pulso completo (pulse_batch_size). Entre floor y target el batch baja linealmente.',
            'icon' => 'fa-satellite-dish',
            'scope' => 'mixto',
            'state' => 'live',
            'detail' => [
                'alcance' => 'TranscriptorSettings::decideDispatch() aplica interpolacion lineal entre floor y target_remote_queue.',
                'cuando_tocar' => 'Mantener bajo (~30) para que el regulador envie pulsos cuando la API este hambrienta. Bajar a 0 desactiva el pulso y deja que el regulador dependa solo de la cola PG.',
                'riesgos' => 'Si floor > target, la logica se invierte (el regulador frena cuando la cola esta "baja"). Mantener floor < target siempre.',
            ],
        ],
        'resume_remote_queue' => [
            'type' => 'int', 'group' => 'ritmo', 'default' => 120, 'min' => 0, 'max' => 1000,
            'env_key' => 'TRANSCRIPTOR_RESUME_REMOTE_QUEUE',
            'label' => 'Cola remota para reanudar (histéresis)',
            'help' => 'Histéresis del freno: al alcanzar target_remote_queue se frena, y NO se reanuda hasta que la cola remota baje a este valor. Evita el ciclo frenar-reanudar alrededor del techo (179/180) que enviaba en cuentagotas.',
            'icon' => 'fa-arrows-rotate',
            'scope' => 'mixto',
            'state' => 'live',
            'detail' => [
                'alcance' => 'RemoteQueueBrake::evaluate(), usado por el planner (tick) y por el sender (TranscriptionSubmitService::send).',
                'cuando_tocar' => 'Con target=180, un resume de 120 deja un colchon de 60 jobs para que el nodo respire. Subirlo a ~150 reanuda antes (mas agresivo); bajarlo a ~60 reanuda mas tarde (mas conservador).',
                'riesgos' => 'Muy cercano al target hace que el freno casi no exista y vuelve el ping-pong. Muy bajo deja al nodo remoto con la cola vacia y desperdicia GPU.',
            ],
        ],
        'remote_queue_recheck_seconds' => [
            'type' => 'int', 'group' => 'ritmo', 'default' => 30, 'min' => 5, 'max' => 300,
            'env_key' => 'TRANSCRIPTOR_REMOTE_QUEUE_RECHECK_SECONDS',
            'label' => 'Revalidar cola remota al frenar (s)',
            'help' => 'Con el freno activo, cada cuantos segundos se vuelve a mirar la cola del nodo para decidir si ya bajo al valor de reanudacion. El freno se aplica de inmediato; esta revalidacion solo gobierna cuando se levanta.',
            'icon' => 'fa-stopwatch',
            'scope' => 'mixto',
            'state' => 'live',
            'detail' => [
                'alcance' => 'RemoteQueueBrake::evaluate() mantiene un pestillo en cache con esta cadencia de re-chequeo.',
                'cuando_tocar' => 'Bajarlo a 10-15 s si queres reaccionar mas rapido a la recuperacion del nodo. Subirlo si el endpoint de metricas es costoso para el nodo.',
                'riesgos' => 'Muy bajo martilla /api/metrics/overview; muy alto deja el envio frenado mas tiempo del necesario tras recuperarse la cola.',
            ],
        ],
        'remote_queue_requeue_seconds' => [
            'type' => 'int', 'group' => 'ritmo', 'default' => 30, 'min' => 5, 'max' => 600,
            'env_key' => 'TRANSCRIPTOR_REMOTE_QUEUE_REQUEUE_SECONDS',
            'label' => 'Reintento tras freno por cola (s)',
            'help' => 'Cuando el sender rebota una fila porque la cola remota esta frenada, cuanto esperar antes de reintentarla. Reemplaza el aplazamiento generico de requeue_after_minutes SOLO para este motivo: asi el sender revalida la cola en ~30 s en vez de esperar 5 min.',
            'icon' => 'fa-clock-rotate-left',
            'scope' => 'mixto',
            'state' => 'live',
            'detail' => [
                'alcance' => 'TranscriptionSubmitService::markRequeueable() con motivo de cola remota.',
                'cuando_tocar' => 'Alineado con remote_queue_recheck_seconds para que el reintento coincida con la revalidacion y no se reintente dos veces por el mismo freno.',
                'riesgos' => 'Muy bajo (5 s) reintenta de mas y hace ruido de queries; muy alto desperdicia el inventario ya staged mientras la cola ya habia bajado.',
            ],
        ],
        'pulse_batch_size' => [
            'type' => 'int', 'group' => 'ritmo', 'default' => 50, 'min' => 1, 'max' => 500,
            'env_key' => 'TRANSCRIPTOR_PULSE_BATCH_SIZE',
            'label' => 'Tamano del pulso cuando cola esta en piso',
            'help' => 'Cantidad de jobs a encolar cuando la cola remota esta en floor (hambrienta). Default 50 -> API receives 50, los workers los drenan a ~10-15s cada uno.',
            'icon' => 'fa-burst',
            'scope' => 'mixto',
            'state' => 'live',
        ],
        'remote_wait_warn_seconds' => [
            'type' => 'int', 'group' => 'confiabilidad', 'default' => 60, 'min' => 5, 'max' => 3600,
            'env_key' => 'TRANSCRIPTOR_REMOTE_WAIT_WARN_SECONDS',
            'label' => 'Edad para considerar stuck (s)',
            'help' => 'Un job en queued mas viejo que esto se cuenta como stuck y reduce el batch del siguiente tick.',
            'icon' => 'fa-hourglass-half',
            'scope' => 'mixto',
            'state' => 'live',
        ],
        'stuck_penalty_pct' => [
            'type' => 'int', 'group' => 'confiabilidad', 'default' => 20, 'min' => 5, 'max' => 80,
            'env_key' => 'TRANSCRIPTOR_STUCK_PENALTY_PCT',
            'label' => 'Reduccion del batch por job stuck (%)',
            'help' => 'Porcentaje de reduccion del batch por cada job stuck detectado. Maximo acumulado 80%.',
            'icon' => 'fa-percent',
            'scope' => 'mixto',
            'state' => 'live',
        ],
        'min_srt_completion_pct' => [
            'type' => 'int', 'group' => 'confiabilidad', 'default' => 90, 'min' => 50, 'max' => 99,
            'env_key' => 'TRANSCRIPTOR_MIN_SRT_COMPLETION_PCT',
            'label' => 'Minimo % completitud del SRT',
            'help' => 'Si el ultimo timestamp del SRT termina antes de este % del audio reportado, se considera truncado y se reencola.',
            'icon' => 'fa-percent',
            'scope' => 'mixto',
            'state' => 'live',
        ],
        'srt_retry_max' => [
            'type' => 'int', 'group' => 'confiabilidad', 'default' => 3, 'min' => 1, 'max' => 10,
            'env_key' => 'TRANSCRIPTOR_SRT_RETRY_MAX',
            'label' => 'Max reintentos por SRT truncado',
            'help' => 'Despues de N reintentos con SRT truncado, el job se promueve a dead. Evita loops infinitos si la API tiene un bug permanente.',
            'icon' => 'fa-rotate',
            'scope' => 'mixto',
            'state' => 'live',
        ],
        'latency_p95_warn_seconds' => [
            'type' => 'int', 'group' => 'ritmo', 'default' => 300, 'min' => 30, 'max' => 3600,
            'env_key' => 'TRANSCRIPTOR_LATENCY_P95_WARN_SECONDS',
            'label' => 'Umbral p95 de alerta (s)',
            'help' => 'El panel de diagnostico pinta en ambar la tarjeta de la etapa cuyo p95 supere este umbral.',
            'icon' => 'fa-stopwatch',
            'scope' => 'local',
            'state' => 'live',
        ],
        'inflight_max' => [
            'type' => 'int', 'group' => 'ritmo', 'default' => 0, 'min' => 0, 'max' => 48,
            'env_key' => 'TRANSCRIPTOR_INFLIGHT_MAX',
            'label' => 'Maximo ffmpeg simultaneos',
            'help' => '0 = desactivado. Limita la concurrencia REAL con independencia del numero de workers: con 11 workers e inflight_max=4, los sobrantes esperan en el semaforo sin tocar systemd.',
            'icon' => 'fa-plane-arrival',
            'scope' => 'local',
            'state' => 'live',
        ],

        // === Descubrimiento ===
        'scan_batch' => [
            'type' => 'int', 'group' => 'descubrimiento', 'default' => 100, 'min' => 1, 'max' => 1000,
            'env_key' => 'TRANSCRIPTOR_SCAN_BATCH',
            'label' => 'Archivos por storage por ciclo',
            'help' => 'Cuantos archivos recientes sin transcripcion toma el scanner en cada storage.',
            'icon' => 'fa-magnifying-glass-plus',
            'scope' => 'local',
            'state' => 'live',
        ],
        'scan_min_age_seconds' => [
            'type' => 'int', 'group' => 'descubrimiento', 'default' => 600, 'min' => 0, 'max' => 3600,
            'env_key' => 'TRANSCRIPTOR_SCAN_MIN_AGE_SECONDS',
            'label' => 'Edad minima del archivo (s)',
            'help' => 'Tiempo sin modificarse para considerar que la grabacion termino de escribirse. Default 600s (10 min) para grabaciones de 15-20 min: cuando han pasado 10 min el archivo esta ~60% escrito y NO se descarta.',
            'icon' => 'fa-hourglass-half',
            'scope' => 'local',
            'state' => 'live',
        ],
        'scan_skip_latest_per_storage' => [
            'type' => 'bool', 'group' => 'descubrimiento', 'default' => true,
            'env_key' => 'TRANSCRIPTOR_SCAN_SKIP_LATEST_PER_STORAGE',
            'label' => 'Excluir el archivo mas reciente por storage',
            'help' => 'Si true, el scanner ignora el archivo con mtime mas alto de cada storage (porque asumimos que esta siendo grabado). Es la validacion principal contra "siempre el mas reciente se esta generando".',
            'icon' => 'fa-filter',
            'scope' => 'local',
            'state' => 'live',
        ],
        'scan_days_back' => [
            'type' => 'int', 'group' => 'descubrimiento', 'default' => 0, 'min' => 0, 'max' => 30,
            'env_key' => 'TRANSCRIPTOR_SCAN_DAYS_BACK',
            'label' => 'Dias hacia atras',
            'help' => 'Dias ademas de hoy que escanea el comando por defecto. 0 = solo hoy.',
            'icon' => 'fa-calendar-week',
            'scope' => 'local',
            'state' => 'live',
        ],
        'scan_max_dispatch_per_cycle' => [
            'type' => 'int', 'group' => 'descubrimiento', 'default' => 200, 'min' => 1, 'max' => 2000,
            'env_key' => 'TRANSCRIPTOR_SCAN_MAX_DISPATCH_PER_CYCLE',
            'label' => 'Tope de encolado por ejecucion',
            'help' => 'Techo absoluto de scan-and-submit. Sustituye al antiguo scan_batch x numero de storages, que encolaba 3100 jobs de golpe.',
            'icon' => 'fa-gauge-simple',
            'scope' => 'local',
            'state' => 'live',
        ],

        // === Confiabilidad ===
        'stale_after_minutes' => [
            'type' => 'int', 'group' => 'confiabilidad', 'default' => 30, 'min' => 5, 'max' => 1440,
            'env_key' => 'TRANSCRIPTOR_STALE_AFTER_MINUTES',
            'label' => 'Antiguedad para considerar atascado (min)',
            'help' => 'Un pending sin job_id mas viejo que esto se reenvia.',
            'icon' => 'fa-clock',
            'scope' => 'local',
            'state' => 'live',
        ],
        'stale_resend_limit' => [
            'type' => 'int', 'group' => 'confiabilidad', 'default' => 50, 'min' => 0, 'max' => 500,
            'env_key' => 'TRANSCRIPTOR_STALE_RESEND_LIMIT',
            'label' => 'Reenvios atascados por ciclo',
            'help' => 'Cada reenvio corre ffmpeg + POST sincronos, cada minuto, en paralelo con los workers. Bajarlo alivia los picos.',
            'icon' => 'fa-rotate-left',
            'scope' => 'local',
            'state' => 'live',
        ],
        'poll_scope' => [
            'type' => 'str', 'group' => 'confiabilidad', 'default' => 'current_day',
            'options' => ['current_day', 'unbounded'],
            'env_key' => 'TRANSCRIPTOR_POLL_SCOPE',
            'label' => 'Alcance del reenvio automatico de atascados',
            'help' => 'current_day: poll-results solo re-envia pendientes del dia actual (mismo criterio que el tick). unbounded: re-envia cualquier pendiente atascado, util solo para rescate manual controlado.',
            'icon' => 'fa-magnifying-glass',
            'scope' => 'mixto',
            'state' => 'live',
        ],
        'poll_limit' => [
            'type' => 'int', 'group' => 'confiabilidad', 'default' => 140, 'min' => 10, 'max' => 1000,
            'env_key' => 'TRANSCRIPTOR_POLL_LIMIT',
            'label' => 'Jobs consultados por ciclo',
            'help' => 'Deberia ir al menos al nivel del objetivo de cola, o el poll no alcanza al dispatch.',
            'icon' => 'fa-list-ol',
            'scope' => 'mixto',
            'state' => 'live',
        ],
        'poll_max_age_hours' => [
            'type' => 'int', 'group' => 'confiabilidad', 'default' => 48, 'min' => 2, 'max' => 720,
            'env_key' => 'TRANSCRIPTOR_POLL_MAX_AGE_HOURS',
            'label' => 'Antiguedad maxima en queued (horas)',
            'help' => 'Pasado este plazo una transcripcion en queued/processing se cierra como dead en vez de sondearse indefinidamente. Es la red que evita backlogs zombis que consumen los slots del poll.',
            'icon' => 'fa-clock',
            'scope' => 'mixto',
            'state' => 'live',
        ],
        'max_retries' => [
            'type' => 'int', 'group' => 'confiabilidad', 'default' => 3, 'min' => 1, 'max' => 10,
            'env_key' => 'TRANSCRIPTOR_MAX_RETRIES',
            'label' => 'Reintentos antes de dead',
            'help' => 'Fallos consecutivos tras los que una transcripcion se promueve a dead.',
            'icon' => 'fa-rotate-left',
            'scope' => 'local',
            'state' => 'live',
        ],
        'corrections_chunk' => [
            'type' => 'int', 'group' => 'confiabilidad', 'default' => 500, 'min' => 50, 'max' => 5000,
            'env_key' => 'TRANSCRIPTOR_CORRECTIONS_CHUNK',
            'label' => 'Chunk de correcciones retroactivas',
            'help' => 'Tamano de bloque al aplicar el diccionario sobre segmentos existentes.',
            'icon' => 'fa-puzzle-piece',
            'scope' => 'local',
            'state' => 'live',
        ],
        'srt_max_segment_chars' => [
            'type' => 'int', 'group' => 'confiabilidad', 'default' => 3000, 'min' => 0, 'max' => 20000,
            'env_key' => 'TRANSCRIPTOR_SRT_MAX_SEGMENT_CHARS',
            'label' => 'Longitud maxima de segmento (chars)',
            'help' => 'Por encima de esto el texto del segmento se corta y deja de ser buscable. 0 = sin limite. Los segmentos largos son habla continua real (emisoras de radio), no basura.',
            'icon' => 'fa-ruler',
            'scope' => 'local',
            'state' => 'live',
        ],

// === API ===
        'submit_timeout' => [
            'type' => 'int', 'group' => 'api', 'default' => 60, 'min' => 10, 'max' => 600,
            'env_key' => 'TRANSCRIPTOR_SUBMIT_TIMEOUT',
            'label' => 'Timeout de envio (s)',
            'help' => 'Debe quedar por debajo del timeout del job (600s).',
            'icon' => 'fa-stopwatch',
            'scope' => 'remoto',
            'state' => 'live',
        ],
        'get_timeout' => [
            'type' => 'int', 'group' => 'api', 'default' => 30, 'min' => 5, 'max' => 300,
            'env_key' => 'TRANSCRIPTOR_GET_TIMEOUT',
            'label' => 'Timeout de consulta (s)',
            'help' => 'Para los GET de estado, SRT y stats.',
            'icon' => 'fa-stopwatch',
            'scope' => 'remoto',
            'state' => 'live',
        ],
        'submit_max_attempts' => [
            'type' => 'int', 'group' => 'api', 'default' => 3, 'min' => 1, 'max' => 5,
            'env_key' => 'TRANSCRIPTOR_SUBMIT_MAX_ATTEMPTS',
            'label' => 'Intentos del POST de envio',
            'help' => 'Solo reintenta ante fallo de conexion o 5xx. Un 4xx es permanente y no se reintenta.',
            'icon' => 'fa-rotate',
            'scope' => 'remoto',
            'state' => 'live',
        ],
        'submit_retry_base_ms' => [
            'type' => 'int', 'group' => 'api', 'default' => 500, 'min' => 100, 'max' => 10000,
            'env_key' => 'TRANSCRIPTOR_SUBMIT_RETRY_BASE_MS',
            'label' => 'Espera base entre intentos (ms)',
            'help' => 'Retardo antes de reintentar un envio fallido.',
            'icon' => 'fa-rotate-left',
            'scope' => 'remoto',
            'state' => 'live',
        ],
        'language' => [
            'type' => 'str', 'group' => 'api', 'default' => 'es',
            'options' => ['es', 'en'],
            'env_key' => 'TRANSCRIPTOR_LANGUAGE',
            'label' => 'Idioma',
            'help' => 'Idioma enviado a la API del transcriptor.',
            'icon' => 'fa-language',
            'scope' => 'local',
            'state' => 'live',
        ],
        'lang_fix' => [
            'type' => 'str', 'group' => 'api', 'default' => 'async',
            'options' => ['off', 'async', 'auto'],
            'env_key' => 'TRANSCRIPTOR_LANG_FIX',
            'label' => 'Correccion de idioma',
            'help' => 'Modo de correccion automatica de idioma en el transcriptor.',
            'icon' => 'fa-language',
            'scope' => 'local',
            'state' => 'live',
        ],
        'audio_output_format' => [
            'type' => 'str', 'group' => 'api', 'default' => 'wav',
            'options' => ['wav', 'opus'],
            'env_key' => 'TRANSCRIPTOR_AUDIO_OUTPUT_FORMAT',
            'label' => 'Formato de envio',
            'help' => 'Formato al que ffmpeg convierte antes de subir. wav = pcm_s16le 16kHz mono, sin perdida (~115 MB/hora). opus = libopus 64k (~29 MB/hora, con perdida). La API acepta ambos.',
            'icon' => 'fa-file-audio',
            'scope' => 'local',
            'state' => 'live',
            'detail' => [
                'alcance' => 'TranscriptionSubmitService::convertAudio() invoca ffmpeg -c:a pcm_s16le o libopus segun el valor.',
                'cuando_tocar' => 'Dejar en wav salvo necesidad explicita de reducir ancho de banda. El constraint del proyecto (AGENTS.md) dice "WAV sin excepcion" - opus existe en el schema solo por compat historica.',
                'riesgos' => 'Si lo cambias a opus, el upstream recibira audio con perdida, lo que puede degradar la calidad del SRT. Ademas el LLM de coherencia trabaja mejor con wav.',
            ],
        ],
        'min_shm_free_bytes' => [
            'type' => 'int', 'group' => 'api', 'default' => 200_000_000, 'min' => 10_000_000, 'max' => 4_000_000_000,
            'env_key' => 'TRANSCRIPTOR_MIN_SHM_FREE_BYTES',
            'label' => 'Minimo libre en /dev/shm (bytes)',
            'help' => 'Si /dev/shm tiene menos de esto, el submit se aborta antes de invocar ffmpeg y el job se reencola para el siguiente ciclo. Default 200 MB cubre ~5 WAVs en vuelo + reintentos.',
            'icon' => 'fa-memory',
            'scope' => 'local',
            'state' => 'live',
        ],
        'requeue_after_minutes' => [
            'type' => 'int', 'group' => 'api', 'default' => 5, 'min' => 1, 'max' => 60,
            'env_key' => 'TRANSCRIPTOR_REQUEUE_AFTER_MINUTES',
            'label' => 'Minutos hasta reintento tras rebote',
            'help' => 'Cuando un job rebota por pre-flight (tmpfs sin espacio), el tick lo ignora durante este tiempo. Pasado el plazo se reencola normalmente.',
            'icon' => 'fa-clock-rotate-left',
            'scope' => 'local',
            'state' => 'live',
        ],
        // === Staging local (transcriptor-two-phase-staging) ===
        //
        // El pipeline se parte en dos fases desacopladas: convertir (ffmpeg,
        // caro en CPU) y enviar (POST, limitado por el headroom remoto). El
        // stager convierte en goteo y deja los WAV listos en tmpfs; el sender
        // los manda solo cuando la cola remota tiene espacio. Asi el host
        // local no sufre picos de CPU ni la API recibe rafagas.
        'supervisor_numprocs' => [
            'type' => 'int', 'group' => 'workers', 'default' => 3, 'min' => 1, 'max' => 64,
            'env_key' => 'TRANSCRIPTOR_SUPERVISOR_NUMPROCS',
            'label' => 'Workers PG declarados',
            'help' => 'Cantidad de procesos transcription:worker que supervisord debe mantener (numprocs del unit). Se usa como referencia para comparar contra los procesos vivos en el panel.',
            'icon' => 'fa-server',
            'scope' => 'local',
            'state' => 'live',
            'detail' => [
                'alcance' => 'Referencia del panel de workers. NO lanza ni mata procesos: eso lo gobierna supervisord (tcloud-transcription-worker.conf).',
                'cuando_tocar' => 'Subirlo al mismo valor que numprocs en el .conf para que el panel no reporte un pool incompleto.',
                'riesgos' => 'Si no coincide con el .conf, el panel muestra una expectativa falsa (ej. "2/3" cuando el .conf tambien dice 2).',
            ],
        ],
        'staging_enabled' => [
            'type' => 'bool', 'group' => 'staging', 'default' => true,
            'env_key' => 'TRANSCRIPTOR_STAGING_ENABLED',
            'label' => 'Staging local de audio',
            'help' => 'Convierte con ffmpeg y deja el audio listo en /dev/shm antes de enviarlo. Permite drenar la cola remota sin picos de CPU. Si se apaga, el envio vuelve a ser sincrono (ffmpeg + POST en el mismo paso).',
            'icon' => 'fa-layer-group',
            'scope' => 'local',
            'state' => 'live',
            'detail' => [
                'alcance' => 'Comando transcription:stage (fase 1) y TranscriptionSubmitService::send() (fase 2).',
                'cuando_tocar' => 'Apagar solo para diagnostico: aisla si un problema esta en ffmpeg o en el POST.',
                'riesgos' => 'Con staging apagado vuelven los picos de CPU cuando hay backlog y la cola remota se llena, porque cada worker convierte y bloquea.',
            ],
        ],
        'staging_budget_bytes' => [
            'type' => 'int', 'group' => 'staging', 'default' => 8000000000, 'min' => 100000000, 'max' => 40000000000,
            'env_key' => 'TRANSCRIPTOR_STAGING_BUDGET_BYTES',
            'label' => 'Presupuesto de /dev/shm (bytes)',
            'help' => 'Techo de bytes que el staging puede ocupar en tmpfs. El stager no empieza una conversion si sum(staged_bytes) + estimado supera este valor, de modo que el envio nunca falla por falta de espacio.',
            'icon' => 'fa-hard-drive',
            'scope' => 'local',
            'state' => 'live',
            'detail' => [
                'alcance' => 'TranscriptionStageCommand (presupuesto) y TranscriptionSubmitService (pre-flight).',
                'cuando_tocar' => 'Subirlo si /dev/shm es grande y queres mas archivos listos. Bajarlo si el host comparte tmpfs con otros procesos.',
                'riesgos' => 'Muy alto acerca el tmpfs al 100% y hace fallar ffmpeg con ENOSPC. Muy bajo deja el sender sin inventario y el pipeline se vuelve sincrono de facto.',
            ],
        ],
        'staging_ttl_minutes' => [
            'type' => 'int', 'group' => 'staging', 'default' => 120, 'min' => 10, 'max' => 1440,
            'env_key' => 'TRANSCRIPTOR_STAGING_TTL_MINUTES',
            'label' => 'TTL del audio staged (min)',
            'help' => 'Un archivo convertido que lleva mas de este tiempo sin enviarse se descarta y la fila vuelve a la cola de conversion. Evita que un paro prolongado de la API llene el tmpfs con WAV obsoletos.',
            'icon' => 'fa-broom',
            'scope' => 'local',
            'state' => 'live',
        ],
        'staging_pace_seconds' => [
            'type' => 'int', 'group' => 'staging', 'default' => 0, 'min' => 0, 'max' => 60,
            'env_key' => 'TRANSCRIPTOR_STAGING_PACE_SECONDS',
            'label' => 'Pausa entre lotes (s)',
            'help' => 'Segundos de espera al final de cada lote paralelo de conversion. Default 0: el stager encadena lotes sin pausa mientras haya espacio en RAM disk y pendientes. Subirlo solo si el host comparte CPU con procesos sensibles.',
            'icon' => 'fa-gauge-simple',
            'scope' => 'local',
            'state' => 'live',
        ],
        'staging_parallel' => [
            'type' => 'int', 'group' => 'staging', 'default' => 4, 'min' => 1, 'max' => 8,
            'env_key' => 'TRANSCRIPTOR_STAGING_PARALLEL',
            'label' => 'Conversiones ffmpeg en paralelo',
            'help' => 'Cuantos ffmpeg corren a la vez dentro de un lote. El stager convierte en paralelo, espera a que el lote completo termine y arranca el siguiente de inmediato (salvo pausa configurada). Regla de bolso: 1 proceso por core dedicado; 4 es un buen punto para un host de 16 cores compartido.',
            'icon' => 'fa-layer-group',
            'scope' => 'local',
            'state' => 'live',
            'detail' => [
                'alcance' => 'TranscriptionStageCommand: pcntl_fork por lote, con watchdog por hijo para no colgar el cron.',
                'cuando_tocar' => 'Subir si el host tiene cores libres y quieres llenar mas rapido el RAM disk. Bajar si ffmpeg compite con nginx/PHP-FPM.',
                'riesgos' => 'Mas de 8 procesos ffmpeg en paralelo suele degradar al host: el scheduler los rota y todos van mas lento.',
            ],
        ],
        'staging_target_inventory' => [
            'type' => 'int', 'group' => 'staging', 'default' => 60, 'min' => 5, 'max' => 1000,
            'env_key' => 'TRANSCRIPTOR_STAGING_TARGET_INVENTORY',
            'label' => 'Inventario objetivo (archivos listos)',
            'help' => 'Cuantos audios ya convertidos mantener listos en tmpfs. El stager deja de convertir al alcanzarlo y reanuda cuando el sender los consume. Es el amortiguador entre la CPU local y la cola remota.',
            'icon' => 'fa-boxes-stacked',
            'scope' => 'local',
            'state' => 'live',
        ],
        'shm_warn_percent' => [
            'type' => 'int', 'group' => 'api', 'default' => 80, 'min' => 50, 'max' => 99,
            'env_key' => 'TRANSCRIPTOR_SHM_WARN_PERCENT',
            'label' => 'Umbral de WARNING en /dev/shm (%)',
            'help' => 'Por encima de este porcentaje, transcription:check-shm-health emite un Log::warning. Default 80 detecta la fuga antes de que /dev/shm llegue al 100%.',
            'icon' => 'fa-triangle-exclamation',
            'scope' => 'local',
            'state' => 'live',
        ],

        // === Workers ===
        'worker_min' => [
            'type' => 'int', 'group' => 'workers', 'default' => 3, 'min' => 0, 'max' => 12,
            'env_key' => 'TRANSCRIPTOR_WORKER_MIN',
            'label' => 'Workers minimo',
            'help' => 'Piso del pool calculado automaticamente.',
            'icon' => 'fa-microchip',
            'scope' => 'local',
            'state' => 'live',
        ],
        'worker_max' => [
            'type' => 'int', 'group' => 'workers', 'default' => 12, 'min' => 1, 'max' => 12,
            'env_key' => 'TRANSCRIPTOR_WORKER_MAX',
            'label' => 'Workers maximo',
            'help' => 'Techo del pool. Se acota ademas al numero de units systemd realmente instaladas.',
            'icon' => 'fa-microchip',
            'scope' => 'local',
            'state' => 'live',
        ],
        'worker_ratio' => [
            'type' => 'int', 'group' => 'workers', 'default' => 6, 'min' => 1, 'max' => 50,
            'env_key' => 'TRANSCRIPTOR_WORKER_RATIO',
            'label' => 'Medios por worker',
            'help' => 'workers = medios_totales / este valor, acotado entre minimo y maximo.',
            'icon' => 'fa-divide',
            'scope' => 'local',
            'state' => 'live',
        ],
        'worker_override' => [
            'type' => 'int', 'group' => 'workers', 'default' => 0, 'min' => 0, 'max' => 12,
            'env_key' => 'TRANSCRIPTOR_WORKER_OVERRIDE',
            'label' => 'Workers forzados',
            'help' => '0 = automatico. Con un valor distinto de 0 el tuner lo usa tal cual, ignorando la formula.',
            'icon' => 'fa-sliders',
            'scope' => 'local',
            'state' => 'live',
        ],

        // === UI ===
        'ui_max_parallel_sends' => [
            'type' => 'int', 'group' => 'ui', 'default' => 3, 'min' => 1, 'max' => 12,
            'env_key' => 'TRANSCRIPTOR_UI_MAX_PARALLEL_SENDS',
            'label' => 'Envios simultaneos desde el navegador',
            'help' => 'Cada envio corre ffmpeg + POST sincronos dentro de php-fpm. Sin tope, seleccionar 200 archivos levantaba 200 procesos.',
            'icon' => 'fa-bars-staggered',
            'scope' => 'local',
            'state' => 'live',
        ],
        'ui_batch_max' => [
            'type' => 'int', 'group' => 'ui', 'default' => 200, 'min' => 10, 'max' => 1000,
            'env_key' => 'TRANSCRIPTOR_UI_BATCH_MAX',
            'label' => 'Tope del slider de lote',
            'help' => 'Alinea el maximo del slider con el clamp del servidor para que no trunque en silencio.',
            'icon' => 'fa-bars',
            'scope' => 'local',
            'state' => 'live',
        ],
        'min_file_size_bytes' => [
            'type' => 'int', 'group' => 'confiabilidad', 'default' => 512000, 'min' => 0, 'max' => 104857600,
            'env_key' => 'TRANSCRIPTOR_MIN_FILE_SIZE_BYTES',
            'label' => 'Tamaño mínimo del audio (bytes)',
            'help' => 'Archivos por debajo de este tamaño se ignoran en el escaneo (no se crea fila) y, si ya tienen fila, el stage los descarta sin pasar por ffmpeg. Default 500 KB: una grabación vacía o truncada pesa 0 bytes, y transcribirla solo quema CPU y reintentos. Bajarlo si hay audios legítimos muy cortos; 0 = desactivar.',
            'icon' => 'fa-file',
            'scope' => 'local',
            'state' => 'live',
            'detail' => [
                'alcance' => 'DiskScannerService::scanStorage() (no crea la fila) + TranscriptionSubmitService::stage() (descarta la ya creada) + TranscriptionBackfillLostCommand.',
                'cuando_tocar' => 'Subirlo si hay muchos audios vacíos/truncados del grabador. Bajarlo si el módulo transcribe cortes publicitarios o notas breves que legítimamente pesan menos de 500 KB.',
                'riesgos' => 'Un audio real y corto por debajo del umbral no se transcribirá. Referencia medida: un programa de 21 min en MP3 pesa ~20 MB y en M4A ~12 MB; 500 KB equivale a ~30 s de audio.',
            ],
        ],

        // === Coherencia IA (pase de correccion de spanglish residual) ===
        'ai_coherence_enabled' => [
            'type' => 'bool', 'group' => 'ia', 'default' => false,
            'env_key' => 'TRANSCRIPTOR_AI_COHERENCE_ENABLED',
            'label' => 'Pase de coherencia IA',
            'help' => 'Corrige con LLM los segmentos con ingles residual que el diccionario no cubre. MANUAL-ONLY: apagado por defecto porque consume tokens en cada transcripcion. Para usarlo, activalo aqui y dispara `transcription:backfill-coherence` sobre el rango de fechas que quieras.',
            'icon' => 'fa-wand-magic-sparkles',
            'scope' => 'local',
            'state' => 'live',
            'detail' => [
                'alcance' => 'TranscriptionCoherencePass corre DENTRO de la ingesta de la transcripcion (TranscriptionProcessor::processDone), es decir, en el poller. Con el toggle apagado el pase retorna sin llamar al LLM.',
                'cuando_tocar' => 'Encendelo solo para una campana puntual de correccion (ej. backfill de un rango) y apagalo al terminar. Encendido de forma permanente gasta tokens en TODAS las transcripciones y ademas frena el poller (cada llamada puede tardar hasta timeout_seconds).',
                'riesgos' => 'Si el LLM esta caido, el pase falla pero la transcripcion queda valida (sin correccion). Las propuestas de aprendizaje se guardan aparte en el modulo de correcciones.',
            ],
        ],
        'ai_coherence_max_segments' => [
            'type' => 'int', 'group' => 'ia', 'default' => 20, 'min' => 1, 'max' => 200,
            'env_key' => 'TRANSCRIPTOR_AI_COHERENCE_MAX_SEGMENTS',
            'label' => 'Tope de segmentos por transcripcion',
            'help' => 'Maximo de segmentos a corregir con IA por transcripcion. Controla costo/latencia en transcripciones con mucho ingles (ej. musica).',
            'icon' => 'fa-list-ol',
            'scope' => 'local',
            'state' => 'live',
        ],
        'ai_coherence_max_learn' => [
            'type' => 'int', 'group' => 'ia', 'default' => 5, 'min' => 0, 'max' => 50,
            'env_key' => 'TRANSCRIPTOR_AI_COHERENCE_MAX_LEARN',
            'label' => 'Pares aprendidos por transcripcion',
            'help' => 'Maximo de pares wrong->correct a proponer como pending por transcripcion tras el pase IA. 0 = desactivar aprendizaje.',
            'icon' => 'fa-graduation-cap',
            'scope' => 'local',
            'state' => 'live',
        ],
        'ai_coherence_batch_size' => [
            'type' => 'int', 'group' => 'ia', 'default' => 5, 'min' => 1, 'max' => 20,
            'env_key' => 'TRANSCRIPTOR_AI_COHERENCE_BATCH_SIZE',
            'label' => 'Segmentos por llamada LLM',
            'help' => 'Cuantos segmentos se corrigen por llamada al LLM. Sub-batches pequenos (5) evitan timeout (cURL 28) con max_tokens alto.',
            'icon' => 'fa-layer-group',
            'scope' => 'local',
            'state' => 'live',
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
        // min_file_size_bytes = 100MB, recortado por el max del schema:
        // durante dias mando cientos de ficheros/dia a dead sin explicacion.
        // El max subio a 100MB (2026-09-16) para que ese caso ya no se recorte.
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
        $deficit = $this->int('target_pg_queue') - $current + $this->int('runway');

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
                'icon' => $spec['icon'] ?? 'fa-cog',
                'scope' => $spec['scope'] ?? 'local',
                'state' => $spec['state'] ?? 'live',
                'detail' => $spec['detail'] ?? null,
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
     * Una lectura del store de caché por proceso cada 30s y una de BD cada 60s.
     * El store de caché es configurable vía `config/cache.php`, compartido
     * entre php-fpm y los workers CLI, asi que el flush() desde la UI propaga
     * a todos los procesos.
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

        if ((int) $val('runway') > (int) $val('target_pg_queue')) {
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
