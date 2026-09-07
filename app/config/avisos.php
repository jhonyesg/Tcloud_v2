<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Motor de matching de menciones (mis-avisos-menciones)
    |--------------------------------------------------------------------------
    |
    | universal: scan único por transcripción con hits compartidos y reparto
    |            relacional (KeywordMatcher refactorizado).
    | legacy:    matcher por-usuario anterior (fila por usuario + correo
    |            síncrono), preservado como fallback de rollback inmediato.
    |
    */

    'engine' => env('AVISOS_ENGINE', 'universal'),

    /*
    |--------------------------------------------------------------------------
    | Rate limiter global del relay de correo (correos/minuto)
    |--------------------------------------------------------------------------
    |
    | Último freno compartido por TODOS los correos de aviso de menciones.
    | Protege el SMTP ante ráfagas (muchos clientes con cadencia corta).
    |
    */

    'mail_rate_per_minute' => env('AVISOS_MAIL_RATE_PER_MINUTE', 20),

    /*
    |--------------------------------------------------------------------------
    | Exportaciones históricas por cliente (Fase 3)
    |--------------------------------------------------------------------------
    */

    'exports' => [
        'max_active_per_user' => env('AVISOS_EXPORT_MAX_ACTIVE', 1),
        'max_per_day' => env('AVISOS_EXPORT_MAX_PER_DAY', 3),
        'signed_url_ttl_minutes' => env('AVISOS_EXPORT_URL_TTL', 120),
        'history_days' => 60, // frontera de retención del negocio
        'min_query_length' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Visor de transcripciones del cliente (mis-avisos-mentions-viewer)
    |--------------------------------------------------------------------------
    |
    | Ventana acotada de segmentos alrededor de la mención (window) y tamaño
    | de página para la expansión por cursores (page). Nunca se carga la
    | transcripción completa: range-scan por (transcription_id, segment_index).
    |
    */

    'transcript' => [
        'window' => (int) env('AVISOS_TRANSCRIPT_WINDOW', 120),
        'page' => (int) env('AVISOS_TRANSCRIPT_PAGE', 60),
        'max_page' => 200,
    ],
];