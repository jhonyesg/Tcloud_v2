<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dashboard tiered cache
    |--------------------------------------------------------------------------
    |
    | TTLs del cache por tiers del payload de /dashboard. Los bloques "cold"
    | (stats globales, agregados del editor de medios) cambian lento y son
    | caros de calcular; los "warm" (cobertura de Mis Avisos) cambian en
    | minutos. Los bloques "hot" (RAM/SHM/sesiones/jobs) no se cachean.
    |
    | Cada tier usa Cache::flexible([fresh, stale_total]): dentro de `fresh`
    | se sirve el valor tal cual; entre `fresh` y `stale_total` se sirve el
    | valor previo y se recalcula en background tras responder.
    |
    */

    'cold_ttl' => (int) env('DASHBOARD_COLD_TTL', 900),
    'cold_stale_total' => (int) env('DASHBOARD_COLD_STALE_TOTAL', 1800),

    'warm_ttl' => (int) env('DASHBOARD_WARM_TTL', 120),
    'warm_stale_total' => (int) env('DASHBOARD_WARM_STALE_TOTAL', 600),

];
