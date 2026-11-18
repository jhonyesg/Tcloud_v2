<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
     * Raiz donde viven los discos de material (Disco_A..Disco_I).
     *
     * La usa `StoragePathRepairService` para recorrer los discos al buscar el
     * material de un storage cuya ruta base dejo de existir, y para validar
     * que una ruta destino sea media real y no una ruta arbitraria del
     * sistema.
     */
    'cloud_root' => env('CLOUD_ROOT', '/www/wwwroot/data.mediaserver.com.co/Tcloud'),

    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
        ],
        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],
    ],
    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],
];