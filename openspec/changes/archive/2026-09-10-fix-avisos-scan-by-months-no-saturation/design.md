## Context

Reproducción del 2026-09-09 con Playwright: al elegir "Histórico completo" + "Forzar re-escaneo" en `/ia/avisos-inteligentes`, el worker disparó 8 queries pesadas en paralelo (cada una con `count(*) FROM transcriptions ⋈ files ⋈ keywords WHERE state='done'`, ~5s cada una sobre 355k filas × 65 keywords), saturó las 24 conexiones de PostgreSQL y dejó PHP-FPM sin conexiones libres para servir cualquier otra request. Diagnóstico vía `pg_stat_activity`. El servidor se liberó cuando el worker completó, pero durante ~3 minutos toda la plataforma quedó colgada.

El path actual del worker es "monolítico": si `noWindow=true && force=true && !from && !to && !preset`, itera sobre TODO el historial de transcripciones sin acotar la unidad de trabajo. Los fixes anteriores (índice, rate-limit, confirmación UI) son paliativos.

## Goals / Non-Goals

**Goals:**
- Rediseñar el path `noWindow=true && force=true && !from && !to && !preset` para que la unidad de trabajo esté acotada: cada mes es una mini-corrida.
- Yielding explícito entre meses (`DB::disconnect()` + `sleep(1)`) para liberar conexiones de BD y que PHP-FPM pueda servir otras requests.
- Plan persistente en cache: el operador puede ver progreso por mes y el sistema puede reanudar tras crash.
- UX nueva: el modal muestra "Procesando mes X/N (YYYY-MM)" en lugar del contador de candidatos.
- Confirmación pre-launch con el # de meses estimado (siguiendo el patrón del full scan existente).
- Migration opcional con índice `(state, finished_at) WHERE state='done'` para que la query por mes vuele.

**Non-Goals:**
- Eliminar el path clásico: sigue vigente para presets, from/to, force sin noWindow.
- Paralelizar entre meses: secuencial por diseño (orden de matching, cancelación cooperativa simple).
- Agregar el modo mensual al cron automático (ya respeta `windowHours`).
- Cambiar el matching de keywords ni el shape de `segment_keyword_hits`.
- Cambiar el widget global (compatible vía campos adicionales).

## Decisions

### 1. Unit-of-work: cada mes es un ciclo cerrado de cache+matching

**Decisión**: cada mes del plan es la unidad visible y reanudable, pero conserva el drenaje interno por tandas de hasta 50 candidatos. Cada tanda ejecuta `selectCandidates` → matching → escritura en `segment_keyword_hits` → update de cache. Esto acota:
- Memoria del worker: solo carga los candidatos del mes actual (≤50 por tanda, N tandas)
  - Cancelación: se revisa entre tandas y entre meses
  - Duración por unidad: cada consulta queda acotada a una tanda, aunque un mes pueda tener muchas tandas
- BD pressure: cada mes libera la conexión antes del siguiente

**Rationale**: el sistema actual itera sobre tandas de 50 dentro de un mes. El cambio es hacer que el "mes" sea el límite natural del ciclo, no las tandas individuales. Operador ve "mes X/N" en lugar de "tanda 100/100 dentro de un scan gigante".

**Alternativa descartada**: paralelizar 4 meses a la vez. Descartado por complejidad de cancelación cooperativa y porque el yielding + secuencial ya da el beneficio de saturación sin complicar el modelo.

### 2. Discovery del plan via min/max(finished_at), NO desde `transcriptions` entera

**Decisión**: el planning phase hace UNA query: `SELECT min(finished_at), max(finished_at) FROM transcriptions WHERE state='done'`, captura un `scan_cutoff_at` y deriva un snapshot estable del plan. El índice nuevo se valida con `EXPLAIN`; no se asume una latencia fija ni se considera requisito para la corrección del cambio.

**Rationale**: el operador no siempre sabe cuántos meses hay. El sistema lo descubre y se lo dice. Esto también maneja el caso "no hay datos" sin disparar el worker.

**Alternativa descartada**: que el operador indique el rango manualmente. Descartado porque contradice la opción "Histórico completo" (que significa "todo").

