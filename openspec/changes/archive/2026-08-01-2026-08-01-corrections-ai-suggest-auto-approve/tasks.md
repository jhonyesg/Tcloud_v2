# Tasks: AI suggest auto-aprueba + búsqueda libre en pending/approved

## 1. Filtro defensivo: extender lista `protected_brands`

- [ ] En `app/config/llm-correction.php`:
  - Agregar a la lista `'protected_brands'`:
    - Empresas de enseñanza de inglés mencionadas por el admin: `'open english'`, `'openenglish'`, `'ef education first'`, `'british council'`.
    - Empresas hispanas de noticieros que han pedido no traducir: `'epm'`, `'isa'`, `'grupo argos'`, `'nutresa'`.
  - Mantener la lista anterior intacta para no romper el flujo en producción.
- [ ] `php artisan config:clear` (importante: la lista se carga en runtime via `config()` en cada request, pero la cache de config puede tener la lista vieja).
- [ ] Smoke test: `php artisan tinker` (o un script throwaway) que llame `LlmCorrectionSuggester::looksLikeBrandOrProperNoun('Open English')` y confirme que retorna `true`. (Si tinker no está disponible, hacer un test rápido con `php -r`).

## 2. Settings: nueva key `auto_approve`

- [ ] En `app/app/Services/Ia/LlmCorrectionSettings.php`:
  - Agregar al array `SCHEMA` la key:
    ```php
    'auto_approve' => [
        'type' => 'bool', 'default' => true,
        'label' => 'Auto-aprobar correcciones del AI Suggest',
        'help' => 'Si true, el suggester inserta con status=approved en lugar de pending. El filtro defensivo de marcas sigue aplicando. Apágalo desde aquí si quieres revisar a mano antes de aprobar.',
    ],
    ```
  - Verificar `bool()` ya existe y funciona (ya verificado previamente en `2026-08-01-corrections-ai-suggest-scheduled`).
- [ ] En `app/database/seeders/*` o similar (si existe): no sembrar nada para esta key. El default `true` en `SCHEMA` ya la cubre cuando el admin aún no configuró.

## 3. Service: auto-approve en `aiSuggestEnEsMix`

- [ ] En `app/app/Services/Ia/CorrectionService.php`:
  - Cambiar firma: `aiSuggestEnEsMix(int $days, int $sampleSize, User $by, bool $autoApprove = false): array`.
  - Dentro del loop de inserción:
    - Si `$autoApprove` y NO existe approved para ese `wrong_normalized` (chequeo anti-duplicado extra):
      - Crear `Correction` con `status='approved'`, `approved_by=$by->id`, `approved_at=now()`, `source=$source`, `wrong_text`, `correct_text`, `wrong_normalized`, `proposed_by=$by->id`.
      - Incrementar `inserted` y `auto_approved`.
    - Si `$autoApprove` y YA existe approved → omitir (contar en `skipped_duplicate`).
    - Si NO `$autoApprove`: comportamiento actual (propose → pending).
  - Añadir `auto_approved` al array de retorno.
- [ ] `php -l` validar.

## 4. Helper reusable: `upsertApprovedBySystem`

- [ ] En `app/app/Services/Ia/CorrectionService.php`:
  - Crear método público `upsertApprovedBySystem(User $by, string $wrong, string $correct, string $source): Correction` que inserta con status=approved respetando las mismas reglas de idempotencia que `propose()` (skip si approved existente, etc.).
  - Cobertura de tests: archivo `app/tests/Feature/AutoApproveCorrectionServiceTest.php` con dos casos: insert nuevo y skip si ya existe approved para el mismo `wrong_normalized`.

## 5. Comando: flag `--auto-approve` + lectura de settings

- [ ] En `app/app/Console/Commands/AiSuggestEnEsCorrectionsCommand.php`:
  - Agregar al signature: `{--auto-approve : Inserta con status=approved en lugar de pending (default lee LlmCorrectionSettings::bool('auto_approve'))}`.
  - En `handle()`: `$autoApprove = (bool) $this->option('auto-approve') ?: $settings->bool('auto_approve');`.
  - Pasar a `aiSuggestEnEsMix(... autoApprove: $autoApprove)`.
  - Log inicial extendido: `AI suggest EN↔ES: days=... sample=... model=... auto_approve=true|false`.
  - Log final extendido: si auto-approve, línea extra `Auto-approved: N`.
- [ ] `php -l` validar.
- [ ] Verificar `php artisan corrections:ai-suggest --help`.

## 6. Controller: nuevo endpoint `approved()` (AJAX) + pasar `auto_approve` al UI

- [ ] En `app/app/Http/Controllers/Ia/CorreccionesController.php`:
  - Nuevo método público:
    ```php
    public function approved() {
        return response()->json(
            Correction::approved()
                ->with('proposedBy:id,username', 'approvedBy:id,username')
                ->orderByDesc('applies_count')
                ->get()
        );
    }
    ```
  - Modificar los métodos de `aiSuggestSettings*` para que `LlmCorrectionSettings` envíe `auto_approve` en la respuesta JSON.

## 7. Rutas

