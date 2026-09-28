<?php

return [

    'fs_primary_enabled' => (bool) env('MIS_ARCHIVOS_FS_PRIMARY', false),

    'fs_primary_canary_storage_ids' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('MIS_ARCHIVOS_FS_CANARY', ''))
    ))),

    'listing_limit' => (int) env('MIS_ARCHIVOS_LISTING_LIMIT', 500),

    'path_cache_ttl' => (int) env('MIS_ARCHIVOS_PATH_CACHE_TTL', 60),

    'missing_grace_days' => (int) env('MIS_ARCHIVOS_MISSING_GRACE_DAYS', 7),

    'matcher_mode' => env('MIS_ARCHIVOS_MATCHER_MODE', 'hot_warm'),

    'matcher_rows_per_minute' => (int) env('MIS_ARCHIVOS_MATCHER_ROWS_PER_MIN', 2000),

    'matcher_warm_depth' => (int) env('MIS_ARCHIVOS_MATCHER_WARM_DEPTH', 2),

    'benchmark_samples' => (int) env('MIS_ARCHIVOS_BENCHMARK_SAMPLES', 20),
];
