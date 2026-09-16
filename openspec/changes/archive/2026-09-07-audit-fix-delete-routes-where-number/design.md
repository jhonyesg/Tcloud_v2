## Context

Auditoría de rutas DELETE en `app/routes/web.php`. La revisión se hizo tras el fix del bug `/correcciones/{id}` donde la falta de `whereNumber('id')` causaba `TypeError: must be of type int, string given` (logs 2026-09-06 11:34:40 y 11:37:28).

## Goals / Non-Goals

**Goals:**
- Cerrar 4 rutas DELETE más que tienen el mismo patrón vulnerable.
- Cambio de 4 líneas en `routes/web.php`.

**Non-goals:**
- Auditar PATCH/PUT (sin reportes en logs).
- Auditar rutas con route-model binding.

## Decisions

### D1. Aplicar `whereNumber` a las 4 rutas identificadas

| Ruta | Constraint |
|---|---|
| `/papelera/{file}` | `->whereNumber('file')` |
| `/api-transcriptor/jobs/{id}` | `->whereNumber('id')` |
| `/avisos-inteligentes/{userId}/keywords/{keywordId}` | `->whereNumber('userId')->whereNumber('keywordId')` |
| `/mis-avisos/keywords/{keywordId}` | `->whereNumber('keywordId')` |

**Por qué:** idéntica al fix que ya hicimos para `/correcciones/{id}`. Defensa en profundidad a nivel de routing — Laravel rechaza el request antes de invocar el controller.

**Alternativa descartada:**
- Convertir params a `int|string` en el controller para hacer un cast defensivo: agrega ruido a cada método sin mejorar la experiencia del cliente (igual ve 500 si el cast falla).
- Validar dentro del controller con `is_numeric($id)`: re-inventa lo que `whereNumber` ya hace a nivel de routing.

## Risks / Trade-offs

- **[Trade-off] Consistencia de URLs** → si algún cliente actual envía un id con formato legacy (ej: `id-123`), ahora recibe 404 en vez de 500. Es semánticamente más correcto, pero técnicamente cambia el código de error.
- **[Riesgo bajo] Compatibilidad** → los IDs en BD son siempre numéricos; no hay riesgo de romper clientes legítimos.

## Migration Plan

### Deploy
1. `git pull` (toma las 4 modificaciones).
2. Smoke test: hacer DELETE con id no numérico en cada ruta → debe dar 404.

### Rollback
- `git revert <commit>`.

### Post-deploy verification
- curl desde shell: `DELETE /papelera/abc`, `DELETE /api-transcriptor/jobs/abc`, etc. → todos 404.
- Playwright opcional.

## Open Questions

Ninguna.
