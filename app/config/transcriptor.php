<?php

return [

    'base_url' => env('TRANSCRIPTOR_BASE_URL', 'http://192.168.0.138:9000'),

    'api_key' => env('TRANSCRIPTOR_API_KEY', ''),

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

    // Cuantos archivos recientes sin transcripcion toma el scanner por storage por ciclo.
    // En el modal de la UI esto es configurable; el valor por defecto es amplio para
    // cubrir todos los cortes del día actual casi de inmediato.
    'scan_batch' => (int) env('TRANSCRIPTOR_SCAN_BATCH', 100),

    // Tiempo (segundos) que un archivo debe llevar sin modificarse para considerarse completo
    'scan_min_age_seconds' => (int) env('TRANSCRIPTOR_SCAN_MIN_AGE_SECONDS', 60),

    // Dias hacia atras que escanea el comando scan-and-submit por defecto (0 = solo hoy)
    'scan_days_back' => (int) env('TRANSCRIPTOR_SCAN_DAYS_BACK', 0),

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
];