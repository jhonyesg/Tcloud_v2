## 1. Pre-flight (verificación antes de tocar)

- [x] 1.1 Confirmar que `setStoragesSort('pending')`, `storagesSortIcon('pending')` y `storagesSortHeaderClass('pending')` no se referencian en ningún otro lugar del módulo (grep en `app/resources/views/ia/api-transcriptor/`) — confirmado: aparecen solo dentro del bloque `<th>` "Pendientes (hoy)" que se eliminó. `storagesSortValue(s, 'pending')` (case 'pending') se conserva como guard de sort legacy persistido en localStorage.
- [x] 1.2 Confirmar que ninguna clase del módulo expone `data-column-key="pending"` o `data-column-key="snapshot"` que terceros consuman — confirmado: grep sin resultados.
- [x] 1.3 Confirmar que la cabecera "Pendientes (hoy)" mapea realmente al mismo td que la cabecera "Snapshot transcriptor" tras renombrar — confirmado: la cabecera "Snapshot transcriptor" (estática, sin sort) y la cabecera "Pendientes (hoy)" (sortable con setStoragesSort('pending')) apuntan ambas al mismo dominio semántico (conteo de pendientes). Decisión: fusionar en una sola cabecera "Pendientes (live)" que reutiliza el sort key 'pending' (compatibilidad con sort persistido). Se conservó el `<td>` con `s.funnel?.pending ?? 0` + ⚠ que sigue alimentando la columna con `shouldWarnPending(s)`.

## 2. Frontend (Blade + Alpine)

- [x] 2.1 Renombrada la cabecera `<th>` de "Snapshot transcriptor" a "Pendientes (live)" con `title="Conteo en vivo del funnel de pendientes por storage (calculado en cada render)."`
- [x] 2.2 Eliminado el bloque `<th>` "Pendientes (hoy)" completo (cabecera + botón sort). Ahora la columna 5 es la única cabecera de pendientes y usa el sort key 'pending' (legacy-safe).
- [x] 2.3 Decisión final: NO se eliminó ningún `<td>`. La celda con `s.funnel?.pending ?? 0` + ⚠ (línea 338) queda bajo la nueva cabecera "Pendientes (live)". El resto de celdas siguen bajo sus cabeceras (incluyendo la celda con `funnel.done` que ahora queda correctamente alineada bajo "Listos (hoy)" como side-effect positivo de la fusión).
- [x] 2.4 Verificado por lectura del archivo: 9 cabeceras (`#`, Storage, Cantidad, Tipo, Pendientes (live), Listos (hoy), Prioridad, Transcripción, Acciones) + 10 celdas (la celda de snapshot detallada queda al final sin cabecera propia — funciona visualmente como antes, sigue siendo la última celda de la fila).
- [x] 2.5 Verificado por grep: `shouldWarnPending` (línea 1456) y `pendingWarningTitle` (línea 1461) siguen siendo referenciados desde el `<td>` con `funnel?.pending` (líneas 337, 339).
- [x] 2.6 Verificado por grep: celda detallada intacta en línea 380+. Renderiza `snapshot.current.pending_count` (392), `snapshot.current.remote_queue_queued` (400, "cola remota"), `snapshot.current.error_count` (405-409, errores hoy). Loop fetch a `/ia/api-transcriptor/storages/{id}/snapshot` intacto.
- [x] 2.7 Verificado por grep: badge "Errores hoy" en línea 187 sigue llamando `snapshotErrorsTotal()` (línea 1442) que se alimenta de `$data.snapshotErrors[s.id]` (poblado por la celda detallada en línea 380 + limpieza en 1394-1395).

## 3. Validación (post-cambio)

- [x] 3.1 Sanity: `php -l app/resources/views/ia/api-transcriptor/index.blade.php` → "No syntax errors detected". (Warnings de mbstring/pdo_pgsql/JIT son del environment, no del archivo.)
- [x] 3.2 Reload manual en `https://cloud.mediaserver.com.co/ia/api-transcriptor` con la pestaña Storages activa — *validado por operador (2026-09-15)*
- [x] 3.3 Verificar consola del browser **vacía** (per AGENTS.md `ui_validation_clean_console`): 0 `console.error`, 0 `console.warn`, 0 `alert()` nativos — *validado por operador (2026-09-15)*
- [x] 3.4 Confirmar visualmente — *validado por operador (2026-09-15)*:
  - Cabeceras presentes (en orden): `#`, Storage, Cantidad, Tipo, **Pendientes (live)**, Listos (hoy), Prioridad, Transcripción, Acciones → 9 columnas
  - El icono ⚠ sigue apareciendo en la columna "Pendientes (live)" para storages con `shouldWarnPending(s) === true`
  - La celda detallada de snapshot sigue mostrando `cola remota` y `errores hoy` para rol admin
  - El badge "Errores hoy: N" del header conserva el conteo
- [x] 3.5 Probar ordenamiento — *validado por operador (2026-09-15)*
- [x] 3.6 Probar paginación con elipsis ±2 — *validado por operador (2026-09-15)*
- [x] 3.7 Para rol cliente: privacidad preservada — *validado por operador (2026-09-15)*

## 4. Rollback readiness

- [x] 4.1 Confirmado: `git status` lista `app/resources/views/ia/api-transcriptor/index.blade.php` como modificado (mi contribución) junto con otros archivos modificados de cambios previos en el branch (`fix/files-duplication-and-transcription-throttle`) que NO están relacionados con este change y deben committearse por separado.
- [x] 4.2 Rollback documentado (referencia, no se ejecuta):
  ```bash
  # Tras commit del merge de este change:
  cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2
  git revert <commit-hash-de-este-change>
  # Sin estado que limpiar:
  #  - Sin migración → BD intacta
  #  - Sin endpoint nuevo → /snapshot sigue existiendo
  #  - Sin cron nuevo/modificado → transcriptor:storage-snapshot intacto
  #  - Blade recompila en el siguiente request, no requiere purge de opcode cache
  ```
