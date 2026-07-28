<?php

return [

    'base_url' => env('TRANSCRIPTOR_BASE_URL', 'http://192.168.0.138:9000'),

    'api_key' => env('TRANSCRIPTOR_API_KEY', ''),

    // Host de callback. SOLO PARA MOSTRAR en la UI: la spec
    // transcription-orchestrator-runtime §9 prohibe cablear una ruta de webhook.
    // La variable ya existia en .env pero no estaba mapeada, asi que la vista
    // pintaba un valor vacio.
    'callback_host' => env('TRANSCRIPTOR_CALLBACK_HOST', ''),

    // Idioma por defecto enviado a la API del transcriptor (es, en, ...)
    'language' => env('TRANSCRIPTOR_LANGUAGE', 'es'),

    // Modo de correccion de idioma: off | async | auto
    'lang_fix' => env('TRANSCRIPTOR_LANG_FIX', 'async'),

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

    // Tiempo (minutos) que un pending sin job_id se considera atascado y se reenvia
    'stale_after_minutes' => (int) env('TRANSCRIPTOR_STALE_AFTER_MINUTES', 30),

    // === Regulador del batch dispatcher (transcription:tick) ===
    // Target de jobs en Redis queues:transcription arriba del cual el regulador frena.
    // El transcriptor tiene 2 workers GPU; cola 140 lo mantiene alimentado sin hipersaturar.
    'target_redis_queue' => (int) env('TRANSCRIPTOR_TARGET_REDIS_QUEUE', 140),

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
    'tick_interval_minutes' => (int) env('TRANSCRIPTOR_TICK_INTERVAL_MINUTES', 2),

    // Separacion entre encolados dentro de un mismo lote (ms). 0 = todos a la vez.
    // Con >0 los jobs entran a Redis escalonados en vez de en el mismo instante:
    // es la diferencia entre 145 arranques simultaneos de ffmpeg y 145 repartidos.
    'dispatch_stagger_ms' => (int) env('TRANSCRIPTOR_DISPATCH_STAGGER_MS', 0),

    // Maximo de ffmpeg+POST SIMULTANEOS, independiente del numero de workers.
    // 0 = desactivado. Con 11 workers e inflight_max=4, los sobrantes esperan en
    // el semaforo Redis sin tocar systemd. El objetivo de cola regula el ritmo de
    // encolado; esto regula la concurrencia real contra los 2 workers GPU.
    'inflight_max' => (int) env('TRANSCRIPTOR_INFLIGHT_MAX', 0),

    // === Confiabilidad ===
    // Cuantos pending atascados reenvia poll-results por ciclo (era 50 hardcodeado).
    'stale_resend_limit' => (int) env('TRANSCRIPTOR_STALE_RESEND_LIMIT', 50),

    // Cuantos jobs queued/processing consulta el polling por ciclo. Estaba
    // hardcodeado en 100, por debajo del target de cola 140: el poll no alcanzaba
    // al dispatch.
    'poll_limit' => (int) env('TRANSCRIPTOR_POLL_LIMIT', 140),

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

    // Max reintentos automaticos antes de promover una Transcription a 'dead'.
    // Se incrementa en TranscriptionSubmitService::markError() y se valida contra este
    // limite en DiskScannerService::collectFailedCandidates() con el flag --include-failed.
    'max_retries' => (int) env('TRANSCRIPTOR_MAX_RETRIES', 3),
];