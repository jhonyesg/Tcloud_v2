## Context

El change actual parte del capability ya archivado `corrections-apply-retroactive-runner` (CLI binary, liveness ping, stuck detector, log con prefijo). Hoy `CorreccionesController::applyRetroactive()` sólo entiende `days_back` como scope temporal y aplica TODAS las approved sin filtro. La tabla Aprobadas ya tiene un set `approvedSelectedIds` (patrón bulk moderation, blade línea 290, 2458) que reusaremos para la selección.

El admin que aprueba una regla nueva y quiere probarla en histórico reciente termina eligiendo entre:
- `days_back=1` (toca todos los segmentos de hoy con todas las 2495 reglas — overkill)
- Lanzar sin filtro (tarda horas)
- No probar contra histórico (deja la regla sin validación retroactiva)

La granularidad por horas + filtro por corrección cierra ambos casos.

## Goals / Non-Goals

**Goals:**
- Time scope mixto horas/días: 1h, 8h, 1d, 3d, 7d, 14d, 30d, all.
- Filtro opcional por `correction_ids` desde UI (selectedIds de Aprobadas) o CLI (`--correction-id=` repetible).
- Preview de impacto antes de lanzar (segmentos en alcance + reglas a aplicar + ETA estimado).
- Backward-compat: `days_back` legacy sigue funcionando sin cambios.
- Preview NO dispara worker ni escribe cache de run.

**Non-Goals:**
- Filtros por `source` (`ai-context-correct`, `manual`, etc.) — fuera de scope.
- Selección de correcciones en el modal AI Suggest — fuera de scope.
- Cambios al motor de matching o al orden `LENGTH(wrong_normalized) DESC`.
- ETA dinámico basado en la velocidad real del worker — usamos heurística conservadora.
- Refactor a deep module del flujo correcciones — alcance incremental.

## Decisions

### 1. Reemplazar `days_back` por `since` (timestamp ISO) en el payload

```php
// applyRetroactive():
$sinceInput = $request->input('since');      // ISO 8601 opcional
$daysBackInput = $request->input('days_back'); // legacy

if ($sinceInput) {
    $since = Carbon::parse($sinceInput);
} elseif ($daysBackInput !== null && $daysBackInput !== '' && $daysBackInput !== 'all') {
    $since = now()->subDays((int) $daysBackInput);
} else {
    $since = null; // todos
}
```

**Rationale:** `since` es unit-agnostic (puede ser horas, minutos, días). Internamente la query sigue siendo `created_at >= since` — misma lógica, sin cambios en BD. Backward-compat: callers que manden `days_back` siguen funcionando.

**Alternatives considered:**
- Agregar `hours_back` separado: duplicación de parámetros, más confusión.
- Re-nombrar `days_back` a `since_minutes`: cambia contrato del CLI existente.

### 2. UI del dropdown con selector mixto horas/días

```html
<select x-model="applyScope">
  <optgroup label="Horas">
    <option value="1h">Última hora</option>
    <option value="8h">Últimas 8 horas</option>
  </optgroup>
  <optgroup label="Días">
    <option value="1d">Último día</option>
    <option value="3d" selected>Últimos 3 días</option>
    ...
  </optgroup>
  <option value="all">Todos los históricos</option>
</select>
```

La transformación al payload la hace el front (JS): `1h → since = now - 1h`, `3d → since = now - 3d`, `all → since = null`.

**Rationale:** Optgroup es nativo, sin librerías. La conversión es trivial en JS. La consistencia con `quickActionWindows` (botones 1d/3d/7d del header) se mantiene.

### 3. Radio "Aplicar a todas" vs "Aplicar solo a N seleccionadas"

```html
<label><input type="radio" x-model="applyMode" value="all"> Aplicar todo el diccionario (2495)</label>
<label x-show="approvedSelectedIds.size > 0">
    <input type="radio" x-model="applyMode" value="selected">
    Aplicar solo las seleccionadas (<span x-text="approvedSelectedIds.size">0</span>)
</label>
```

