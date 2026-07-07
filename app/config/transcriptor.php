<?php

return [

    'base_url' => env('TRANSCRIPTOR_BASE_URL', 'http://192.168.0.138:9000'),

    'api_key' => env('TRANSCRIPTOR_API_KEY', ''),

    'webhook_token' => env('TRANSCRIPTOR_WEBHOOK_TOKEN', ''),

    'callback_host' => env('TRANSCRIPTOR_CALLBACK_HOST', 'http://192.168.0.118'),

    // Idioma por defecto enviado a la API del transcriptor (es, en, ...)
    'language' => env('TRANSCRIPTOR_LANGUAGE', 'es'),

    // Timeout en segundos para el POST de envio del archivo
    'submit_timeout' => (int) env('TRANSCRIPTOR_SUBMIT_TIMEOUT', 60),

    // Timeout en segundos para los GET de estado/SRT/stats
    'get_timeout' => (int) env('TRANSCRIPTOR_GET_TIMEOUT', 30),

    // Chunk size para el comando retroactivo de correcciones
    'corrections_chunk' => (int) env('TRANSCRIPTOR_CORRECTIONS_CHUNK', 500),

    // Cuantos archivos recientes sin transcripcion toma el scanner por storage por ciclo
    'scan_batch' => (int) env('TRANSCRIPTOR_SCAN_BATCH', 5),

    // Tiempo (segundos) que un archivo debe llevar sin modificarse para considerarse completo
    'scan_min_age_seconds' => (int) env('TRANSCRIPTOR_SCAN_MIN_AGE_SECONDS', 60),

    // Ventana (minutos) tras la cual un job queued/processing entra al polling de respaldo
    'stale_after_minutes' => (int) env('TRANSCRIPTOR_STALE_AFTER_MINUTES', 30),
];