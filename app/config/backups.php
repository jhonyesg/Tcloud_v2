<?php

return [
    /**
     * Vigencia del enlace público que se genera al atender una solicitud de
     * backup. Se cuenta en días CALENDARIO desde la creación del enlace.
     *
     * La spec de compartidos usa `shares.default_expiry_days` (30) para los
     * enlaces que crea el usuario a mano. Este valor es aparte y más corto:
     * el enlace de backup entrega el master de producción, así que vive
     * menos tiempo.
     */
    'link_validity_days' => (int) env('BACKUP_LINK_VALIDITY_DAYS', 2),

    /**
     * Longitud mínima del motivo que el cliente escribe en el modal. El
     * motivo es la justificación de por qué pide el material de producción:
     * sin un mínimo, "x" o "a" no sirve ni para auditar ni para priorizar.
     */
    'min_motivo_length' => (int) env('BACKUP_MIN_MOTIVO_LENGTH', 10),

    /**
     * Storage que contiene el árbol de backup ("Producción"). Es el storage
     * raíz del que cuelgan las carpetas de canal (`01_Caracol_BK`, …), y el
     * destino de `storage_providers.backup_storage_id`.
     *
     * Null = todavía no declarado: el comando `backups:map-storages` pide
     * `--root=` en ese caso.
     */
    'root_storage_id' => env('BACKUP_ROOT_STORAGE_ID') !== null
        ? (int) env('BACKUP_ROOT_STORAGE_ID')
        : null,
];
