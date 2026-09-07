## Why

El botón **Re-aplicar correcciones** ofrece hoy un único eje de scope — alcance temporal en días enteros (1/3/7/14/30/90/all) — y siempre aplica **todas** las 2495 correcciones aprobadas a los segmentos del alcance. Para el caso real del admin (probar una regla recién aprobada sobre segmentos de hoy, sin tocar el corpus histórico de 585k segmentos), esto significa esperar horas sin necesidad o, peor, arriesgarse a tocar transcripciones viejas con reglas inmaduras.

El admin también carece de confirmación de impacto antes de lanzar: el modal muestra "0 / 585.240 segmentos" engañoso (siempre 0 hasta que el worker reporta el primer chunk). Si se le da a "Confirmar y aplicar" con alcance "todos los históricos", el proceso queda corriendo horas sin que el admin sepa cuántas reglas se aplicarán ni cuántas ya están desactualizadas.

## What Changes

- **Granularidad temporal mezclada (horas + días)**: el dropdown pasa a ofrecer `1h, 8h, 1d, 3d, 7d, 14d, 30d, all`. Internamente, `days_back` se reemplaza por un parámetro `since` (timestamp ISO) que se computa desde el valor del dropdown, evitando la imprecisión de "1 día" para reglas probadas minutos atrás.
- **Selección de correcciones opcional**: nuevo radio button en el modal Re-aplicar con dos opciones:
  - **Aplicar todo el diccionario aprobado** (comportamiento actual, default)
  - **Aplicar solo las correcciones seleccionadas** (X), donde X viene del set `approvedSelectedIds` que ya existe en la tabla Aprobadas
- **Filtro `correction_ids`**: el endpoint acepta `correction_ids: int[]` (validado 1-2495 ids existentes y con `status='approved'`). El comando artisan acepta el flag repetible `--correction-id=` para que el flujo CLI siga siendo simétrico al de UI.
- **Preview antes de lanzar**: nuevo botón "Vista previa" en el modal que muestra conteos sin aplicar — cuántos segmentos caerían en el alcance, cuántas reglas se aplicarían, y un ETA estimado según el chunk size y la última corrida equivalente. Llama a un endpoint nuevo `POST /ia/correcciones/apply-retroactive/preview`.
- **Sin breaking change para la API actual**: el endpoint existente `POST /ia/correcciones/apply-retroactive` mantiene compatibilidad — los nuevos parámetros son opcionales y con defaults equivalentes al comportamiento histórico.

## Capabilities

### Modified Capabilities
- `corrections-apply-retroactive-runner`: Se agregan requirements de scope (time granularity mezclada + per-correction filter) y preview de impacto. Los requirements previos (CLI binary, liveness ping, stuck detector, log tag) NO cambian — sólo se extienden.

## Impact

- **Código**:
  - `app/app/Http/Controllers/Ia/CorreccionesController.php::applyRetroactive()` — aceptar `correction_ids` y `since` (en vez de sólo `days_back`); nuevo método `previewApplyRetroactive()` para el dry-run client-side.
  - `app/app/Console/Commands/CorrectionsApplyRunCommand.php` — agregar signature flag `--correction-id=` repetible; pasar el array al `CorrectionService::applyRetroactively()`.
  - `app/app/Services/Ia/CorrectionService.php::applyRetroactively()` — agregar parámetro `array $correctionIds = []`; si está vacío, usa TODAS las approved (backward-compat); si no, filtra `whereIn('id', ...)`.
  - `app/resources/views/ia/correcciones/index.blade.php` — modal Re-aplicar: dropdown de scope actualizado (1h, 8h, …), radio de selección, botón "Vista previa" con panel de impacto.
  - `app/routes/web.php` — nueva ruta `POST /ia/correcciones/apply-retroactive/preview`.
- **APIs/Routes**: extiende `POST /ia/correcciones/apply-retroactive` con parámetros opcionales; agrega `POST /ia/correcciones/apply-retroactive/preview`.
- **Migraciones**: ninguna (todo es scope filtering, no schema change).
- **Compatibilidad**: backward-compat con `days_back` legacy. Cliente CLI sin cambios sigue funcionando si no pasa `--correction-id`.
- **Rendimiento**: aplicar a 1-10 correcciones específicas sobre 1h es la combinación óptima para validar reglas recién aprobadas — typical 30s-2min vs horas del alcance completo.

## Non-goals

- No agregar filtros por `source` (ai-context-correct, manual, etc.) en esta iteración; queda como follow-up.
- No exponer la selección como filtro en el flujo de AI Suggest; eso es scope del modal de sugerencia.
- No cambiar el motor de matching (orden por `LENGTH(wrong_normalized) DESC`) ni el cache de pares.
- No re-arquitecturar el módulo correcciones como deep module (sigue siendo alcance incremental).
- No incluir un "ETA dinámico" que consulte la velocidad real del worker; el preview usa heurística conservadora basada en la última corrida histórica del mismo alcance.
