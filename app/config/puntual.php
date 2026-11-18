<?php

return [

    /*
     * Vigencia de los enlaces de entrega (change `solicitud-puntual-tv-radio`).
     *
     * Días calendario contados desde el momento de la solicitud. Es una
     * política PROPIA de este flujo: no hereda la de los enlaces compartidos
     * (30 días por defecto en `shares.php`), porque el enlace de una
     * entrega no es un enlace que el dueño pueda extender.
     *
     * Un enlace de entrega no admite `expires_at = NULL`: no hay forma de
     * volverlo permanente.
     */
    'link_validity_days' => (int) env('PUNTUAL_LINK_VALIDITY_DAYS', 3),

    /*
     * Umbral de recomendación de la entrega por DÍA (en minutos).
     *
     * Por encima de este umbral, la interfaz compara la franja pedida con
     * el día completo y recomienda el día: una franja de 2 h 30 son 8-10
     * enlaces con 8-10 vencimientos que hay que administrar, mientras que
     * el día completo es UN enlace sobre la carpeta del día.
     *
     * Es un valor de configuración, no una constante: se puede mover sin
     * desplegar código.
     */
    'umbral_dia_minutos' => (int) env('PUNTUAL_UMBRAL_DIA_MINUTOS', 120),

    /*
     * Longitud mínima del motivo. YA NO SE USA: el motivo pasó a ser
     * opcional (2026-11-18). Se conserva la clave para no romper
     * despliegues que la lean.
     */
    'min_motivo_length' => (int) env('PUNTUAL_MIN_MOTIVO_LENGTH', 10),

    /*
     * Duración máxima de una franja, en minutos. Por encima, el cliente
     * tiene que pedir el día completo.
     */
    'max_franja_minutos' => (int) env('PUNTUAL_MAX_FRANJA_MINUTOS', 720),

    /*
     * Filas por página del historial de solicitudes.
     */
    'por_pagina' => (int) env('PUNTUAL_POR_PAGINA', 20),

    /*
     * Tamaño máximo de página que acepta el endpoint de historial.
     */
    'max_por_pagina' => (int) env('PUNTUAL_MAX_POR_PAGINA', 100),

];
