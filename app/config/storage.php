<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Directorio base de los storages personales
    |--------------------------------------------------------------------------
    |
    | Prefijo de path que identifica los storages personales de los usuarios.
    | La bandera `storage_providers.is_personal` se siembra desde este prefijo
    | en la migración de normalización y es la única fuente de verdad posterior;
    | este valor solo se usa para crear nuevos storages personales.
    |
    */
    'personal_base_path' => env('PERSONAL_STORAGE_BASE_PATH', '/home/www/Usuarios_tcloud/'),
];
