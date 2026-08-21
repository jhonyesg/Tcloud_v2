# Change: exclusiones dinámicas (Black Friday, Open English, etc.) editables desde UI

## Why

La lista de marcas/exclusiones del AI suggest vive hoy en `config/llm-correction.php` como array literal (`protected_brands`). El admin pidió el 2026-08-01:

> *"necesito poner una parte donde ponga exclusiones ejemplo una exclusion seria esta palabra Black Friday"*

Concretamente: cuando surja un término que NO debe traducirse en ninguna circunstancia (ej. "Black Friday" como evento comercial, "Copa América", "Open English" como empresa, "San Valentín" como节日, etc.), el admin necesita poder agregarlo **sin tocar código ni redesplegar**.

Hoy el flujo es engorroso:
1. Editar `config/llm-correction.php` (file edit + ssh).
2. `php artisan config:clear`.
3. Esperar a que el siguiente deploy / cache TTL expire.

Costo de fricción ≈ 5–10 min por exclusión nueva + alto riesgo de typos porque la lista es parte del código de control del LLM. La solución: una tabla nueva `correction_protected_terms` editable desde `/ia/correcciones → IA Suggest → Exclusiones`, con cache de 5 min que el sugerir consulta en cada corrida.

El admin ya validó que **quiere lista separada y dedicada** (no mezclar con `protected_brands` de tech/medios), y **solo admin** edita (no cliente).

## What Changes

### 1. Migración nueva: `correction_protected_terms`

```
- id (bigint)
- term (string, único activo+archivado no, índice)
- category (string nullable) — ej: 'event', 'brand', 'product', 'org'
- notes (text nullable) — contexto / razón
- created_by (FK users.id)
- created_at, updated_at, archived_at (nullable soft delete)
```

Índices: `term` único por `archived_at IS NULL` (un término activo no puede estar duplicado), `category` para filtrar.

### 2. Modelo `App\Models\CorrectionProtectedTerm`

- Scopes `active()` (no archivados), `archived()`, `category($cat)`.
- Método estático `termsList(): array<string>` que retorna array plano de strings lowercased para usar directo en `looksLikeBrandOrProperNoun`.

### 3. Servicio `App\Services\Ia\CorrectionProtectedTermsService`

- `listAll(): array<{id, term, category, notes, created_by_username, created_at, archived_at}>` — para la UI.
- `add(string $term, ?string $category, ?string $notes, User $by): CorrectionProtectedTerm` — valida no-vacío, lowercase, único activo; tira 422 si duplicado.
- `archive(int $id): bool` — soft delete (setea archived_at).
- `restore(int $id): bool`.
- Cache layer: `Cache::remember('correction_protected_terms:active', 300, fn() => self::listAllFromDb())` — TTL 5 min.
- `protectedTerms(): array<string>` — array de strings para el filtro; cachea 5 min.

### 4. Integración con `LlmCorrectionSuggester::looksLikeBrandOrProperNoun`

- Mantener `protected_brands` (config) como la lista de marcas tech/hardware SIEMPRE activas.
- Añadir a la lista combinada los términos de `CorrectionProtectedTermsService::protectedTerms()` cuando el flag pasa a esa función.
- Modificación mínima del método: añadir un parámetro `array $extraTerms = []` o leer del service directamente. Decisión final en implementación: el service se inyecta al método.
- El prompt del LLM (`renderProtectedBrandsList()`) también incluye ambas listas para que la barrera 1 (prompt) y barrera 2 (post-filtro) cubran los mismos términos.

### 5. Endpoints nuevos en `CorreccionesController`

- `GET /ia/correcciones/protected-terms` — lista todas (activas + archivadas), cache invalidado.
- `POST /ia/correcciones/protected-terms` — agregar. Body: `{term, category?, notes?}`. Valida: term no vacío, único entre los activos. Devuelve 201 con la fila.
- `DELETE /ia/correcciones/protected-terms/{id}` — soft archive.
- `POST /ia/correcciones/protected-terms/{id}/restore` — re-activar.
- Invalidación de cache en cada mutación.

