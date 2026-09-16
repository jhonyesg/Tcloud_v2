<?php

return [

    'default' => env('QUEUE_CONNECTION', 'redis'),

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('QUEUE_DB_CONNECTION', 'pgsql'),
            'table' => env('QUEUE_TABLE', 'jobs'),
            'queue' => 'default',
            'retry_after' => (int) env('QUEUE_RETRY_AFTER', 90),
            'after_commit' => true,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => env('REDIS_QUEUE', 'default'),
            // Post-transcriptor-pg-native-queue: el transcriptor ya NO usa esta
            // cola (consume directo de `transcriptions` con FOR UPDATE SKIP LOCKED).
            // Otras colas (SendAlertDigest, MentionsExportJob, BackfillKeywordMatches)
            // siguen usando el driver redis. retry_after=900 es el techo seguro
            // para jobs de hasta 600s de ffmpeg + margen.
            'retry_after' => (int) env('QUEUE_RETRY_AFTER', 900),
            'block_for' => null,
            'after_commit' => true,
        ],
    ],

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'failed_jobs',
    ],
];