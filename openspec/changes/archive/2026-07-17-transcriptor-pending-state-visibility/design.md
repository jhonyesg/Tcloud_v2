## Context

El módulo `/ia/api-transcriptor` muestra `Pendientes: 0` y `Completados: 0` cuando el backend tiene `Transcription`s activas. El problema es estructural: el frontend clasifica cada `state` de BD (`pending`, `queued`, `processing`, `done`, `error`, `dead`) en dos sub-tabs, pero **`state = 'pending'` no cae en ninguno**.

Adicionalmente, `cancelJob()` (línea 167) y `dispatchNow()` (línea 743) **rechazan explícitamente** `state = 'pending'`, por lo que las filas en ese estado están no solo invisibles, sino también **inaccionables**. Solo `destroy()` puede borrarlas, y la UI no expone esa acción.

Y `stateClass()`/`stateDot()` (líneas 980-999 del controlador y 2010-2027 de la vista) no tienen entrada para `'pending'`, por lo que aún si se renderizara, usaría el color fallback indistinguible de `queued`.

Hay un caso adicional relevante: el endpoint `stats()` (línea 969) **ya devuelve** `$stats['local']` con `count(*) GROUP BY state`. El frontend lo carga vía `loadStats()` (línea 1176) pero **no lo muestra en ningún lado**. Está disponible pero inutilizado.

## Goals / Non-Goals

**Goals:**
- Hacer que `state='pending'` sea **visible** en la sub-tab "Pendientes" y **accionable** (Enviar ahora / Cancelar / Borrar).
- Mostrar contadores por estado en algún lugar visible de la UI para que la discrepancia "UI dice 0, BD tiene N" sea detectable de un vistazo.
- Establecer las bases para diagnosticar por qué se acumulan filas en `state='pending'` (endpoint de stats mejorado + script de diagnóstico, no fix del scheduler).

**Non-Goals:**
- NO se modifica el comando `transcription:scan-and-submit`, `ConvertAndTranscribeJob`, ni el worker Redis. La causa-raíz del no-consumo queda fuera de scope (será un change aparte si se confirma).
- NO se renombra `state='pending'` ni se cambia el modelo `Transcription`.
- NO se cambian los estados existentes `queued|processing|done|error|dead` ni su semántica.
- NO se agregan permisos nuevos.

## Decisions

### Decisión 1: Clasificación de UI por estado

Adoptar la siguiente taxonomía explícita en el frontend:

| Estado BD | Sub-tab "Pendientes" | Sub-tab "Completados" | Acción principal | Acción secundaria |
|---|---|---|---|---|
| `pending` (sin `job_id`) | ✅ | ❌ | "Enviar ahora" (síncrono) | "Borrar" (DELETE local) |
| `queued` (con `job_id`) | ✅ | ❌ | "Refrescar estado" | "Cancelar" |
| `processing` (con `job_id`) | ✅ | ❌ | "Refrescar estado" | "Cancelar" |
| `done` | ❌ | ✅ | "Reprocesar" | "Ver detalle" |
| `error` | ❌ | ✅ | "Reintentar" | "Ver detalle" |
| `dead` | ❌ | ✅ | "Reintentar" | "Ver detalle" |

**Por qué**: el cambio actual es una regresión silenciosa donde solo 4 estados son visibles (queued/processing/done/error/dead). El estado `pending` es el que más importa al admin porque representa trabajo NO enviado — exactamente lo que significa "pendiente" en lenguaje natural.

### Decisión 2: Permitir `dispatchNow` sobre `state='pending'`

`dispatchNow()` (línea 743) tiene un guard que rechaza todo lo que no sea `queued|processing`. Esto es incorrecto: una `Transcription` en `state='pending'` sin `job_id` es justamente la candidata obvia para "Enviar ahora".

**Cambio**: extender el guard a `[STATE_PENDING, STATE_QUEUED, STATE_PROCESSING]`. La lógica interna ya maneja correctamente el caso "no `job_id`" — borra la fila y recrea vía `firstOrCreate` (líneas 769-782). Sin embargo, hay un caso especial: si la fila ya está en `pending`, **no hace falta borrarla y recrearla** — basta con llamar `submit()` directamente. Se introduce un branch:

```php
if ($job->state === STATE_PENDING) {
    // ya existe la fila pending, solo enviamos
    $result = app(TranscriptionSubmitService::class)->submit($job);
} else {
    // rama actual: borrar y recrear (mantiene comportamiento existente)
    ...
}
```

**Por qué**: si hacemos "delete + firstOrCreate" sobre una fila `pending`, el `firstOrCreate` la va a RECUPERAR (no hay riesgo de duplicar), pero el `delete` deja una ventana donde otro worker podría leer la fila. Mejor rama explícita.

### Decisión 3: Permitir `cancelJob` sobre `state='pending'`

`cancelJob()` (línea 167) rechaza todo lo que no sea `queued|processing`. Para `pending` no tiene sentido cancelar en la API externa (no hay `job_id`), pero **sí tiene sentido borrarla localmente** porque está ocupando cupo y nunca se envió.

**Cambio**: extender el guard a `[STATE_PENDING, STATE_QUEUED, STATE_PROCESSING]`. En la rama `pending`, saltar el bloque de "cancel upstream" y hacer `DELETE` directo. Devolver mensaje diferenciado.

**Por qué**: es el complemento natural del Decisión 2. Si una fila `pending` se queda huérfana (scheduler no la recogió), el admin debe poder deshacerse de ella sin tener que pedir soporte.

**Alternativa considerada**: usar `destroy()` (línea 200) que ya existe. Descartado porque confunde semánticamente "cancelar" (acción del usuario sobre su propio job) con "borrar registro" (admin/debug).

### Decisión 4: Contadores por estado visibles en la UI

Hoy `stats()` ya devuelve `$stats['local']` (línea 974: `count GROUP BY state`), pero la UI no lo muestra. Decisión: **mostrar el desglose en el header de la sub-tab Trabajos**, justo al lado de los badges existentes (`jobsPendingCount`, `jobsCompletedCount`).

Renderizado propuesto (en `index.blade.php`, cerca de la línea 578, donde están los sub-tabs Pendientes/Completados):

```
[ Pendientes (47) ]  [ Completados (318) ]       ┌─ Por estado BD ─┐
                                                    pending:    47  ●
                                                    queued:     12  ●
                                                    processing: 23  ●
                                                    done:      318  ●
                                                    error:       4  ●
                                                    dead:        1  ●
                                                    └──────────────┘
```

Esto resuelve la observación del admin de un vistazo: si dice "Pendientes 0" pero el desglose muestra "pending: 47", la discrepancia es inmediata y accionable.

### Decisión 5: Endpoint de diagnóstico `transcriptor:diagnose-pending`

Nuevo comando artisan `transcriptor:diagnose-pending` (en `app/app/Console/Commands/`) que lista todas las filas en `state='pending'` con:
- `id`, `file_id`, `original_name`, `state`
- `created_at` (hace cuánto se creó la fila)
- `started_at`
- `storage_provider_id` (join con `files`)
- `priority` (join con `storage_providers.transcription_priority`)
- `job_id` (si lo tiene — si tiene job_id pero state=pending, hay un bug grave)

Output: tabla CLI coloreada + opción `--json` para piping.

**Por qué no se mete dentro del módulo web**: el admin puede no querer instalar nada; el diagnóstico Tinker/CLI es inmediato y reversible.

**Alternativa considerada**: hacerlo solo diagnóstico via Tinker uno-shot. Descartado porque queremos que la próxima vez que pase, sea un comando memorizable.

### Decisión 6: NO tocar el scheduler en este change (explícito)

La acumulación de filas en `pending` es síntoma probable de un bug en el pipeline (`scan-and-submit`, `BulkDispatchTranscriptionJob`, o el worker). **Este change solo expone y diagnostica**. El fix del scheduler queda para un change separado que solo se abre **después** de correr `transcriptor:diagnose-pending` y entender la causa.

Razón: si tocamos tres cosas a la vez (UI + scheduler + acciones), no sabremos qué arregló qué. Disciplina de scope.