- [ ] En `app/routes/web.php`: agregar `Route::get('/correcciones/approved', [CorreccionesController::class, 'approved']);` agrupada con las demás `/correcciones/*`.

## 8. UI: reescritura de tabla approved a AJAX + búsqueda libre en pending y approved

- [ ] En `app/resources/views/ia/correcciones/index.blade.php`:
  - **Tab "Pendientes"** (L177-231):
    - Agregar `<input type="search">` arriba del filtro source, al lado. Wire a `searchTerm` en Alpine.
    - Modificar getter `pendingFiltered` (buscar `c.source === this.sourceFilter` cerca de línea 1200) para incluir filtro por texto:
      ```js
      get pendingFiltered() {
          const t = (this.searchTerm ?? '').trim().toLowerCase();
          return this.pending
              .filter(c => this.sourceFilter === 'all' || c.source === this.sourceFilter)
              .filter(c => !t || (c.wrong_text||'').toLowerCase().includes(t) || (c.correct_text||'').toLowerCase().includes(t));
      }
      ```
    - Indicador `X visibles / Y totales` cuando filtro activo.
  - **Tab "Aprobadas"** (L233-289):
    - Reemplazar el `foreach($approved)` server-side por patrón AJAX:
      - Nuevo Alpine state `approved: []`, `approvedSearch: ''`, `approvedSourceFilter: 'all'`.
      - Cargar en `init()` con `await this.loadApproved()`.
      - Método `loadApproved()` que pega a `/ia/correcciones/approved`.
      - Misma estructura de tabla con checkbox bulk + filtro source + búsqueda.
      - Reutilizar `bulkDestroyApproved()` ya existente.
    - Botón Eliminar por fila sigue funcionando.

## 9. UI: sub-tab "AI Suggest" histórica (aprobadas y pendientes con source=ai-suggest-*)

- [ ] En `app/resources/views/ia/correcciones/index.blade.php`:
  - En el sidebar de pestañas (donde está `tab === 'pending' | 'approved' | 'ai-settings'`), agregar pestaña `'ai-suggest-results'`.
  - Contenido: dos bloques apilados:
    1. **Auto-aprobadas por AI Suggest** (corrida reciente): tabla con aprobado_en, fuente (today/yesterday), modelo, total_mined, total_auto_approved, total_rejected.
    2. **Pendientes con source=ai-suggest-***: tabla con la misma estética que Pendientes, pero solo las del source AI Suggest.
  - Datos: AJAX a un nuevo endpoint unificado `/correcciones/ai-suggest-results` que devuelva ambos bloques (más eficiente que 2 llamadas).
  - Búsqueda libre en ambos bloques.

## 10. Controller endpoint consolidado `/correcciones/ai-suggest-results`

- [ ] En `app/app/Http/Controllers/Ia/CorreccionesController.php`:
  - Nuevo método que retorna:
    ```json
    {
      "autoApprovedRuns": [...],  // 5 últimas corridas, last_ai_suggest_at / count / status por source
      "approvedList": [...],       // correcciones aprobadas con source=ai-suggest-%
      "pendingList": [...],        // correcciones pending con source=ai-suggest-%
      "searchable": true
    }
    ```
- [ ] Ruta `GET /correcciones/ai-suggest-results`.

## 11. UI Toggle de auto-approve en AI Settings

- [ ] En `app/resources/views/ia/correcciones/index.blade.php`, dentro del panel `tab === 'ai-settings'`:
  - Nuevo bloque "Auto-aprobado" antes del final.
  - Toggle Switch (Alpine `x-model="aiSettings.values.auto_approve"`) que se traduce a `0|1` para el backend.
  - Wire a endpoint `aiSuggestSettingsUpdate` que ya existe (verificar que recibe `auto_approve`).
- [ ] Verificar en API: `POST /correcciones/ai-suggest-settings` con payload `{auto_approve: false}` actualiza la DB.

## 12. Verificación end-to-end

- [ ] Smoke test backend (curl autenticado):
  - `php artisan corrections:ai-suggest --days=1 --sample=200 --dry-run --auto-approve` → debe mostrar `Auto-approved: 0 (dry-run no inserta)`.
  - `php artisan corrections:ai-suggest --days=1 --sample=20` (sin --auto-approve y con un set pequeño para que termine rápido) → verificar en BD que inserta con status=approved cuando el setting está en true.
- [ ] Smoke test UI:
  - Cargar `/ia/correcciones`, tab "Aprobadas": ¿se ve la búsqueda libre? ¿el filtro source?
  - Tab "Pendientes": ¿la búsqueda filtra por texto?
  - Sub-tab "AI Suggest Results": ¿se ven aprobadas y pendientes de AI?
- [ ] Verificar `protected_brands`: `php -r "require 'vendor/autoload.php'; ..."` con `looksLikeBrandOrProperNoun('Open English')` → true.

## 13. Archivar

- [ ] Mover el change a `openspec/changes/archive/2026-08-01-2026-08-01-corrections-ai-suggest-auto-approve/` (convención de doble fecha).
- [ ] Aplicar delta al spec canónico `openspec/specs/transcription-corrections/spec.md` (1 MODIFIED + 2-3 ADDED Requirements).