El botón "Confirmar y aplicar" se deshabilita si `applyMode='selected' && approvedSelectedIds.size === 0`.

**Rationale:** Reusa el set `approvedSelectedIds` que ya existe en la tabla (no requiere nueva UI de selección). Mensaje contextual con conteo. Si el admin no seleccionó nada en Aprobadas, la opción "solo seleccionadas" no se muestra.

### 4. Validación server-side de correction_ids

```php
$request->validate([
    'correction_ids' => 'sometimes|array|max:2495',
    'correction_ids.*' => 'integer|min:1',
]);
// Después de validar:
if (!empty($correctionIds)) {
    $existing = Correction::approved()
        ->whereIn('id', $correctionIds)
        ->pluck('id')
        ->all();
    $missing = array_diff($correctionIds, $existing);
    if (!empty($missing)) {
        return response()->json([
            'error' => 'IDs no encontrados o no aprobados: ' . implode(',', $missing),
        ], 422);
    }
}
```

**Rationale:** No confiamos en IDs del cliente sin revalidar — un admin podría tener una corrección que cambió de status entre que la seleccionó y la envió. 422 con mensaje claro es mejor que un run silenciosamente parcial.

### 5. Endpoint de preview compartido con la query del worker

```php
public function previewApplyRetroactive(Request $request) {
    [$since, $correctionIds] = $this->resolveScope($request);
    $segmentsTotal = $this->countSegmentsInScope($since);
    $correctionsTotal = empty($correctionIds)
        ? Correction::approved()->count()
        : count($correctionIds);
    return response()->json([
        'segments_total' => $segmentsTotal,
        'corrections_total' => $correctionsTotal,
        'estimated_minutes' => $this->estimateMinutes($segmentsTotal, $correctionsTotal),
    ]);
}
```

`$this->estimateMinutes()` usa una heurística conservadora: `max(1, ceil(segmentsTotal / 5000))` minutos. 5000 segments/min es conservador para 2495 reglas; con menos reglas es más rápido, pero el cap conservador informa al admin.

**Rationale:** El preview ejecuta un `COUNT(*)` sobre `transcription_segments` filtrado por `created_at >= since`. Es una query indexada (no es full-scan), tarda < 200ms para 585k segmentos. NO escribe cache, NO lanza worker.

### 6. CLI flag repetible para simetría con UI

```bash
php artisan corrections:apply-run --run-id=X --correction-id=10516 --correction-id=10511 --since=2026-09-06T20:00:00Z
```

Mapeo:
- `--correction-id=` se acumula en array (Laravel Command options con `*` suffix).
- `--since=` ISO 8601, parseado vía Carbon.

## Risks / Trade-offs

- **Migración de la UI**: la opción default cambia de "7d" a "3d". Cambio mínimo pero merece nota en el release notes.
- **Preview mal estimado**: si el admin confía en el ETA y la corrida tarda 5× más por BD lenta, se frustra. Mitigación: ETA usa un piso generoso (5000 seg/min, no 20000) + el banner existente "Sin avances desde las 10:20 p.m." ya alerta de stuck.
- **Selected ids stale**: si el admin selecciona una corrección que se rechaza entre que la seleccionó y la envía → 422 claro. UX suficientemente robusta.
- **`correction_ids` huge**: cap a 2495 (max approved). El LIMIT en validate ya lo cubre.

## Migration Plan

Sin migración de BD. Rollout:
1. **Pre-deploy**: en staging, correr preview con scope `1h` y comparar contra `SELECT COUNT(*)` directo.
2. **Deploy**: merge + deploy.
3. **Verificación post-deploy**:
   - Re-aplicar con `1h` y "todo el diccionario" → debe terminar en segundos.
   - Re-aplicar con `8h` y 3 seleccionadas → debe terminar en segundos y aplicar sólo esas 3.
   - Preview en ambos casos → números coherentes con el worker.
4. **Rollback**: revert del merge. El endpoint legacy sigue funcionando (days_back).
