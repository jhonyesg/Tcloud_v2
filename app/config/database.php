<?php

return [
    'default' => env('DB_CONNECTION', 'pgsql'),
    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'tcloudstorage'),
            'username' => env('DB_USERNAME', 'cloud'),
            'password' => env('DB_PASSWORD', 'cloud123'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
            // Zona horaria de la SESION de PostgreSQL. Sin esto la sesion queda
            // en UTC, y como Laravel formatea los bindings DateTime con
            // `format('Y-m-d H:i:s')` en la zona del Carbon (America/Bogota),
            // un instante Bogota se enviaba como naive y PostgreSQL lo
            // interpretaba como UTC: cada `timestamptz` escrito desde la app
            // quedaba corrido -5h.
            //
            // Con la sesion en America/Bogota:
            //  - los `timestamptz` se escriben/leen con el instante correcto;
            //  - las columnas naive (created_at, dispatched_at, started_at...)
            //    se comparan contra `now()` en el MISMO marco horario, que es
            //    justo lo que necesitan el watchdog (dispatched_at < now()-15m)
            //    y el filtro "hoy" del worker (recorded_at >= medianoche local).
            //
            // Override operativo: DB_TIMEZONE=UTC en .env + config:cache.
            //
            // Change bogota-end-to-end-timezone: blindaje contractual. Bogota
            // es la unica zona soportada; el .env ya no puede cambiarla por
            // accidente. Si en el futuro se necesita multi-zona, ese es un
            // change nuevo (no una variable de entorno).
            'timezone' => 'America/Bogota',
        ],
    ],
    'migrations' => 'migrations',
    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', 'tcloud_'),
        ],
        'default' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],
        'cache' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],
        'session' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_SESSION_DB', '2'),
        ],
    ],
];