## Purpose

Capability `files-folder-tree-integrity-guard`: garantiza que la cadena de breadcrumb de una carpeta nunca muestre dos segmentos con el mismo `name` en posiciones consecutivas, y repara in-place cuando el caso es unívoco. Protege la experiencia de Mis Archivos contra estados de la fila `files` donde un folder tiene un `parent_id` que lo anida bajo otro folder con idéntico `name` (auto-referencia indirecta).

## Requirements

### Requirement: Breadcrumb sin segmentos duplicados consecutivos
La vista de Mis Archivos SHALL mostrar el breadcrumb de la carpeta actual deduplicando segmentos adyacentes con el mismo nombre.

#### Scenario: Cadena limpia no se modifica
- **WHEN** el usuario navega a una carpeta cuya cadena recursiva de `parent_id` no contiene el mismo `name` en posiciones consecutivas
- **THEN** el breadcrumb se muestra idéntico a como lo devuelve la query CTE recursiva, sin transformaciones

#### Scenario: Cadena con duplicado consecutivo se deduplica en presentación
- **WHEN** el breadcrumb resuelto contendría dos segmentos con el mismo `name` consecutivos (ej. `Alerta_Cartagena > 28092026 > 28092026`)
- **THEN** el sistema elimina el segmento duplicado intermedio de la respuesta AJAX y loguea `breadcrumb.dedup_consecutive` con `storage_id`, `segment_id`, `segment_name`
- **THEN** el segmento actual (último de la lista, donde está el usuario) NO se deduplica, solo los intermedios
- **THEN** la reparación in-place del `parent_id` se ejecuta de forma best-effort sin bloquear la respuesta

#### Scenario: Caso ambiguo se preserva sin tocar datos
- **WHEN** la cadena tiene tres o más segmentos consecutivos con el mismo `name` (caso ambiguo)
- **THEN** el sistema loguea `breadcrumb.dedup_ambiguous` con la cadena completa
- **THEN** el helper `repairInPlace` retorna `repaired=false, reason=ambiguous_multiple` y NO modifica la BD

#### Scenario: Caso legítimo con el mismo nombre no genera alerta
- **WHEN** la cadena contiene una auto-referencia estructural legítima (ej. `node_modules/tailwindcss/lib/lib` donde ambos `lib` son carpetas reales distintas en disco)
- **THEN** el sistema no loguea repair ni deduplica (la query CTE devuelve los segmentos correctos desde `parent_id` sin que coincidan en storage_provider_id)

### Requirement: Reparación in-place idempotente
El servicio de integridad SHALL exponer `repairInPlace(int $fileId)` que re-asigna el `parent_id` de un folder al abuelo efectivo cuando la auto-referencia es unívoca, y SHALL ser idempotente.

#### Scenario: Caso patológico unívoco se repara
- **WHEN** la cadena de un folder es exactamente `X > X` (padre con el mismo nombre), y existe un abuelo (chain[2].id)
- **THEN** `repairInPlace` actualiza `parent_id` del folder objetivo al id del abuelo
- **THEN** loguea `breadcrumb.repair` con `file_id`, `old_parent_id`, `new_parent_id`, `duplicated_name`
- **THEN** bumpea el epoch `folder_gen:{storage_id}:{parent_id}` del folder reparado

#### Scenario: Reparación ejecutada dos veces es no-op
- **WHEN** `repairInPlace($fileId)` se invoca dos veces sobre el mismo folder sin cambios intermedios en BD
- **THEN** la segunda invocación retorna `repaired=false, reason=no_duplicate` y NO loguea `breadcrumb.repair`

#### Scenario: Reparación falla gracefully
- **WHEN** `repairInPlace` levanta una excepción (ej. BD no disponible)
- **THEN** `FileController::index` captura la excepción en try/catch, loguea `breadcrumb.repair_failed` con el mensaje, y continúa devolviendo los breadcrumbs deduplicados al cliente

### Requirement: Comando batch de reparación
El sistema SHALL exponer `php artisan files:repair-breadcrumb-cycles` para detectar y reparar en bulk casos en toda la BD.

#### Scenario: Dry-run lista casos sin modificar
- **WHEN** se ejecuta sin `--apply`
- **THEN** el comando lista cada carpeta con `name == parent.name` agrupada por `storage_provider_id`, omitiendo el caso legítimo `lib/lib`
- **THEN** no modifica ninguna fila
- **THEN** retorna exit code 1 si hay casos, 0 si no

#### Scenario: Apply repara en bulk
- **WHEN** se ejecuta con `--apply`
- **THEN** ejecuta `repairInPlace` por cada caso detectado
- **THEN** retorna exit code 0 si todos los casos se resolvieron
- **THEN** la salida muestra resumen agrupado por `storage_provider_id` con conteo de reparaciones

#### Scenario: Filtro por storage
- **WHEN** se ejecuta con `--storage=134` (uno o más)
- **THEN** solo procesa los storages listados
- **THEN** los storages fuera del filtro no se tocan

### Requirement: Detección batch vía SQL recursivo
El servicio SHALL exponer `findAllCycles()` que ejecuta un CTE recursivo con guard anti-ciclo para enumerar todos los folders cuya cadena de `parent_id` visita el mismo nodo más de una vez.

#### Scenario: CTE corta ciclos infinitos
- **WHEN** existen filas con `parent_id` apuntando a un ancestro propio (ciclo de cualquier longitud)
- **THEN** `findAllCycles` retorna cada folder afectado exactamente una vez, agrupado por `storage_provider_id`
- **THEN** la query NO excede 5 segundos incluso con 1.5M filas en `files`

#### Scenario: Caso legítimo no aparece como ciclo
- **WHEN** `lib/lib` o `A/A/A` estructuralmente legítimos coexisten en la BD
- **THEN** `findAllCycles` los distingue de los patológicos comparando `storage_provider_id` y `path` canónico — solo retorna los segundos