## Risks / Trade-offs

- **[Riesgo] Carrera entre scheduler y "Enviar ahora" manual** → Un usuario hace clic en "Enviar ahora" sobre una fila `pending` justo cuando el scheduler la recoge. Mitigación: `TranscriptionSubmitService::submit()` ya es idempotente ante `state='pending'` (lo verifica internamente) y el `dispatchNow` ahora propuesto corre síncronamente, así que la carrera se resuelve "el último gana". Si hay doble envío, el segundo verá `job_id` y entrará en la rama `already_submitted`.

- **[Riesgo] Romper la action bar bulk-dispatch** → `dispatchableJobs()` (línea 1248) y `dispatchableJobsCount()` (línea 1251) filtran por `queued|processing`. Si agregamos `pending` a esos getters, el botón "Procesar N pendientes ahora" en bulk cambiará comportamiento y enviará también filas `pending` vía bulk. Decisión: **NO modificar esos getters**; el bulk encola vía cola Redis (no síncrono como dispatchNow), y queremos validación humana caso por caso para `pending`. Los getters se quedan en `queued|processing`, y el botón sigue significando "lo que se envió y necesita refresh / re-dispatch upstream".

- **[Riesgo] Cambiar `cancelJob` abre puerta a borrado accidental** → Si un usuario hace clic en "Cancelar" sobre una `pending` por error, se borra sin pedir confirmación. Mitigación: la UI ya pide confirmación en `cancelJob()` del frontend (buscar `confirm(` cerca de la línea de llamada), validar que se mantenga.

- **[Trade-off] Mostrar 6 contadores en header puede ser ruidoso** → Si la mayoría del tiempo solo importa `done`/`pending`, los otros 4 estados saturan. Mitigación: el panel es colapsable (`<details>`) y solo se expande al clic. El badge "Pendientes N" ya resume los más relevantes.

- **[Riesgo] `dispatchNow` sobre `pending` puede tardar >30s y colgarse desde el navegador** → Ya hay `timeout: 600000` en el frontend (línea 1412). No introducir nuevo riesgo.

- **[Riesgo] La investigación descubre que NO hay bug aguas abajo, sino comportamiento esperado** → Posible. Entonces este change simplemente mejora UX sin necesidad de abrir change adicional. Aceptable.

## Migration Plan

No requiere migración de BD. Despliegue estándar:

1. Merge → deploy normal (rsync / git pull en servidor + `php artisan view:clear`).
2. No requiere restart de PHP-FPM ni de supervisord (cambios son Blade + 1 método de controller + 1 comando nuevo).
3. Verificación manual con admin en `/ia/api-transcriptor`:
   - Si hay `pending` huérfanas en BD, ahora aparecen en la sub-tab "Pendientes" y los contadores del header cuadran.
   - Botones "Enviar ahora" / "Borrar" / "Cancelar" presentes según tabla Decisión 1.
4. Rollback: `git revert` + deploy. No requiere limpieza de BD.

## Open Questions

- **P1**: ¿Hay otras rutas además de `/ia/api-transcriptor/jobs/{id}/cancel` que asuman `state in {queued, processing}`? El grep en controller no muestra más, pero antes de mergear buscar también en `app/app/Console/Commands/`.
- **P2**: ¿El `transcriptor:diagnose-pending` debe poder ejecutar `--fix` (borrar automáticamente filas con `started_at < $threshold` y `state='pending'`)? Decisión: NO en este change. Sería útil pero queremos entender causa raíz primero.
- **P3**: ¿La acción "Borrar" sobre `pending` debe hacer soft-delete (columna `deleted_at`) o hard-delete? Decisión propuesta: **hard-delete** porque `Transcription` no tiene columna `deleted_at` en migraciones vistas. Verificar con `php artisan migrate:status` durante implementación.
- **P4**: ¿Vale la pena agregar un webhooks/state-change listener que cuando `state='pending'` lleva >N minutos emita un log/warning? Decisión: fuera de scope, solo flag en `diagnose-pending`.
