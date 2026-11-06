<?php

return [
    // Existing NULL expirations remain permanent. This value applies only to new links.
    'default_expiry_days' => (int) env('SHARE_DEFAULT_EXPIRY_DAYS', 30),

    // Keep filesystem verification and destructive operations bounded per request.
    'max_bulk_items' => (int) env('SHARE_MAX_BULK_ITEMS', 200),
    'availability_verification_limit' => (int) env('SHARE_AVAILABILITY_VERIFICATION_LIMIT', 100),

    // Threshold that requires the operator to type "ELIMINAR" before bulk delete proceeds.
    'hard_confirmation_threshold' => (int) env('SHARE_HARD_CONFIRMATION_THRESHOLD', 25),

    // Maximo de elementos que se listan en una carpeta compartida.
    //
    // El listado de una carpeta publica reune los hijos directos de la fila
    // de la carpeta MAS las raices de los storages cuyo `base_path` cae
    // dentro de su ruta fisica. Una carpeta que sea la raiz fisica de un
    // sub-storage puede tener decenas de miles de entradas: la vista las
    // serializa todas en el DOM, asi que sin tope la pagina publica se
    // vuelve inutilizable. Al superar el tope, la vista lo informa en vez de
    // truncar en silencio.
    'folder_listing_max_items' => (int) env('SHARE_FOLDER_LISTING_MAX_ITEMS', 2000),
];
