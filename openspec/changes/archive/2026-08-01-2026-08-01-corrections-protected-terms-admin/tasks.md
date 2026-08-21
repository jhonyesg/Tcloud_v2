# Tasks: exclusiones dinámicas (Black Friday, etc.) editables desde UI

## 1. Migración

- [ ] Crear `app/database/migrations/2026_08_01_120000_create_correction_protected_terms_table.php`:
  - Columnas: `id BIGSERIAL`, `term VARCHAR(120) NOT NULL`, `category VARCHAR(32) NULL`, `notes TEXT NULL`, `created_by BIGINT NOT NULL` (FK `users.id` ON DELETE RESTRICT), `created_at TIMESTAMP NULL`, `updated_at TIMESTAMP NULL`, `archived_at TIMESTAMP NULL`.
  - Índices: `UNIQUE (term) WHERE archived_at IS NULL` (Postgres partial unique index), `(category)`, FK con `users(id) ON DELETE RESTRICT`.
  - `down()` dropa la tabla.
- [ ] `php artisan migrate` (producción ya ejecutará en el próximo deploy).

## 2. Modelo `CorrectionProtectedTerm`

- [ ] Crear `app/app/Models/CorrectionProtectedTerm.php`:
  - `$table`, `$fillable = ['term', 'category', 'notes', 'created_by']`.
  - `$casts = ['archived_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime']`.
  - Scopes: `scopeActive($q)`, `scopeArchived($q)`, `scopeCategory($q, $cat)`.
  - `createdBy(): BelongsTo User`.
  - Método estático `termsListActive(): array<string>` que retorna array plano de términos activos lowercased.

## 3. Servicio `CorrectionProtectedTermsService`

- [ ] Crear `app/app/Services/Ia/CorrectionProtectedTermsService.php`:
  - Cache key: `correction_protected_terms:active` con TTL 300s.
  - `terms(): array<string>` — array plano lowercased; cache 5min.
  - `listAll(): array<int, array<string, mixed>>` — lista paginada para UI (activas + archivadas), sin cache (o cache corto 60s).
  - `add(string $term, ?string $category, ?string $notes, User $by): CorrectionProtectedTerm`:
    - Trim + lowercase `$term`.
    - Validar no-vacío.
    - Verifica unicidad contra activos (UNIQUE constraint + chequeo explícito).
    - Crea con `created_by = $by->id`.
    - Forgot cache.
  - `archive(int $id): bool` — set `archived_at = now()`, forget cache.
  - `restore(int $id): bool` — set `archived_at = null`, forget cache.

## 4. Integración con `LlmCorrectionSuggester`

- [ ] En `app/app/Services/Ia/LlmCorrectionSuggester.php`:
  - Importar `CorrectionProtectedTermsService`.
  - `looksLikeBrandOrProperNoun($wrong)` además de leer `protected_brands` del config, también llama `$service->terms()` y los concatena en la lista a matchear.
  - `renderProtectedBrandsList()` concatena `protected_brands` ∪ términos dinámicos en la lista que se incluye en el system prompt.
- [ ] Inyectar `CorrectionProtectedTermsService` en el constructor del Suggester (o usar `app()` helper si no se inyecta).

## 5. Controller methods + rutas

- [ ] En `app/app/Http/Controllers/Ia/CorreccionesController.php`:
  - `protectedTermsIndex()` → JSON con activas + archivadas.
  - `protectedTermsStore(Request $request)` → valida con Laravel Validator; 201 con fila creada, 422 si duplicada o vacío.
  - `protectedTermsArchive(int $id)` → 204 No Content; 404 si no existe.
  - `protectedTermsRestore(int $id)` → 204 No Content.
- [ ] En `app/routes/web.php`:
  - `Route::get('/correcciones/protected-terms', [..., 'protectedTermsIndex'])`
  - `Route::post('/correcciones/protected-terms', [..., 'protectedTermsStore'])`
  - `Route::delete('/correcciones/protected-terms/{id}', [..., 'protectedTermsArchive'])`
  - `Route::post('/correcciones/protected-terms/{id}/restore', [..., 'protectedTermsRestore'])`
- [ ] `php -l` validar ambos archivos.

## 6. UI: subpanel "Exclusiones"

- [ ] En `app/resources/views/ia/correcciones/index.blade.php`:
  - Bloque nuevo al final del panel `tab === 'ai-settings'`, dentro de un contenedor purple-light (consistente con el resto del panel).
  - Header: "Exclusiones (palabras que el AI Suggest nunca va a traducir)" + botón "Agregar exclusión".
  - Tabla: term / category / notes / created_by.username / created_at / botón Archivar.
  - Búsqueda libre `<input type="search">` filtrando por `term`.
  - Botón toggle "Mostrar archivadas" → muestra archivadas con columna `archived_at` y botón Restaurar.
  - Modal "Agregar exclusión": input term + select category + textarea notes + botón Guardar. POST al endpoint store; en 201 refresca la lista.
- [ ] Alpine state: `exclusiones: []`, `exclusionesLoading: false`, `exclusionesSearch: ''`, `showExcluirModal: false`, `excluirForm: {term: '', category: '', notes: ''}`, `excluirShowArchived: false`.
- [ ] Métodos Alpine: `loadExclusiones()`, `submitExclusion()`, `archiveExclusion(id)`, `restoreExclusion(id)`.

## 7. Cache-bust en AI suggest

- [ ] Confirmar que el cache del service (`correction_protected_terms:active`) NO cause falsos negativos críticos. Documentar en código: "TTL 5min. Para aplicar YA después de agregar/archivar, ejecuta `php artisan cache:forget correction_protected_terms:active`".

## 8. Verificación end-to-end

- [ ] `php artisan migrate` (o confirmar que se aplique en deploy).
- [ ] Smoke backend: `php artisan tinker`-equivalente o un mini-script que:
  - Cree "black friday" en `correction_protected_terms`.
  - Llame `LlmCorrectionSuggester::looksLikeBrandOrProperNoun('Black Friday')` → expect `true`.
  - Llame `->looksLikeBrandOrProperNoun('a black friday sale')` → expect `true` (sub-frase).
  - Archive la fila; cache TTL 5min — para test inmediato: `Cache::forget('correction_protected_terms:active')`.
  - Re-llame; debería retornar `false` (ya no está en la lista).
- [ ] UI: en `/ia/correcciones → IA Suggest`, abre el panel "Exclusiones", agrega "Black Friday", verifica que aparece, archivá, verifica que sigue en lista de archivadas, restaura.

## 9. Spec delta

- [ ] Append al spec canónico `openspec/specs/transcription-corrections/spec.md`: 1 ADDED Requirement "Admin puede gestionar exclusiones dinámicas".

## 10. Archivar

- [ ] Mover el change a `openspec/changes/archive/2026-08-01-2026-08-01-corrections-protected-terms-admin/`.
