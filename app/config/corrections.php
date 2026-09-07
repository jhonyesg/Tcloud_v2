<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Ventana de undo para acciones masivas
    |--------------------------------------------------------------------------
    | Cuántos minutos después de una acción masiva (bulk approve/reject/destroy)
    | el admin todavía puede deshacerla via POST /correcciones/undo/{bulkActionId}.
    | Pasado este tiempo, el endpoint retorna 410 Gone.
    */
    'undo_window_minutes' => (int) env('CORRECTIONS_UNDO_WINDOW_MINUTES', 5),

    /*
    |--------------------------------------------------------------------------
    | Máximo de IDs por acción masiva
    |--------------------------------------------------------------------------
    | Límite superior del array `ids` que el admin puede enviar en una sola
    | acción masiva. Previene acciones masivas accidentales enormes.
    */
    'bulk_max_ids' => (int) env('CORRECTIONS_BULK_MAX_IDS', 500),

    /*
    |--------------------------------------------------------------------------
    | Retención del log de undo
    |--------------------------------------------------------------------------
    | Días que se conservan las entradas de correction_bulk_actions antes
    | de ser purgadas por CleanupBulkActionsLogCommand (cron diario).
    */
    'undo_log_retention_days' => (int) env('CORRECTIONS_UNDO_LOG_RETENTION_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Mínimo de palabras para proponer una corrección
    |--------------------------------------------------------------------------
    | Decisión del 2026-08-13. Una regla corta dispara en todo el corpus sin
    | mirar el contexto, y el resultado es espanglish en vez de una mejora:
    | `of love`->`de love`, `of security`->`de security`, `of emergency`->
    | `de emergency`. Todas eran de dos palabras, así que prohibir solo la
    | palabra suelta no bastaba; a partir de tres, la frase se ancla en su
    | contexto y deja de disparar donde no debe.
    |
    | Solo afecta a los PRODUCTORES automáticos (ai-suggest, miner, cycle). El
    | alta manual del admin en /ia/correcciones no pasa por aquí: si un humano
    | decide que una palabra concreta hay que corregirla, es su criterio.
    */
    'min_suggestion_words' => (int) env('CORRECTIONS_MIN_SUGGESTION_WORDS', 3),

    /*
    |--------------------------------------------------------------------------
    | Ejemplos de contexto en transcripciones
    |--------------------------------------------------------------------------
    | Cambios/2026-08-13-corrections-context-examples: al moderar una corrección
    | el admin puede pedir ejemplos reales de dónde dispara la regla, buscándolos
    | en vivo sobre transcription_segments (8,3 GB).
    |
    | La búsqueda solo es viable porque se apoya en el índice GIN trigram de la
    | columna `text`. Ver CorrectionContextFinder para el detalle; los límites de
    | abajo son las guardas que impiden que degenere en un scan de tabla:
    |
    | - min_probe_length: pg_trgm no puede servir patrones de <3 caracteres, así
    |   que con sondas más cortas ni se consulta (devuelve 'too_short').
    | - scan_limit: filas que se traen antes de deduplicar por transcripción.
    | - timeout_ms: statement_timeout de la consulta. Al agotarse se responde
    |   'timeout' en vez de propagar el error.
    | - cache_ttl: el diccionario es estable, así que se cachea con holgura. La
    |   clave incluye updated_at y se invalida sola al editar la corrección.
    */
    'context' => [
        'examples' => (int) env('CORRECTIONS_CONTEXT_EXAMPLES', 5),
        'scan_limit' => (int) env('CORRECTIONS_CONTEXT_SCAN_LIMIT', 30),
        'timeout_ms' => (int) env('CORRECTIONS_CONTEXT_TIMEOUT_MS', 10000),
        'cache_ttl' => (int) env('CORRECTIONS_CONTEXT_CACHE_TTL', 604800),
        'min_probe_length' => (int) env('CORRECTIONS_CONTEXT_MIN_PROBE_LENGTH', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Modo sensibles de la lista de revisión de transcripciones
    |--------------------------------------------------------------------------
    | corrections-manual-only-and-context-search (2026-09-05): el filtro y el
    | conteo de matches sensibles corren acotados a las N candidatas y bajo
    | statement_timeout; al agotarse se responde degradado (counts vacíos +
    | flag), nunca un 504 de nginx.
    */
    'review_sensitive' => [
        'timeout_ms' => (int) env('CORRECTIONS_REVIEW_SENSITIVE_TIMEOUT_MS', 10000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Corrección inline por ejemplo con IA (cambios 2026-09-05)
    |--------------------------------------------------------------------------
    | Cache de respuestas del flujo "Corregir esta frase con IA" en el modal
    | de contexto. TTL por defecto 24h: re-abrir el modal sin re-gastar tokens,
    | "Reintentar" sí consume. Clave incluye la fecha para invalidar de
    | manera natural cuando cambia la regla padre.
    */
    'ai_context_correct' => [
        'cache_ttl' => (int) env('CORRECTIONS_AI_CONTEXT_CORRECT_CACHE_TTL', 86400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Blocklist de términos context-sensitive
    |--------------------------------------------------------------------------
    | Cambios/2026-08-02-corrections-dictionary-atomicity: detecta muletillas
    | y falsos amigos en las correcciones y los marca con risk_level 'high' o
    | 'medium' para excluirlos del applyToText() automático.
    |
    | El auditor (ContextShiftAuditor) itera correcciones aprobadas y, por
    | cada término de esta lista que aparezca como token independiente
    | (\b{term}\b) en wrong_text, sugiere el risk_level correspondiente.
    | El admin puede sobrescribir manualmente.
    */
    /*
    |--------------------------------------------------------------------------
    | Detector de inglés residual en segmentos
    |--------------------------------------------------------------------------
    | Cambios/2026-08-11-english-residual-segment-detector: clasifica cada
    | token de un segmento en en/es/unknown y calcula un score de mezcla.
    | Si el score supera el threshold, la transcripción se marca needs_review.
    */
    'english_residual' => [
        'threshold' => (float) env('EN_RESIDUAL_THRESHOLD', 0.25),
        'en_functions' => [
            // Solo palabras inequívocamente inglesas. NO incluir 'a','an','in',
            // 'on','to','by','do','or','but','can' porque también son comunes
            // en español (preposiciones/conjunciones) y producirían falsos
            // positivos masivos.
            'the','and','of','for','with','at','from',
            'is','are','was','were','this','that','these','those',
            'have','has','had','does','did','will','would','should',
            'could','may','might','must',
        ],
        'es_stopwords' => [
            'el','la','los','las','un','una','unos','unas','y','o','pero','que',
            'de','en','a','por','para','con','sin','sobre','entre','es','son',
            'era','eran','fue','fueron','ser','estar','tener','haber',
            'este','esta','estos','estas','ese','esa','esos','esas',
            'muy','más','menos','sí','no','ya','también','porque','como',
            'cuando','donde','si','lo','le','les','me','te','se','nos',
            'mi','tu','su','yo','tú','él','ella','ellos','ellas',
            'nosotros','vosotros','ha','han','he','has','hemos','hay',
            'a','al','del','una',
        ],
    ],

    'context_sensitive' => [
        // Muletillas / discourse markers: NO se traducen automáticamente porque
        // cambian el registro/tone (ej: "you know" muletilla vs "you know" verbo).
        'filler_words' => [
            ['term' => 'like',       'risk' => 'high',   'note' => 'muletilla cuando no es comparación'],
            ['term' => 'you know',   'risk' => 'high',   'note' => 'muletilla enfática'],
            ['term' => 'i mean',     'risk' => 'high',   'note' => 'muletilla dubitativa'],
            ['term' => 'basically',  'risk' => 'medium', 'note' => 'puede ser contenido o muletilla'],
            ['term' => 'literally',  'risk' => 'medium', 'note' => 'muletilla intensificadora'],
            ['term' => 'honestly',   'risk' => 'medium', 'note' => 'muletilla o adverbio'],
            ['term' => 'obviously',  'risk' => 'medium', 'note' => 'muletilla o adverbio'],
            ['term' => 'sort of',    'risk' => 'high',   'note' => 'muletilla dubitativa'],
            ['term' => 'kind of',    'risk' => 'high',   'note' => 'muletilla dubitativa'],
            ['term' => 'right',      'risk' => 'medium', 'note' => 'tag question, contexto-dependiente'],
            ['term' => 'okay',       'risk' => 'medium', 'note' => 'muletilla o respuesta'],
        ],

        // Falsos amigos / false cognates: la traducción tiene sentido distinto
        // en EN vs ES. Si el correct_text contiene la traducción unsafe, se
        // marca como high risk.
        'false_friends' => [
            ['term' => 'actually',    'safe_translations' => ['en realidad', 'de hecho', 'la verdad'], 'unsafe' => ['actualmente'], 'risk' => 'high'],
            ['term' => 'eventually',  'safe_translations' => ['con el tiempo', 'al final'],            'unsafe' => ['finalmente'],  'risk' => 'high'],
            ['term' => 'sensitive',   'safe_translations' => ['sensible', 'susceptible'],               'unsafe' => [],               'risk' => 'medium'],
            ['term' => 'sympathetic', 'safe_translations' => ['comprensivo', 'empático'],               'unsafe' => ['simpático'],    'risk' => 'high'],
            ['term' => 'actual',      'safe_translations' => ['real', 'verdadero'],                     'unsafe' => ['actual'],       'risk' => 'high'],
            ['term' => 'realize',     'safe_translations' => ['darse cuenta'],                          'unsafe' => [],               'risk' => 'low'],
            ['term' => 'eventual',    'safe_translations' => ['final', 'posterior'],                    'unsafe' => [],               'risk' => 'medium'],
        ],
    ],
];