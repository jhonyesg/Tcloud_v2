<?php

/**
 * Papelera de reciclaje — configuracion global.
 *
 * retention_days: dias que un item permanece en papelera antes de la purga
 * automatica (15 por defecto, configurable).
 *
 * purge_batch_size: tamano del chunk para trash:purge. Mas bajo = menos memoria
 * y mas commits visibles en logs; mas alto = menos queries totales.
 *
 * purge_max_ratio: si candidatos / total > este ratio, la purga aborta con
 * log trash.purge.aborted_mass_delete. Protege contra bugs que harian que
 * toda la tabla acabe marcada como trash de golpe. Mismo patron que el
 * guardarrail de SessionService::cleanOrphans.
 *
 * lock_ttl: TTL del Cache::lock de trash:purge. Si el proceso muere a mitad
 * de la purga, el lock se libera solo despues de este tiempo.
 *
 * urgent_threshold_days: dias restantes a partir de los cuales el badge de la
 * papelera en el sidebar se pinta en rojo (urgente). 3 dias es el default
 * para alertar al usuario antes del borrado automatico.
 */
return [
    'retention_days' => (int) env('TRASH_RETENTION_DAYS', 15),
    'purge_batch_size' => (int) env('TRASH_PURGE_BATCH', 500),
    'purge_max_ratio' => (float) env('TRASH_PURGE_MAX_RATIO', 0.5),
    'lock_ttl' => (int) env('TRASH_LOCK_TTL', 600),
    'urgent_threshold_days' => (int) env('TRASH_URGENT_DAYS', 3),
    'sidebar_cache_ttl' => (int) env('TRASH_SIDEBAR_CACHE_TTL', 60),
];
