## Context

La columna `file_modified_at` fue añadida en la migración `2026_05_13_000003` y `StorageSyncService` la popula correctamente desde `filemtime()`. Sin embargo, `FileController::upload()` no la establece al crear el registro, dejando `NULL` para archivos subidos vía UI. El frontend usa `file_modified_at || created_at` como fallback, pero `created_at` es la fecha del upload/scan — no la fecha de modificación real del archivo, lo que hace incoherente el ordenamiento.

## Goals / Non-Goals

**Goals:**
- `file_modified_at` se popula en **todos** los caminos de creación de archivos
- La columna "Fecha" y el sort reflejan únicamente `file_modified_at`
- Archivos sin `file_modified_at` muestran `"—"` en lugar de una fecha engañosa

**Non-Goals:**
- No se modifica `StorageSyncService` (ya es correcto)
- No se hace backfill automático de registros existentes con `NULL`
- No se expone `created_at` en la UI de archivos

## Decisions

### 1. Leer `filemtime()` después del `move()` en upload

**Decisión:** Tras `$file->move($destDir, $filename)`, construir `$physicalPath` y llamar `filemtime()` para obtener la fecha de modificación real del archivo en disco.

**Alternativa descartada:** Usar `now()` como fecha — introduce el mismo problema que `created_at` (fecha de upload, no del archivo real).

**Alternativa descartada:** Usar la fecha del archivo original en origen vía `$file->getMTime()` (UploadedFile de Symfony) — puede devolver la fecha del archivo temporal en `/tmp`, no la del archivo original. `filemtime()` post-move es más confiable.

### 2. Sin fallback a `created_at` en el frontend

**Decisión:** En `sortedFiles()` y en las expresiones de display, usar únicamente `file_modified_at`. Si es `null`, se ordena como `0` (queda al fondo en ASC) y se muestra `"—"`.

**Alternativa descartada:** Mantener el fallback `|| created_at` — perpetúa la confusión semántica que causó el bug.

## Risks / Trade-offs

- **[Riesgo] Registros existentes con `file_modified_at = NULL`** → Aparecerán con `"—"` hasta el próximo rescan. Mitigación: el usuario puede hacer un rescan manual; `StorageSyncService` ya maneja la actualización correctamente.
- **[Trade-off] Archivos subidos antes de este fix** → Misma situación que arriba. El fix solo aplica a uploads futuros.
- **[Riesgo] `filemtime()` falla si el archivo no existe** → Se añade `file_exists()` guard; si falla, `file_modified_at = null` en lugar de romper el upload.
