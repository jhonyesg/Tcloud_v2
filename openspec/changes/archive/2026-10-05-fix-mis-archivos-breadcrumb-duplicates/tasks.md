# Tasks

## 1. Servicio de integridad

- [ ] 1.1 Crear `app/app/Services/FileBreadcrumbIntegrityService.php` con los 4 métodos estáticos: `chainFor`, `findConsecutiveDuplicates`, `repairInPlace`, `assertNoSelfNestedName`, `findAllCycles`. Importaciones: `use App\Models\File; use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Log;`. Ningún facade resuelve dentro de namespace.
- [ ] 1.2 Los métodos son `public static` para que el comando, el controller, y el harness los invoquen sin instanciar.
- [ ] 1.3 `chainFor` usa el mismo CTE que `FileController:99-106` (consolidación de definición de cadena). La ruta del parent es por `parent_id`, no por inferencia de path.
- [ ] 1.4 `findConsecutiveDuplicates` recibe `array<int, array{depth,int,name,parent_id}>` y devuelve `array<int, int>` (los `depth` que son duplicados).
- [ ] 1.5 `repairInPlace` solo repara si `findConsecutiveDuplicates` retorna **exactamente 1 entrada**. Más → `ambiguous_multiple`. Menos → no-op. Repara apuntando `parent_id` de fileId al abuelo (chain[2].id), bumpeando `Cache::increment("folder_gen:{storageId}:{pid}")` correspondiente.
- [ ] 1.6 `assertNoSelfNestedName` devuelve `['ok'=>bool, 'reason'=>string]`. Razones posibles: `no_parent`, `parent_not_found`, `parent_name_differs`, `duplicate_path_already_exists`, `legitimate_lib_lib_case`.
- [ ] 1.7 `findAllCycles` consulta recursiva con guard `WHERE NOT (f.id = ANY(ch.trail))` para cortar el infinito.

## 2. Comando

- [ ] 2.1 Crear `app/app/Console/Commands/FilesRepairBreadcrumbCyclesCommand.php` con `$signature = 'files:repair-breadcrumb-cycles {--apply} {--storage=*}'` y `$description = 'Detecta y repara carpetas con breadcrumb duplicado consecutivo'`.
- [ ] 2.2 Dry-run por defecto. Con `--apply`, ejecuta `repairInPlace` por cada caso. Snapshot pre-DELETE no aplica porque el fix solo re-parenta (no borra).
- [ ] 2.3 Agrupar resultados por `storage_provider_id` en la salida. Excluir el caso `lib/lib` legítimo de la lista de reparación.
- [ ] 2.4 Exit code 0 si no hay casos. Exit code 1 si dry-run encontró casos. Exit code 0 si `--apply` los resolvió todos (incluso si hubo casos).

## 3. Validación en sync

- [ ] 3.1 En `StorageSyncService::createFileFromScan` (líneas 371-401), añadir pre-condición `assertNoSelfNestedName($storage->id, $parentId, $name)` antes de `$this->registry->ensure(...)`.
- [ ] 3.2 Si `ok=false`, loguear `storage_sync.parent_id_cycle_refused` con `storage_id`, `parent_id`, `name`, `reason`, `hint` (sugerir comando). Devolver `File::find($gate['existing_id']) ?? File::find($parentId)` para no perder el path visible pero NO crear la fila conflictiva.
- [ ] 3.3 Si `ok=true`, continuar el flujo intacto.

## 4. Defense en read

- [ ] 4.1 En `FileController::index` (líneas 107-110), post-query de breadcrumbs, dedupar segmentos consecutivos con el mismo `name`. Si se detecta duplicado, loguear `breadcrumb.dedup_consecutive` con `storage_id`, `segment_id`, `segment_name`. Llamar `FileBreadcrumbIntegrityService::repairInPlace($seg['id'])` en try/catch.
- [ ] 4.2 Tras reparar exitosamente, bumpear `Cache::increment("folder_gen:{storageId}:{parentId_de_seg}")` para invalidar el cache del folder del segmento reparado.
- [ ] 4.3 NO aplicar dedup cuando el duplicado es el **último segmento** (el actual); eso es indicador de "estamos en un folder cuyo padre tiene el mismo nombre" lo cual puede ser legítimo. Solo dedupar segmentos intermedios.

## 5. Harness

- [ ] 5.1 Crear `app/tests/harness_mis_archivos_breadcrumb_integrity.php` con tag `hbb_<8-hex>`. Ejecutable directo (`php tests/harness_mis_archivos_breadcrumb_integrity.php`), exit 0/1.
- [ ] 5.2 Helpers locales `h_ok`, `h_fail`, `h_section`, `h_check`. Cleanup defensivo al inicio con `WHERE name LIKE 'hbb_%'` para borrar corridas previas.
- [ ] 5.3 Aserciones 1-12 descritas en `design.md §E`. Cubre: caso legítimo `lib/lib`, caso patológico duplicado, caso ambiguo triple, idempotencia, end-to-end con la cadena real del operador (`Bolivar/Alerta_Cartagena/28092026` con hijo incorrecto), cache epoch, concurrencia, caso negativo del legítimo.
- [ ] 5.4 Cleanup en `finally`: borrar todos los `files` con `path LIKE 'hbb_%'` y los `storage_providers` con `name LIKE 'hbb_%'` antes de salir.

## 6. Verificación manual

- [ ] 6.1 Desde el explorador real del operador (sesión jsuarez), navegar a `04 Emisoras 03 > Bolivar > Alerta_Cartagena > 28092026`. Confirmar que el breadcrumb muestra máximo 5 segmentos, sin duplicados consecutivos.
- [ ] 6.2 Click "Actualizar" sobre el mismo folder. Confirmar que el contador de `created` es 0 (no se re-crea la fila conflictiva) y aparece un warning `storage_sync.parent_id_cycle_refused` en logs si el caso existe.
- [ ] 6.3 Ejecutar `php artisan files:repair-breadcrumb-cycles` desde `app/`. Confirmar salida en formato `design.md §D`.
- [ ] 6.4 Después de `--apply`, recargar la misma página. Confirmar que el breadcrumb quedó sin duplicados y la respuesta AJAX incluye `cache_generation` nuevo.

## 7. Rollback

- [ ] 7.1 Si el validator de createFileFromScan rompe un sync legítimo, agregar `--skip-integrity-gate` al comando y/o una flag `storage_sync.skip_integrity_gate=true` en `.env` + `config:cache`. Documentar en AGENTS.md.
- [ ] 7.2 Si el breadcrumb-dedup repara algo que el operador quiere conservar, ejecutar `php artisan files:repair-breadcrumb-cycles --apply --storage=X` para revertir (`repairInPlace` opera idempotentemente en ambos sentidos).
- [ ] 7.3 Plan B: `git revert <commit>` deja todo el código original, sin impacto en BD (no se borra nada en el fix normal).
