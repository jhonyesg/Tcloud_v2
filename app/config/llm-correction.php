<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Habilitar/deshabilitar el suggester LLM-powered
    |--------------------------------------------------------------------------
    | Switch maestro. Cuando está en false, los comandos cron y los manuales
    | salen sin hacer nada (ni siquiera consumen tokens). Útil para apagarlo
    | rápidamente si el LLM está degradado o el coste sube.
    */
    'enabled' => env('LLM_CORRECTION_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Proveedor LLM
    |--------------------------------------------------------------------------
    | Solo OpenAI-compatible por ahora. Configurable para apuntar a
    | OpenAI, Anthropic (vía proxy), Groq, OpenRouter, Ollama (con proxy),
    | o Kilo Gateway.
    */
    'provider' => 'openai-compatible',

    'base_url' => env('LLM_BASE_URL', 'https://api.kilo.ai/api/gateway'),
    'api_key' => env('LLM_API_KEY'),
    'model' => env('LLM_MODEL', 'minimax/minimax-m3'),

    /*
    |--------------------------------------------------------------------------
    | Parámetros del request
    |--------------------------------------------------------------------------
    | temperature baja (0.2) para minimizar creatividad/alucinaciones.
    | max_tokens limitado para que un runaway no gaste el bolsillo.
    | timeout generoso (60s) porque el LLM puede tener cold start.
    */
    'timeout_seconds' => (int) env('LLM_TIMEOUT_SECONDS', 60),
    'max_tokens' => (int) env('LLM_MAX_TOKENS', 4000),
    'temperature' => (float) env('LLM_TEMPERATURE', 0.2),

    /*
    |--------------------------------------------------------------------------
    | Defaults operacionales
    |--------------------------------------------------------------------------
    | El cron cada-4h usa estos defaults. --days y --sample en CLI pueden
    | sobreescribirlos.
    */
    'sample_size_default' => (int) env('LLM_SAMPLE_SIZE_DEFAULT', 200),
    'days_back_default' => (int) env('LLM_DAYS_BACK_DEFAULT', 1),

    /*
    |--------------------------------------------------------------------------
    | Versionado del prompt
    |--------------------------------------------------------------------------
    | Cuando se cambia el system prompt, se incrementa este version.
    | Útil para auditoría (logs) y A/B testing futuro.
    */
    'prompt_version' => '2026-08-01',

    /*
    |--------------------------------------------------------------------------
    | Marcas protegidas (segunda capa de defensa)
    |--------------------------------------------------------------------------
    | Lista explícita de marcas/productos/organizaciones que NUNCA deben
    | ser traducidas. El system prompt ya las incluye, pero esta lista
    | es editable vía config sin tocar código (rotación rápida ante
    | incidentes).
    |
    | También utilizada por el post-filtro PHP para descartar candidatos
    | cuyo `wrong` matchee (completo o como sub-frase) cualquier entrada.
    */
    'protected_brands' => [
        // Software / cloud
        'microsoft', 'apple', 'google', 'sony', 'samsung', 'amazon', 'meta',
        'salesforce', 'slack', 'zoom', 'office', 'word', 'excel',
        'powerpoint', 'outlook', 'teams', 'gmail', 'drive',
        // Hardware
        'iphone', 'macbook', 'ipad', 'airpods',
        // Marcas colombianas / hispanas frecuentes en noticieros
        'dionato', 'ecopetrol', 'bancolombia', 'comcel', 'claro',
        'movistar', 'tigo', 'caracol', 'rcn',
        // Empresas hispanas de servicios / entretenimiento pedidas por el admin (2026-08-01)
        // — motivo: el LLM proponía "Open English" → español por no estar listadas.
        'epm', 'isa', 'grupo argos', 'nutresa',
        // Empresas de enseñanza de inglés (defensa explícita — son marcas, no contenido
        // a traducir; agregado tras feedback 2026-08-01).
        'open english', 'openenglish', 'ef education first', 'british council',
    ],
];
