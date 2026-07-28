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
            // DEBE superar ConvertAndTranscribeJob::$timeout (600s) con margen.
            // Con retry_after=90 Redis devolvia a la cola todo job de mas de 90s
            // mientras el worker original seguia en ffmpeg, produciendo dos ffmpeg
            // y dos POST del mismo archivo. La guarda por job_id no cubre esa
            // ventana: solo actua una vez la primera copia ha escrito job_id.
            // Validado por transcription:config, que falla si retry_after <= 600.
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