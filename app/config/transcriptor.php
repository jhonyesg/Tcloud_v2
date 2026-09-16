<?php

return [

    'base_url' => env('TRANSCRIPTOR_BASE_URL', 'http://192.168.0.138:9000'),

    'api_key' => env('TRANSCRIPTOR_API_KEY', ''),

    // 'callback_host' eliminado el 2026-08-12. Solo se pintaba en el panel de
    // ayuda, y ahi hacia dano: daba cuerpo a un mecanismo de callback que no
    // existe (no hay ruta /webhooks/transcription, y submit() con callback_url
    // era codigo muerto). Los resultados se recogen SOLO por polling; ver
    // TranscriptionPollingService.
    //
    // TRANSCRIPTOR_CALLBACK_HOST queda huerfano en .env: se puede borrar de ahi
    // cuando se toque el fichero, ya no lo lee nadie.

    // Idioma por defecto enviado a la API del transcriptor (es, en, ...)
    'language' => env('TRANSCRIPTOR_LANGUAGE', 'es'),

    // Modo de correccion de idioma: off | async | auto
    'lang_fix' => env('TRANSCRIPTOR_LANG_FIX', 'async'),

    // Formato al que ffmpeg convierte antes de subir a POST /v1/transcribe: wav | opus.
    //
    // wav  = pcm_s16le 16kHz mono, sin perdida (~1,9 MB/min).
    // opus = libopus 64k 16kHz mono (~0,5 MB/min, con perdida).
    //
    // La API del transcriptor acepta ambos; wav es el que consume directamente
    // el modelo ASR, asi que evita una etapa de decodificacion con perdida.
    'audio_output_format' => env('TRANSCRIPTOR_AUDIO_OUTPUT_FORMAT', 'wav'),

    // Timeout en segundos para el POST de envio del archivo
    'submit_timeout' => (int) env('TRANSCRIPTOR_SUBMIT_TIMEOUT', 60),

    // Timeout en segundos para los GET de estado/SRT/stats
    'get_timeout' => (int) env('TRANSCRIPTOR_GET_TIMEOUT', 30),

    // Chunk size para el comando retroactivo de correcciones
    'corrections_chunk' => (int) env('TRANSCRIPTOR_CORRECTIONS_CHUNK', 500),

    // Longitud maxima de un segmento de SRT antes de truncarlo.
    //
    // Estuvo fijado en 500 "para no inflar la BD con basura", pero la premisa era
    // falsa: la columna es `text` (ilimitada en Postgres) y los segmentos que lo
    // superaban resultaron ser habla real de ~30s sin pausas — tipico de emisoras
    // de radio. Se cortaban a mitad de palabra y ese texto no aparecia en las
    // busquedas. 9.023 segmentos afectados, ~700-1.100 nuevos al dia, a cambio de
    // ahorrar 4 MB sobre una tabla de 3,2 GB.
    //
    // 0 = sin limite. El aviso en log se mantiene como diagnostico.
    'srt_max_segment_chars' => (int) env('TRANSCRIPTOR_SRT_MAX_SEGMENT_CHARS', 3000),

    // Cuantos archivos recientes sin transcripcion toma el scanner por storage por ciclo.
    // En el modal de la UI esto es configurable; el valor por defecto es amplio para
    // cubrir todos los cortes del día actual casi de inmediato.
    'scan_batch' => (int) env('TRANSCRIPTOR_SCAN_BATCH', 100),

    // Tiempo (segundos) que un archivo debe llevar sin modificarse para considerarse completo
    'scan_min_age_seconds' => (int) env('TRANSCRIPTOR_SCAN_MIN_AGE_SECONDS', 60),

    // Dias hacia atras que escanea el comando scan-and-submit por defecto (0 = solo hoy)
    'scan_days_back' => (int) env('TRANSCRIPTOR_SCAN_DAYS_BACK', 0),

    // Techo absoluto de jobs que scan-and-submit encola por ejecucion.
    // Sustituye al antiguo scan_batch * count($storages), que con 31 storages
    // encolaba 3100 jobs de golpe saltandose el regulador.
    'scan_max_dispatch_per_cycle' => (int) env('TRANSCRIPTOR_SCAN_MAX_DISPATCH_PER_CYCLE', 200),

    // Excluir el audio más reciente por storage del escaneo. Cuando el
    // tick descubre archivos por mtime, el de mayor mtime suele estar
    // aún siendo grabado por el radio. Si true, lo saltea y deja que el
    // próximo tick (dentro de scan_min_age_seconds) lo levante ya completo.
    'scan_skip_latest_per_storage' => (bool) env('TRANSCRIPTOR_SCAN_SKIP_LATEST_PER_STORAGE', true),

    // Tiempo (minutos) que un pending sin job_id se considera atascado y se reenvia
    'stale_after_minutes' => (int) env('TRANSCRIPTOR_STALE_AFTER_MINUTES', 30),

// === Regulador del batch dispatcher (transcription:tick) ===
    // Target de jobs pendientes (state=pending, recorded_at>=today) arriba del
    // cual el regulador frena. El contador vive en la tabla `transcriptions`
    // (cola nativa PG): el cutover transcriptor-pg-native-queue (2026-09-15)
    // elimino la cola previa basada en Laravel Bus.
    // El transcriptor tiene 2 workers GPU; cola 140 lo mantiene alimentado sin hipersaturar.
    'target_pg_queue' => (int) env('TRANSCRIPTOR_TARGET_PG_QUEUE', 140),

    // Minimo y maximo por ciclo (regulador clamp)
    'min_batch' => (int) env('TRANSCRIPTOR_MIN_BATCH', 10),
    'max_batch' => (int) env('TRANSCRIPTOR_MAX_BATCH', 200),

    // Minimo de "runway" sobre el target — siempre enviamos al menos este sobrante
    // para que el transcriptor nunca quede idle aunque la cola este exactamente en target.
    'runway' => (int) env('TRANSCRIPTOR_RUNWAY', 5),

    // Scope del dispatcher automatico: solo procesa Transcription con created_at >= hoy.
    // Valores: current_day (default) | unbounded (manual recovery via UI).
    'scope' => env('TRANSCRIPTOR_SCOPE', 'current_day'),

    // === Ritmo del dispatcher: lo que convierte la rafaga en goteo ===
    // Freno de emergencia. Con esto activo el tick sigue DESCUBRIENDO (no se
    // pierde nada) pero deja de encolar, y los endpoints de envio devuelven 423.
    'dispatch_paused' => (bool) env('TRANSCRIPTOR_DISPATCH_PAUSED', false),

    // Cada cuantos minutos encola el tick. El scheduler corre cada minuto y el
    // comando se autolimita contra un timestamp en cache, asi que esto es
    // ajustable en caliente sin tocar routes/console.php.
    'tick_interval_minutes' => (int) env('TRANSCRIPTOR_TICK_INTERVAL_MINUTES', 3),

    // Maximo de ffmpeg+POST SIMULTANEOS, independiente del numero de workers.
    // 0 = desactivado (default post-migracion). La concurrencia ahora se logra
    // con N units supervisord (transcription:worker), no con funnel en la BD.
    // El objetivo de cola regula el ritmo de encolado; esto regula la
    // concurrencia intra-proceso contra los 2 workers GPU.
    'inflight_max' => (int) env('TRANSCRIPTOR_INFLIGHT_MAX', 0),

    // === Confiabilidad ===
    // Cuantos pending atascados reenvia poll-results por ciclo (era 50 hardcodeado).
    'stale_resend_limit' => (int) env('TRANSCRIPTOR_STALE_RESEND_LIMIT', 50),

    // Alcance del reenvio automatico de atascados en poll-results.
    // current_day (default): solo re-envia pendientes con created_at >= hoy,
    //   mismo criterio que TranscriptionTickCommand. Los archivos antiguos
    //   solo llegan por envio manual (rango explicito, historico completo
    //   o bulk dispatch desde la UI).
    // unbounded: re-envia cualquier pending atascado. Reservado para rescate
    //   manual controlado; NUNCA debe quedar activo en el cron automatico.
    'poll_scope' => env('TRANSCRIPTOR_POLL_SCOPE', 'current_day'),

    // Cuantos jobs queued/processing consulta el polling por ciclo. Estaba
    // hardcodeado en 100, por debajo del target de cola 140: el poll no alcanzaba
    // al dispatch.
    'poll_limit' => (int) env('TRANSCRIPTOR_POLL_LIMIT', 140),

    // Antiguedad tras la cual una fila en queued/processing se cierra como dead
    // en vez de seguir sondeandose. Sin este corte, un job cuyo resultado se
    // perdio upstream se reconsulta cada minuto para siempre y ocupa un slot de
    // poll_limit: el 2026-08-12 habia 33.571 filas asi (1-5 de agosto), que
    // gastaban ~139 de los 140 slots de cada ciclo.
    'poll_max_age_hours' => (int) env('TRANSCRIPTOR_POLL_MAX_AGE_HOURS', 48),

    // Reintentos del POST de envio ante fallo de conexion o 5xx (nunca 4xx).
    // Sin esto un 502 transitorio mandaba la transcripcion directa a markError().
    'submit_max_attempts' => (int) env('TRANSCRIPTOR_SUBMIT_MAX_ATTEMPTS', 3),
    'submit_retry_base_ms' => (int) env('TRANSCRIPTOR_SUBMIT_RETRY_BASE_MS', 500),

    // === Pool de workers (TranscriptionTuneCommand) ===
    // Eran private const. workers = clamp(medios/worker_ratio, worker_min, worker_max).
    'worker_min' => (int) env('TRANSCRIPTOR_WORKER_MIN', 3),
    'worker_max' => (int) env('TRANSCRIPTOR_WORKER_MAX', 12),
    'worker_ratio' => (int) env('TRANSCRIPTOR_WORKER_RATIO', 6),

    // Override manual del pool. 0 = automatico (formula del ratio).
    // En saturacion lo que se necesita es "ponlo en 4 ahora", no deducir el ratio.
    'worker_override' => (int) env('TRANSCRIPTOR_WORKER_OVERRIDE', 0),

    // === Topes de la UI ===
    // Maximo de POST simultaneos que el navegador lanza en "enviar seleccionados".
    // Cada uno ejecuta ffmpeg + POST sincronos dentro de php-fpm.
    'ui_max_parallel_sends' => (int) env('TRANSCRIPTOR_UI_MAX_PARALLEL_SENDS', 3),

    // Tope del slider de lote en la UI. Debe coincidir con el clamp del servidor
    // en ApiTranscriptorController::processBatch para no truncar en silencio.
    'ui_batch_max' => (int) env('TRANSCRIPTOR_UI_BATCH_MAX', 200),

    // Cuando true, el submit calcula SHA256 del wav enviado y lo manda al
    // upstream como `idempotency_key` para que la API externa deduplique
    // reenvios dentro de su ventana. Default true (alineado con el schema).
    'submit_with_idempotency_key' => (bool) env('TRANSCRIPTOR_SUBMIT_WITH_IDEMPOTENCY_KEY', true),

    // Maximo de espera (s) entre reintentos si el upstream responde 503 sin
    // header Retry-After. Si excede, el sistema aborta el envio y el job se
    // reencola para el siguiente tick.
    'max_backoff_seconds' => (int) env('TRANSCRIPTOR_MAX_BACKOFF_SECONDS', 300),

    // Cantidad de respuestas 4xx/5xx en una ventana de 5 min para que el
    // circuit breaker se abra y el tick frene con reason=upstream_circuit_open.
    'circuit_breaker_threshold' => (int) env('TRANSCRIPTOR_CIRCUIT_BREAKER_THRESHOLD', 3),

    // Tiempo que el break queda abierto antes de pasar a half-open. Durante
    // este periodo el tick no encola.
    'circuit_breaker_open_seconds' => (int) env('TRANSCRIPTOR_CIRCUIT_BREAKER_OPEN_SECONDS', 60),

    // === Burst dispatcher (TranscriptorBurstDispatchCommand) ===
    // Si la API remota tiene queue.queued + processing >= este valor, el
    // burst-dispatcher pausa. Validado contra /api/metrics/overview.
    'burst_max_upstream_queue' => (int) env('TRANSCRIPTOR_BURST_MAX_UPSTREAM_QUEUE', 180),

    // Maximo de audios que toma el burst-dispatcher por rafaga cuando hay
    // headroom. Se calcula como min(headroom, batch_size).
    'burst_batch_size' => (int) env('TRANSCRIPTOR_BURST_BATCH_SIZE', 40),

    // Cantidad de procesos paralelos (proc_open) que procesan audios del lote.
    'burst_parallel_ffmpeg' => (int) env('TRANSCRIPTOR_BURST_PARALLEL_FFMPEG', 8),

    // Cuando no hay headroom o candidatos, el daemon espera este tiempo
    // antes de re-verificar /api/metrics/overview.
    'burst_poll_interval_seconds' => (int) env('TRANSCRIPTOR_BURST_POLL_INTERVAL_SECONDS', 10),

    // === Webhook (Fase D, experimental) ===
    // Off por defecto. Cuando se activa, el upstream notifica via webhook
    // en vez de esperar al polling. Requiere TCLOUD_CALLBACK_URL configurado.
    'submit_with_callback' => (bool) env('TRANSCRIPTOR_SUBMIT_WITH_CALLBACK', false),

    // Secreto compartido con el upstream para firmar los webhooks entrantes.
    // Generar con: openssl rand -hex 32
    'webhook_secret' => env('TCLOUD_WEBHOOK_SECRET', ''),

    // Max reintentos automaticos antes de promover una Transcription a 'dead'.
    // Se incrementa en TranscriptionSubmitService::markError() y se valida contra este
    // limite en DiskScannerService::collectFailedCandidates() con el flag --include-failed.
    'max_retries' => (int) env('TRANSCRIPTOR_MAX_RETRIES', 3),

    // Tamano minimo (bytes) del archivo de audio para enviar a transcripcion.
    // Por debajo de esto el archivo casi seguro esta corrupto o truncado
    // (tipico de radios en vivo: el grabador crea el MP3 al iniciar y el tick
    // lo dispatcha mientras aun se esta escribiendo). ffmpeg falla con
    // "Failed to read frame size" y tras 3 retries termina en dead, gastando
    // un slot de cola. Filtrar aqui evita el ciclo completo.
    'min_file_size_bytes' => (int) env('TRANSCRIPTOR_MIN_FILE_SIZE_BYTES', 1024),

    // === Claves que vivian solo en el esquema de TranscriptorSettings ===
    //
    // Todas funcionaban (el accessor cae al default del esquema), pero la
    // pantalla de configuracion informaba "Origen: archivo" sobre una clave que
    // el archivo no tenia, y el test que vigila esa correspondencia llevaba
    // tiempo en rojo. Se declaran aqui con su mismo default.

    // Minutos que espera un job rebotado por pre-flight antes de reintentarse.
    'requeue_after_minutes' => (int) env('TRANSCRIPTOR_REQUEUE_AFTER_MINUTES', 5),

    // Porcentaje de uso de /dev/shm a partir del cual el centinela avisa.
    'shm_warn_percent' => (int) env('TRANSCRIPTOR_SHM_WARN_PERCENT', 80),

    // === Pase de coherencia IA sobre segmentos con ingles residual ===
    // El umbral de seleccion y el modelo NO son configurables: viven en
    // TranscriptionCoherencePass y en llm-correction.model respectivamente.
    //
    // MANUAL-ONLY (default false): el pase consume tokens del LLM en CADA
    // transcripcion que se ingesta, asi que NO se ejecuta solo. Para correrlo
    // hay que activar el toggle a mano (AI Settings o este env) y luego
    // dispararlo con `transcription:backfill-coherence`.
    //
    // Por que: change archivado `2026-08-25-llm-coherence-manual-only-defaults-off`
    // ordenaba este default; quedo a medias y el pase siguio corriendo solo en
    // cada ingesta del poller (~9.5 s por job, y con el gateway LLM caido el
    // tiempo se pagaba igual sin obtener correccion).
    'ai_coherence_enabled' => (bool) env('TRANSCRIPTOR_AI_COHERENCE_ENABLED', false),
    'ai_coherence_max_segments' => (int) env('TRANSCRIPTOR_AI_COHERENCE_MAX_SEGMENTS', 20),
    'ai_coherence_max_learn' => (int) env('TRANSCRIPTOR_AI_COHERENCE_MAX_LEARN', 5),
    'ai_coherence_batch_size' => (int) env('TRANSCRIPTOR_AI_COHERENCE_BATCH_SIZE', 5),

    // Espacio libre minimo en /dev/shm (bytes) para aceptar una conversion. Por
    // debajo, TranscriptionSubmitService rebota el job con requeue en vez de
    // llenar el tmpfs.
    //
    // La clave existia en el esquema de TranscriptorSettings pero no aqui: el
    // valor efectivo salia del default del esquema y la columna "Origen" de la
    // pantalla de configuracion mentia diciendo "archivo".
    'min_shm_free_bytes' => (int) env('TRANSCRIPTOR_MIN_SHM_FREE_BYTES', 200000000),

    // Destinatario del centinela de flujo (transcription:health-check). Vacio =
    // solo se registra el WARNING en laravel.log, sin correo.
    //
    // El centinela existe porque el 2026-08-18 el pipeline se paro por completo
    // durante 44 horas sin que ninguna pieza avisara: cada una reportaba su
    // propio estado como normal y nadie hacia la pregunta de arriba (¿esta
    // entrando trabajo?).
    'health_alert_email' => env('TRANSCRIPTOR_HEALTH_ALERT_EMAIL', ''),

    // === Regulador configurable (optimize-transcriptor-dispatch-throughput) ===
    //
    // `regulator_mode` redefine la senal que usa el tick para frenar.
    //   - local_only : solo conteo PG (pending state) + shm_free (comportamiento
    //                  post-migracion + guarda de tmpfs).
    //   - remote_aware: ademas consulta /api/stats con cache y TTL corto;
    //                  prioriza la saturacion real de la GPU remota sobre
    //                  la cola local.
    //   - hybrid    : cualquiera de pg_queue / remote_gpu / shm / inflight
    //                  dispara freno; la razon es la primera senal saturada
    //                  en orden de prioridad.
    'regulator_mode' => env('TRANSCRIPTOR_REGULATOR_MODE', 'local_only'),

    // TTL de la cache de /api/stats cuando regulator_mode consulta la GPU
    // remota. Mantenerlo corto: 15s da margen para no castigar al
    // nodo ASR sin perder relevancia operativa.
    'regulator_remote_cache_seconds' => (int) env('TRANSCRIPTOR_REGULATOR_REMOTE_CACHE_SECONDS', 15),

    // Timeout estricto para /api/info. Si la API no responde en este plazo
    // el regulador considera la senal como "unknown" y NO dispara freno por
    // GPU (fail-open conservador). 800ms es compatible con una API bajo carga
    // pero descarta conexiones colgadas.
    'regulator_remote_timeout_ms' => (int) env('TRANSCRIPTOR_REGULATOR_REMOTE_TIMEOUT_MS', 800),

    // Path del endpoint de telemetria remota. La API upstream expone
    // /api/metrics/overview (publicado 2026-09-14, plano, sin auth, con
    // node.workers, gpu.util_pct, ramdisk.pct, queue.by_state_corrected).
    // /api/info es alternativo mas detallado pero requiere Bearer token.
    'regulator_remote_info_path' => env('TRANSCRIPTOR_REGULATOR_REMOTE_INFO_PATH', '/api/metrics/overview'),

    // Umbral (0-100) de ocupacion de la GPU remota a partir del cual el
    // regulador dispara `reason=remote_gpu_saturated`.
    'regulator_remote_saturation_pct' => (int) env('TRANSCRIPTOR_REGULATOR_REMOTE_SATURATION_PCT', 80),

    // Umbral (0-100) de uso del ramdisk remoto a partir del cual el regulador
    // dispara `reason=remote_ramdisk_pressure`. La API upstream
    // (/api/metrics/overview) reporta ramdisk.pct; cuando supera este umbral
    // el tick frena para no empeorar la presion de espacio.
    'remote_ramdisk_pressure_pct' => (int) env('TRANSCRIPTOR_REMOTE_RAMDISK_PRESSURE_PCT', 85),

    // Umbral (0-100) de uso de RAM host remoto a partir del cual el regulador
    // dispara `reason=remote_ram_pressure`. RAM alta mata el contenedor antes
    // que el disco se llene; es la senal de OOM inminente.
    'remote_ram_pressure_pct' => (int) env('TRANSCRIPTOR_REMOTE_RAM_PRESSURE_PCT', 90),

    // Estrategia target-cola-remota: el regulador mantiene la cola del API
    // upstream entre [floor_remote_queue, target_remote_queue]. Bajo el piso,
    // envia un pulso completo (pulse_batch_size). Sobre el techo, frena.
    // Entre los dos, el batch baja linealmente para drenar sin saturar.
    'target_remote_queue'  => (int) env('TRANSCRIPTOR_TARGET_REMOTE_QUEUE', 180),
    'floor_remote_queue'   => (int) env('TRANSCRIPTOR_FLOOR_REMOTE_QUEUE', 30),
    'pulse_batch_size'     => (int) env('TRANSCRIPTOR_PULSE_BATCH_SIZE', 50),

    // Edad (segundos) a partir de la cual un job en `queued` se cuenta como
    // stuck y reduce el batch del siguiente tick. Suele correlacionar con
    // "la API upstream esta congestionada".
    'remote_wait_warn_seconds' => (int) env('TRANSCRIPTOR_REMOTE_WAIT_WARN_SECONDS', 60),

    // Porcentaje de reduccion del batch por cada job stuck detectado. Maximo
    // acumulado 80%. Con 2 stuck y 20% el batch baja 40%.
    'stuck_penalty_pct' => (int) env('TRANSCRIPTOR_STUCK_PENALTY_PCT', 20),

    // Minimo porcentaje del audio que debe cubrir el SRT para considerarlo
    // completo. Caso real: la API upstream marca como done transcripciones
    // que solo procesaron ~48 segundos de 21 min de audio. Con 90% el
    // orquestador detecta cualquier SRT que termine antes del 90% del
    // audio y lo reencola. Threshold conservador: el ultimo timestamp del
    // SRT puede quedar 5-10% corto por pausas naturales del locutor.
    'min_srt_completion_pct' => (int) env('TRANSCRIPTOR_MIN_SRT_COMPLETION_PCT', 90),

    // Maximo de reintentos para SRT truncado antes de marcar dead. Evita
    // loops infinitos si la API tiene un bug permanente.
    'srt_retry_max' => (int) env('TRANSCRIPTOR_SRT_RETRY_MAX', 3),

    // Umbral (segundos) para que el panel de diagnostico pinte en ambar la
    // tarjeta de la etapa cuyo p95 lo supere. Default 300s = 5min, alineado
    // con el SLO operativo "una transcripcion de 15min no debe tardar mas
    // de 5min en resolverse una vez commitada".
    'latency_p95_warn_seconds' => (int) env('TRANSCRIPTOR_LATENCY_P95_WARN_SECONDS', 300),
];