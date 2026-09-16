# Tasks: archivar corrección al pasarla a exclusión

## 1. Backend: extender `protectedTermsStore`

- [ ] En `app/app/Http/Controllers/Ia/CorreccionesController.php`:
  - En el modo bulk, después de que un ítem se crea exitosamente:
    - Si `$row->id && isset($item['correction_id'])`, llamar `$svc->reject($correction, $admin, 'moved_to_exclusion: ' . $row->term)`.
    - Si la corrección no existe (404), log warning y continuar (no rompe el lote).
  - El response bulk incluye `archived: [{correction_id, term}]` para que la UI confirme.
- [ ] `php -l` validar.

## 2. UI: modal single envía `correction_id`

- [ ] En el método `openExcludeForPending(c)` y `openExcludeForApproved(c)`, guardar `c.id` en `excludeShortcutForm.correctionId`.
- [ ] En `submitExcludeShortcut`, enviar `{term, notes, correction_id: this.excludeShortcutForm.correctionId}` cuando exista.

## 3. UI: bulk asigna correction_ids por término

- [ ] En `submitExcludeBulk`, mapear `terms` con `correction_id: id` (id viene de `ids` que ya tenemos del selection).
- [ ] Si el `includeIndex` está activo, la nota puede incluir el id para auditoría.

## 4. UI: refresh post-éxito + toast mejorado

- [ ] Después de un `201`/`207` con éxito, recargar la lista desde donde se originó la acción:
  - Si `excludeShortcutForm.source === 'pending'` → `await this.loadPending()`.
  - Si `source === 'approved'` → `await this.loadApproved()`.
- [ ] Si `tab === 'ai-settings'` → `await this.loadExclusiones()`.
- [ ] Toast verde: `"Exclusión 'X' agregada + corrección #Y archivada"` o `"N creadas, M archivadas"` para bulk.

## 5. Verificación

- [ ] Smoke: crear pendiente fixture, simular POST al endpoint bulk con `{terms: [{term, correction_id}], notes}`, confirmar: 201 con corrección archivada en BD (`status='rejected'`, `rejected_reason='moved_to_exclusion: <term>'`) y la exclusión creada.
- [ ] UI: en pendientes, click Excluir → modal pre-llenado → guardar → toast + fila desaparece.
- [ ] UI: bulk seleccionar 3, Excluir 3 → modal bulk → guardar → toast + 3 filas desaparecen.

## 6. Spec delta

- [ ] Append al spec canónico (1 ADDED Requirement sobre el archivado automático en atajos).

## 7. Archivar

- [ ] Mover a `archive/2026-08-01-2026-08-01-corrections-archive-on-exclude/`.