### 3. Yielding con `DB::disconnect()` + `sleep(1)`

**Decisión**: después de cada mes, el worker libera la conexión usada por el proceso CLI y hace `sleep(1)`. El siguiente mes abre una conexión nueva. El objetivo es limitar la permanencia de recursos del worker; no se afirma que el proceso comparta conexiones con PHP-FPM.

**Rationale**: clave para que PHP-FPM recupere conexiones. Sin `disconnect()`, PHP-FPM-worker-pool mantiene la conexión asociada al proceso del worker, y PHP-FPM se queda con menos workers libres para servir tráfico web. `sleep(1)` da respiración incluso si FPM tiene mucha cola.

**Alternativa descartada**: usar `pcntl_signal()` para señalar al worker que ceda. Descartado porque `setsid` + `execBackground` rompen la trazabilidad de PID.

### 4. Plan persistente en `month_plan` con mismo TTL del run

**Decisión**: el plan se almacena en la misma cache key `avisos_scan_bg:{runId}` bajo `month_plan[YYYY-MM]`. El TTL se renueva con cada actualización de progreso. Si el worker muere a mitad, un mes `running` se trata como no completado y se salta únicamente los meses `done`. La implementación debe definir explícitamente si la recuperación reutiliza el mismo `runId` o si relanza de forma idempotente.

**Rationale**: mejor UX que un scan "todo desde cero". Si el operador tiene que interrumpir (mantenimiento, fin de turno), no pierde progreso.

**Alternativa descartada**: persistir en `transcriptions` una tabla `month_plan`. Descartado por simplicidad — la cache ya está y la durabilidad de 2h es suficiente (si el operador quiere persistencia >2h, puede dividir en runs).

### 5. Confirmación pre-launch con `/scan/run-bg/preview`

**Decisión**: nuevo endpoint `POST /scan/run-bg/preview` que ejecuta únicamente el planning sin mutar. Devuelve `{month_count, first_month, last_month, scan_cutoff_at}` y no ejecuta un conteo exacto de candidatos mediante los joins pesados. El frontend lo llama cuando detecta `noWindow + force` antes de mostrar el botón de confirmar.

**Rationale**: el operador ve "Vas a procesar 30 meses" antes de comprometer la BD. El número exacto de candidatos se conoce durante el drenaje; el preview no debe volver a ejecutar la consulta que causó la saturación.

**Alternativa descartada**: usar `window.confirm()` JS nativo con un número hardcoded. Descartado porque no escala y miente cuando el rango cambia.

### 6. Migration del índice parcial por fecha — opcional pero recomendada

**Corrección de la decisión**: la forma preferida a validar es `CREATE INDEX CONCURRENTLY transcriptions_finished_at_done_idx ON transcriptions(finished_at) WHERE state='done'`. No se requiere `DESC`; PostgreSQL puede recorrer el B-tree en sentido inverso.

**Decisión**: nueva migration con `CREATE INDEX CONCURRENTLY transcriptions_state_finished_at_idx ON transcriptions(state, finished_at) WHERE state='done'`. Partial index para no inflar el catálogo con estados que no nos importan.

**Rationale**: la query por mes ya está acotada por el plan, así que **no es bloqueante para el fix principal**. La forma del índice debe validarse con `EXPLAIN`; no se promete una duración fija de la migration.

**Alternativa descartada**: índice sin partial `WHERE state='done'`. Descartado porque el resto de estados (pending, dispatched) no se benefician de este índice.

## Risks / Trade-offs