### 6. UI: subpanel "Exclusiones" en `/ia/correcciones → IA Suggest`

Bloque nuevo al final del panel IA Suggest, con:
- Tabla de términos activos (term / category / notes / created_by / created_at / botón Archivar).
- Botón "Agregar exclusión" → modal con: input term (autocomplete=false para evitar sugerir), select category (event / brand / product / org / otro), textarea notes.
- Banner informativo: "Estos términos NUNCA serán traducidos por AI Suggest. El filtro se aplica en el prompt del LLM y en el post-filtro PHP. Cambios toman efecto en ≤5 min en la siguiente corrida cache."

Búsqueda libre en la tabla (case-insensitive por term). Botón "Archivadas" para ver el historial soft-deleted y restaurar.

### 7. Spec

- 1 ADDED Requirement en `transcription-corrections`: "Admin puede agregar, archivar y restaurar exclusiones dinámicas desde `/ia/correcciones → IA Suggest → Exclusiones`, con efecto en ≤5 min en la próxima corrida (cache TTL)".

## Non-goals

- **No se borra `protected_brands` del config**. La lista existente (marcas tech/hardware) sigue siendo el piso mínimo siempre activo. Las exclusiones dinámicas son una capa adicional.
- **No se exponen las exclusiones a clientes**: solo admin.
- **No se versionan las exclusiones** (audit log de cambios): si el admin quiere saber qué agregó quién, lo ve en `created_by` + `created_at`. Historial exhaustivo queda como follow-up.
- **No se sincroniza con `corrections`**: una exclusión no genera una corrección approved; simplemente bloquea al LLM en el prompt y al post-filtro.
- **No hay auto-suggest desde análisis**: las exclusiones son 100% manuales (es lo pedido).
- **No hay ACL por usuario**: cualquier admin edita (los admins son un grupo chico y confiable; ACL por rol sería overkill).

## Impact

- **Specs affected**: `transcription-corrections` (1 ADDED).
- **Code affected (nuevos)**:
  - `app/database/migrations/2026_08_01_120000_create_correction_protected_terms_table.php`
  - `app/app/Models/CorrectionProtectedTerm.php`
  - `app/app/Services/Ia/CorrectionProtectedTermsService.php`
- **Code affected (modificados)**:
  - `app/app/Services/Ia/LlmCorrectionSuggester.php` (`looksLikeBrandOrProperNoun` consulta el service; `renderProtectedBrandsList` concatena ambas listas)
  - `app/app/Http/Controllers/Ia/CorreccionesController.php` (4 métodos: index, store, archive, restore)
  - `app/routes/web.php` (4 rutas)
  - `app/resources/views/ia/correcciones/index.blade.php` (subpanel Exclusiones + modal agregar + tabla búsqueda)
  - `openspec/specs/transcription-corrections/spec.md` (delta ADDED)
- **Migrations**: 1 nueva tabla (no toca `corrections`).
- **Riesgos**: bajo. La nueva lista es aditiva (concatena con `protected_brands`). Si se cae la tabla, el service tira excepción y `looksLikeBrandOrProperNoun` cae en fallback "solo `protected_brands`" — defenza en profundidad.
- **Costes**: cero. Cache 5 min evita el hit a la DB por corrida.

## Open questions (resueltas)

- **¿Quién puede agregar?** Admin (igual que el resto del módulo).
- **¿Cache TTL?** 5 minutos. Si el admin necesita que aplique YA, `php artisan cache:forget correction_protected_terms:active`.
- **¿Nombre de la lista?** "Exclusiones" (lo que usó el admin en su mensaje).
- **¿Case sensitivity?** El service guarda en lowercase y `looksLikeBrandOrProperNoun` ya hace `mb_strtolower` antes de comparar.
- **¿Soporte para frases multi-palabra?** Sí: "Black Friday", "San Valentín" — el filtro defensivo actual ya maneja sub-frases con `str_contains`.
- **¿Soporte para caracteres especiales del español?** Sí, el filtro usa `mb_strtolower` para ñ/á/é. "San Valentín" queda cubierto.
