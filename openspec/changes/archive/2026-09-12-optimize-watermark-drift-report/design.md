## Context

`WatermarkReconciler::driftReport()` (app/app/Services/Ia/WatermarkReconciler.php:202) calcula dos conjuntos de pares:

- **aplicables**: `user_keyword × user_storages` con `user_alerts_inteligentes.enabled = true` y `transcription_access = true`.
- **existentes**: filas de `keyword_scan_watermarks`.

Hoy materializa ambos como `Collection` y usa `Collection::contains()` anidados para calcular `missing` y `orphan`. Con 1245 aplicables × 910 existentes son ~2.3 M comparaciones de clousures, ~1.2 s medido.

El método es consumido por el dashboard (tier tibio, se invalida con frecuencia por el `CacheEpoch`) y por `avisos:reconcile-watermarks`. Ver proposal.md para la motivación.

## Goals / Non-Goals

**Goals:**
- Reducir `driftReport()` de ~1.2 s a orden de milisegundos.
- Preservar exactamente el contrato de retorno y los conteos.

**Non-Goals:**
- Cambiar qué pares son aplicables/huérfanos.
- Mover el cálculo a SQL puro.
- Tocar cache, TTLs o auditoría.

## Decisions

### D1 — Set-difference por hash en PHP

Convertir aplicables y existentes a arrays `$set[$keywordId . ':' . $storageId] = true`, luego:

```php
$missing = array_keys(array_diff_key($applicableSet, $existingSet)); // drift negativo
$orphan  = array_keys(array_diff_key($existingSet, $applicableSet));  // drift positivo
```

Complejidad O(n+m). Medido: 1.6 ms vs 1.2 s, mismos conteos (missing=396, orphan=61).

**Alternativa considerada**: `EXCEPT` en SQL. Más rápido aún, pero requiere dos queries extra y reconstruir objetos `stdClass` con `keyword_id`/`storage_provider_id` para preservar el shape que consumen `ReconcileWatermarksCommand` (`$m->keyword_id`) y los tests. El hash en PHP ya alcanza el objetivo sin tocar el contrato. Se deja SQL como optimización futura si el volumen crece.

### D2 — Preservar el shape de `missing[]`/`orphan[]`

Los comandos y harnesses acceden a `$m->keyword_id` y `$m->storage_provider_id` (propiedades de objeto). Se mantiene la materialización de `$applicablePairs`/`$existingPairs` como colecciones de objetos y solo se cambia el cálculo de las diferencias; el `missing[]`/`orphan[]` se reconstruye desde el mapa de pares original para no introducir arrays asociativos.

### D3 — Sin cache adicional

Una vez O(n+m), `driftReport()` es barato y no necesita cache propio. El tier tibio del dashboard sigue existiendo para el conjunto, pero ya no es un problema que se invalide.

## Risks / Trade-offs

- **[Paridad de resultados]** → el hash usa ints de `keyword_id`/`storage_provider_id`; el harness de paridad compara el resultado nuevo contra el conteo esperado y los pares específicos (harness existente `harness_watermark_reconciler_audit.php` ya valida `missing[]` puntual).
- **[Tipos string vs int en las llaves]** → se castea a int ambos lados (`(int)`) antes de construir la llave; si una columna viniera como string numérico, la llave sería coherente de todos modos.
- **[`scope_user_id`]** → se preserva sin cambios.

## Migration Plan

1. Refactorizar `driftReport()` (solo el cálculo de diferencias).
2. Correr `harness_watermark_reconciler_audit.php`, `harness_coverage_dashboard.php` y `harness_dashboard_tiered_cache.php`.
3. Medir latencia antes/después.

**Rollback**: `git revert`; sin migración ni estado persistente.
