<?php

return [

    /*
     * Interruptor maestro del sincronizado de storages.
     *
     * Con esto en false, syncFolder() y fullSync() devuelven el listado actual de
     * BD sin escanear disco, sin crear filas y sin purgar nada. Es el corte de
     * emergencia y la palanca para ventanas de mantenimiento (p.ej. antes de
     * ejecutar files:dedupe).
     *
     * Cambiarlo requiere `php artisan config:clear`.
     */
    'enabled' => (bool) env('STORAGE_SYNC_ENABLED', true),

    /*
     * Guardas de purga.
     *
     * El incidente del 2026-07-27 ocurrio porque un montaje NFS caido deja el
     * punto de montaje como un directorio local vacio y legible: el escaner no
     * distinguia "vacio" de "no puedo leer", y el sync interpretaba el resultado
     * vacio como "borraron todo en disco" y vaciaba el arbol de la BD.
     */
    'prune' => [

        // Permite desactivar por completo el borrado de filas huerfanas.
        'enabled' => (bool) env('STORAGE_SYNC_PRUNE_ENABLED', true),

        // Nunca purgar cuando el disco devolvio 0 entradas pero la BD tiene filas.
        // Esta regla por si sola habria evitado el incidente completo.
        'refuse_on_empty' => (bool) env('STORAGE_SYNC_PRUNE_REFUSE_ON_EMPTY', true),

        // Por debajo de este numero de filas en BD no se aplica la regla de ratio:
        // en carpetas diminutas cualquier borrado legitimo supera cualquier ratio.
        'min_rows_for_ratio' => (int) env('STORAGE_SYNC_PRUNE_MIN_ROWS', 5),

        // Proporcion maxima de la carpeta que una sola pasada puede borrar.
        // Por encima de esto se asume problema de montaje, no borrado real.
        'max_delete_ratio' => (float) env('STORAGE_SYNC_PRUNE_MAX_RATIO', 0.34),
    ],

    /*
     * Ventana de silencio, en segundos, para el aviso de entradas ilegibles.
     *
     * Una entrada que devuelve EIO lo hace en cada pasada, asi que sin esto el
     * sincronizador repite el mismo warning indefinidamente: 30 carpetas dañadas
     * en los NFS de difusor01 generaban ~1476 lineas al dia.
     */
    'unreadable_log_ttl' => (int) env('STORAGE_SYNC_UNREADABLE_LOG_TTL', 21600),

    /*
     * Locks distribuidos (Redis) para serializar el sincronizado.
     *
     * Sin ellos, N cargas de pagina concurrentes sobre una carpeta vacia lanzaban
     * N escaneos simultaneos que se pisaban entre si e insertaban duplicados.
     * Jerarquia estricta storage -> carpeta, asi que no hay deadlock posible.
     */
    'lock' => [
        'folder_ttl' => (int) env('STORAGE_SYNC_LOCK_FOLDER_TTL', 300),
        'storage_ttl' => (int) env('STORAGE_SYNC_LOCK_STORAGE_TTL', 3600),

        // Ventana durante la cual no se reintenta el auto-escaneo de una carpeta
        // vacia desde el listado web. Mismo patron que PublicShareController.
        'autoscan_backoff' => (int) env('STORAGE_SYNC_AUTOSCAN_BACKOFF', 60),
    ],

    /*
     * Deteccion de montajes caidos.
     *
     * Rutas que se ESPERA que sean puntos de montaje. Si una de estas comparte
     * dispositivo con su directorio padre, el montaje no esta activo aunque el
     * directorio exista y sea legible.
     */
    'mounts' => [
        'expected' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('STORAGE_SYNC_EXPECTED_MOUNTS', ''))
        ))),

        // Fichero centinela opcional dentro de cada montaje. Si se define y no
        // existe, el montaje se considera caido. Mas fiable que la comparacion de
        // dispositivo, pero exige crearlo en cada export NFS.
        'sentinel' => env('STORAGE_SYNC_MOUNT_SENTINEL', ''),
    ],

    /*
     * Watchdog de accesibilidad (storage:health).
     *
     * `dispatch_reconcile` deja el sistema en modo observacion sin disparar
     * reconciliaciones automaticas: util para ventanas de mantenimiento.
     * `reconcile_cooldown` controla el TTL en Redis que evita redespachar
     * sobre el mismo storage en cada tick (mientras el lock siga siendo true).
     */
    'health' => [
        'dispatch_reconcile' => (bool) env('STORAGE_SYNC_HEALTH_DISPATCH', true),
        'reconcile_cooldown' => (int) env('STORAGE_SYNC_HEALTH_COOLDOWN', 280),
    ],

    /*
     * Pacing del reconciliador (storage:reconcile).
     *
     * Tras detectar un remonte, el reconciliador procesa las carpetas del
     * storage en chunks de N filas con `pace_seconds` segundos entre cada
     * uno. Asi no satura el server aunque el storage tenga 700k archivos.
     * `disable_via_env` permite cortar el reconciliador en operacion sin
     * retirar el schedule.
     */
    'reconcile' => [
        'batch_size' => (int) env('STORAGE_SYNC_RECONCILE_BATCH', 50),
        'pace_seconds' => (int) env('STORAGE_SYNC_RECONCILE_PACE', 2),
        'lock_ttl_seconds' => (int) env('STORAGE_SYNC_RECONCILE_LOCK_TTL', 3600),
    ],
];
