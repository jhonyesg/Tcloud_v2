# expose-all-transcriptor-settings-in-config-ui

## Why

El schema de `TranscriptorSettings` declara **54 settings** agrupados en 10 grupos (`ritmo, descubrimiento, confiabilidad, api, workers, ui, ia, burst, webhook, saturacion`). La UI de Configuración (`resources/views/ia/api-transcriptor/index.blade.php`) solo renderiza **6 grupos** en su `cfgGroupsOrder`, dejando **14 settings ocultos**:

- 4 de `saturacion` (idempotency, circuit breaker, backoff).
- 4 de `ia` (coherence pass con LLM).
- 4 de `burst` (dispatcher manual de ráfaga).
- 2 de `webhook` (callback_url entrante).

Estos 14 settings **sí funcionan** (cada uno tiene al menos un reader en código de producción), pero el operador no los puede tunear desde la UI. Tiene que ir a `.env` o `system_settings` por BD, lo cual es opaco y propenso a errores. La conversación del 2026-09-15 documentó esta confusión y el operador pidió que todo fuera editable desde la UI.

## What Changes

`resources/views/ia/api-transcriptor/index.blade.php`:

- Extender `cfgGroupsOrder` para incluir `saturacion, burst, webhook, ia` (entre los grupos ya presentes, en orden narrativo para el operador).
- Agregar labels legibles en `cfgGroupLabels` para los 4 grupos nuevos.
- Agregar helps honestos en `cfgGroupHelps` que describan **a quién afecta cada grupo** (incluyendo la advertencia de que `burst` solo aplica si se ejecuta manualmente el comando, no está en el cron).

`TranscriptorSettings`, `config/transcriptor.php`, `routes/console.php`: **no se tocan**. La pieza faltante era exclusivamente el render del UI.

## Capabilities

### New Capabilities

Ninguna. No introduce comportamiento nuevo; solo expone configuración existente.

### Modified Capabilities

Ninguna. La lógica de los 14 settings no cambia; lo que cambia es su visibilidad.

## Non-goals

- NO se borran settings de schema. Los 14 ya están activos; siguen funcionando igual.
- NO se reordena el schema. Solo el orden de presentación en el UI.
- NO se modifica el comportamiento de ningún setting.
- NO se introduce selector de "grupo oculto" / collapsible: todos los grupos se muestran siempre que tengan al menos un setting (eso ya lo hace el `cfgGroups()` actual).
- NO se hace commit del cambio de UI en este proposal. Se hace durante la fase apply, después de validar la propuesta.

## Impact

**Archivos modificados (1):**

- `app/resources/views/ia/api-transcriptor/index.blade.php`: 3 arrays JS (~30 líneas).
  - `cfgGroupsOrder`: de 6 grupos a 10 grupos.
  - `cfgGroupLabels`: +4 entries nuevas.
  - `cfgGroupHelps`: +4 entries nuevas.

**APIs / rutas / modelos**: ninguno.

**Migraciones de BD**: ninguna.

**Riesgo**: muy bajo. Cambios son de UI/Blade. Validación con `php -l` y un smoke test en `/ia/api-transcriptor`.

**Rollback**: `git revert <commit>`. Sin estado persistente.