- **[Duración total mayor]** → Catch-up de 30 meses a 30s/mes = 15 minutos vs. ~5 min del monolito (que satura). Mitigación: el plan es resumible, así que el operador puede interrumpir y continuar más tarde. El progreso por mes da feedback continuo.
- **[Complejidad del cache shape]** → añadimos `mode`, `month_plan`, `current_month`, `months_total`, `months_done`. Mitigación: el frontend que ya lee el state solo añade UI condicional para esos campos; el resto sigue igual. Tests E2E validan el shape.
- **[Race condition entre cache y worker]** → el worker, el endpoint de stop y el scanner pueden escribir el mismo state. Mitigación: actualizaciones protegidas por lock/merge; `stop_requested=true` nunca puede volver a `false` y los meses `done` no pueden perderse.
- **[TTL puede expirar mid-scan]** → el TTL se renueva con cada tanda y debe separarse del criterio de worker stale. Un mes `running` se trata como no completado al recuperar.
- **[Mes muy grande]** → un mes puede contener gran parte del histórico. Mitigación: el mes no es la unidad de consulta; las tandas de 50 siguen siendo el límite de consulta, memoria y cancelación.
- **[Datos nuevos durante el scan]** → el plan usa `scan_cutoff_at` como snapshot. Las transcripciones posteriores quedan para otra corrida o el cron.
- **[Activación en producción]** → feature flag de emergencia permite desplegar desactivado y habilitar después de validar la migration y las métricas.
- **[Yielding de 1s × 30 meses = 30s "perdidos" total]** → aceptable. Mejor 30s de yielding que 3 min de saturación total.
- **[El preview endpoint añade latencia al click]** → ~50ms para el planning query. Imperceptible.

## Migration Plan

1. Crear y ejecutar la migration con `CONCURRENTLY`, fuera de transacción, sin asumir una duración fija.
2. Verificar que el índice quedó válido.
3. Desplegar el código con el feature flag desactivado.
4. Reload de PHP-FPM con `setsid bash` y `kill -USR2` (o `nginx -s reload && systemctl reload php84-php-fpm`).
5. Activar el feature flag después de la validación inicial.
6. **No** requiere reinicio de workers supervisord.
5. Verificación post-deploy:
   - Click "Escanear ahora" + "Histórico completo" + "Forzar" → ver el preview con # de meses.
   - Confirmar → ver el modal con "Procesando mes 1/N (YYYY-MM)".
   - En otro tab, navegar a `/dashboard` y `/mis-archivos` durante el scan → deben responder rápido (<2s).
   - Monitorear `pg_stat_activity` durante el scan: máximo 4-6 conexiones activas (no 24).
7. Rollback: desactivar primero el feature flag; luego `git revert` + reload PHP-FPM si fuera necesario. La migration se revierte separadamente con `DROP INDEX CONCURRENTLY`.

## Open Questions

## Refuerzos acordados

- El mes es la unidad visible y reanudable; las tandas de hasta 50 siguen siendo la unidad real de consulta, memoria y cancelación.
- El planning captura `scan_cutoff_at`; el plan es un snapshot y no incorpora transcripciones creadas después de iniciar la corrida.
- Los rangos mensuales deben ser semiabiertos: `>= inicio_del_mes` y `< inicio_del_mes_siguiente`, evitando problemas de segundos, microsegundos y límites duplicados.
- Un estado `running` sin completar se trata como pendiente al recuperar; solo los meses `done` se omiten.
- Las actualizaciones del state deben protegerse contra carreras entre worker y endpoint de stop. `stop_requested=true` no puede volver a `false`.
- El preview solo ejecuta planning. No debe calcular un `candidates_estimate` exacto mediante los joins pesados del matching.
- El modo mensual se habilita mediante un feature flag de emergencia para poder desplegar desactivado y activarlo después de validar el índice y las métricas.
- La migration del índice debe usar `CONCURRENTLY`, ejecutarse fuera de transacción, verificar el índice resultante y no asumir una duración fija. La forma recomendada a validar es `finished_at WHERE state='done'`; `DESC` no es necesario para recorridos descendentes.
- La primera versión mantiene los meses secuenciales; no se paralizan fases.

Ninguna para este change. La elección de unit-of-work (mes) y yielding (`disconnect + sleep(1)`) se resolvió en Decisions §1-3. La integración con el widget global es trivial (campos adicionales en `progress`).

Sin embargo, un punto que vale la pena **evaluar con otro proveedor** (como pidió el operador):
- El índice recomendado a validar es `finished_at WHERE state='done'`; no se requiere `DESC` porque PostgreSQL puede recorrer el B-tree en sentido inverso.
- No se paralelizan meses en esta versión; la protección de conexiones y la recuperación tienen prioridad.
- El preview es `POST` para recibir el mismo body del lanzamiento y evitar caché accidental del navegador.
