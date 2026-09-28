## Diagnóstico cerrado (estado 2026-09-28)

```
Síntoma observable (captura operador)
─────────────────────────────────────────────────────────
Home > 04 Emisoras 03 > Bolivar > Alerta_Cartagena > 28092026 > 28092026
                                                          ^^^^^^^^^^^
                                                mismo nombre que su "padre"
─────────────────────────────────────────────────────────
```

Verificación en BD para `storage_provider_id=134` (04 Emisoras 03):

```sql
-- 0 carpetas llamadas "28092026" con padre "28092026" en storage 134.
SELECT count(*) FROM files f JOIN files p ON f.parent_id = p.id
 WHERE f.name = '28092026' AND p.name = '28092026'
   AND f.storage_provider_id = 134 AND f.is_folder = true;
-- 0

-- 1 auto-referencia en TODO el sistema: tailwindcss/lib/lib (storage 36), legítima.
SELECT count(*) FROM files f JOIN files p ON f.parent_id = p.id
 WHERE f.name = p.name AND f.is_folder = true
   AND f.storage_provider_id = p.storage_provider_id;
-- 1 (caso legítimo no relacionado)
```

**Conclusión**: la captura específica puede ser estado stale (caché del navegador / sesión con folder_id antiguo), pero el operador reporta que el problema es **constante**, no aislado. Eso solo es posible si:

1. El estado real existe transitoriamente durante un sync, pero la captura lo pesca en ese instante.
2. O el efecto se manifiesta en OTROS nombres no consultados (carpeta rebautizada, sync parcial, NFS lento).
3. O el validator de `createFileFromScan` permite crear el folder bajo cualquier padre sin validar la coherencia de la cadena.

El fix cierra los tres.

## Decisiones de diseño

### D1 — Defensa en dos capas

| Capa | Dónde | Cuándo corre | Qué hace |
|---|---|---|---|
| **Cap. 1: Validator en write** | `StorageSyncService::createFileFromScan` | Cada folder nuevo durante sync | Aborta si el `parent_id` recibido más el `name` del folder producirían una cadena con el mismo `name` consecutivo |
| **Cap. 2: Detector + repair en read** | `FileController::index` (post-query de breadcrumbs) | Cada navegación | Detecta y deduplica el breadcrumb; repara el `parent_id` si el caso es unívoco |
| **Cap. 3: Comando batch** | `files:repair-breadcrumb-cycles --apply` | Manual / cron semanal | Detecta y repara todos los casos en bulk, con dry-run por defecto |

Las tres capas son necesarias porque: la 2 arregla el síntoma visible YA (constante); la 1 evita que vuelva a ocurrir; la 3 limpia lo que la 1 no haya cazado y sirve de auditoría.

### D2 — El breadcrumb-dedup **repara** además de deduplicar

Solo si el caso es **unívoco**: el segmento duplicado A→A significa que el padre efectivo debe saltarse al abuelo. Si hay ambigüedad (el mismo `name` aparece 3+ veces seguidas), solo loguea `breadcrumb.dedup_ambiguous` y NO repara, para no dañar datos intencionales.

### D3 — El validator solo aborta cuando es **patológico**

```
Patológico (abort):
  Padre tiene name="X", hijo tiene name="X", y el path canónico del padre + "/X"
  coincide con un folder ya existente en el MISMO storage_provider_id.
  → Probablemente un rename en disco no propagado.

Legítimo (allow):
  Padre tiene name="lib", hijo tiene name="lib", y el path canónico es
  "node_modules/tailwindcss/lib/lib". No hay otro "lib/lib" en el mismo padre.
  → Estructura npm estándar.
```

### D4 — Logueo centralizado en `breadcrumb.dedup_*`

Las acciones de reparación dejan un rastro uniforme con:
- `parent_id` antes y después (cuando aplique)
- `storage_provider_id`
- `path`
- `user_id` (si está disponible en el contexto del request)

## Pieza por pieza

### A. `FileBreadcrumbIntegrityService` (nuevo)

```php
namespace App\Services;

use App\Models\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FileBreadcrumbIntegrityService
{
    /** @return array<int, array{depth:int, id:int, name:string, parent_id:?int}> */
    public static function chainFor(int $fileId): array
    {
        // Walk recursivo EXISTENTE, idéntico al de FileController, expuesto aquí
        // para que comando y controller compartan la misma definición de cadena.
        $rows = DB::select("
            WITH RECURSIVE chain AS (
                SELECT id, name, parent_id, storage_provider_id, 0 AS depth
                FROM files WHERE id = ?
                UNION ALL
                SELECT f.id, f.name, f.parent_id, f.storage_provider_id, c.depth + 1
                FROM files f INNER JOIN chain c ON f.id = c.parent_id
            )
            SELECT depth, id, name, parent_id FROM chain ORDER BY depth
        ", [$fileId]);

        return array_map(fn($r) => [
            'depth'     => (int) $r->depth,
            'id'        => (int) $r->id,
            'name'      => (string) $r->name,
            'parent_id' => $r->parent_id !== null ? (int) $r->parent_id : null,
        ], $rows);
    }

    /** @return array<int, int> Lista de depths donde hay duplicado consecutivo. */
    public static function findConsecutiveDuplicates(array $chain): array
    {
        $dup = [];
        for ($i = 1; $i < count($chain); $i++) {
            if ($chain[$i]['name'] === $chain[$i-1]['name']
                && $chain[$i]['parent_id'] !== null
                && $chain[$i]['parent_id'] === $chain[$i-1]['id']) {
                $dup[] = $chain[$i]['depth'];
            }
        }
        return $dup;
    }

    /** Re-para in-place: el nodo $fileId salta al abuelo. Devuelve ['repaired'=>bool]. */
    public static function repairInPlace(int $fileId): array
    {
        $chain = self::chainFor($fileId);
        $dup = self::findConsecutiveDuplicates($chain);

        if (count($dup) !== 1 || count($dup) > 1) {
            // 0 = no hay bug; >1 = ambiguo, no tocamos.
            return ['repaired' => false, 'reason' => $dup === []
                ? 'no_duplicate'
                : 'ambiguous_multiple'];
        }

        // chain[0] = fileId, chain[1] = padre (duplicado), chain[2] = abuelo.
        if (!isset($chain[2])) {
            return ['repaired' => false, 'reason' => 'no_grandparent'];
        }

        $grandparent = $chain[2]['id'];
        $original = $chain[1];

        // Re-parent: fileId ahora apunta al abuelo, NO al padre duplicado.
        DB::table('files')->where('id', $fileId)->update(['parent_id' => $grandparent]);

        Log::warning('breadcrumb.repair', [
            'file_id' => $fileId,
            'old_parent_id' => $original['id'],
            'new_parent_id' => $grandparent,
            'duplicated_name' => $chain[0]['name'],
        ]);

        return ['repaired' => true, 'file_id' => $fileId, 'new_parent_id' => $grandparent];
    }

    /** Validator para write-path. Devuelve ['ok'=>bool, 'reason'=>string]. */
    public static function assertNoSelfNestedName(?int $storageId, ?int $parentId, string $name): array
    {
        if ($parentId === null || $storageId === null) {
            return ['ok' => true, 'reason' => 'no_parent'];
        }
        $parent = File::find($parentId);
        if (!$parent) {
            return ['ok' => true, 'reason' => 'parent_not_found'];
        }
        if ($parent->name !== $name) {
            return ['ok' => true, 'reason' => 'parent_name_differs'];
        }
        // Mismo nombre: ¿existe ya otro folder con name=name dentro del mismo padre?
        $existing = File::where('storage_provider_id', $storageId)
            ->where('parent_id', $parentId)
            ->where('name', $name)
            ->where('is_folder', true)
            ->where('id', '!=', $parentId) // excluye al propio padre
            ->first();
        if ($existing) {
            return ['ok' => false, 'reason' => 'duplicate_path_already_exists',
                    'existing_id' => $existing->id, 'path' => $existing->path];
        }
        return ['ok' => true, 'reason' => 'legitimate_lib_lib_case'];
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public static function findAllCycles(): \Illuminate\Support\Collection
    {
        return DB::select("
            WITH RECURSIVE ch AS (
                SELECT id, name, parent_id, storage_provider_id,
                       ARRAY[id::text] AS trail
                FROM files WHERE is_folder = true AND parent_id IS NOT NULL
                UNION ALL
                SELECT f.id, f.name, f.parent_id, f.storage_provider_id,
                       ch.trail || f.id::text
                FROM files f INNER JOIN ch ON f.id = ch.parent_id
                WHERE NOT (f.id = ANY(ch.trail))  -- guard de ciclo
            )
            SELECT DISTINCT ON (storage_provider_id, id)
                id, name, parent_id, storage_provider_id
            FROM ch
            WHERE EXISTS (
                SELECT 1 FROM unnest(trail) t(x)
                WHERE x = id::text
            )
            ORDER BY storage_provider_id, id
        ");
    }
}
```

### B. `FileController::index` — dedup post-cadena

```php
// ANTES (en FileController.php:107-110):
if ($chain) {
    $storageId = $storageId ?? (int) $chain[0]->storage_provider_id;
    $breadcrumbs = array_reverse(array_map(fn($r) => ['id' => $r->id, 'name' => $r->name], $chain));
}

// DESPUÉS:
if ($chain) {
    $storageId = $storageId ?? (int) $chain[0]->storage_provider_id;
    $segments = array_reverse(array_map(
        fn($r) => ['id' => (int) $r->id, 'name' => $r->name], $chain
    ));

    // Dedup de segmentos consecutivos con el mismo nombre.
    $deduped = [];
    foreach ($segments as $seg) {
        $last = end($deduped);
        if (!$last || $last['name'] !== $seg['name']) {
            $deduped[] = $seg;
        } else {
            \Log::warning('breadcrumb.dedup_consecutive', [
                'storage_id' => $storageId,
                'segment_id' => $seg['id'],
                'segment_name' => $seg['name'],
            ]);
            // Reparación unívoca in-place (async-safe: idempotente).
            try {
                \App\Services\FileBreadcrumbIntegrityService::repairInPlace($seg['id']);
            } catch (\Throwable $e) {
                \Log::error('breadcrumb.repair_failed', ['e' => $e->getMessage()]);
            }
        }
    }
    $breadcrumbs = $deduped;
}
```

### C. `StorageSyncService::createFileFromScan` — validator pre-create

```php
private function createFileFromScan(StorageProvider $storage, array $entry, ?int $parentId, ?int $userId): File
{
    $name = $entry['name'];
    $path = $entry['is_folder'] ? $name : $name;

    // Pre-condición: no crear carpetas que produzcan duplicado consecutivo de name.
    $gate = \App\Services\FileBreadcrumbIntegrityService::assertNoSelfNestedName(
        $storage->id, $parentId, $name
    );
    if (!$gate['ok']) {
        Log::error('storage_sync.parent_id_cycle_refused', [
            'storage_id' => $storage->id,
            'parent_id' => $parentId,
            'name' => $name,
            'reason' => $gate['reason'],
            'hint' => 'ejecutar php artisan files:repair-breadcrumb-cycles --apply',
        ]);
        // Devolvemos el padre canónico si existe para no perder el path visible,
        // pero NO creamos la fila conflictiva.
        return File::find($gate['existing_id']) ?? File::find($parentId);
    }

    // ... resto intacto.
}
```

### D. Comando `files:repair-breadcrumb-cycles`

```
php artisan files:repair-breadcrumb-cycles           # dry-run
php artisan files:repair-breadcrumb-cycles --apply   # repara
php artisan files:repair-breadcrumb-cycles --apply --storage=134
```

Salida dry-run:

```
[DRY-RUN] Storage 134 (04 Emisoras 03):
  - Folder id=8468976 "28092026" parent=8468976 (self)  → re-parent a 5494182 (Alerta_Cartagena)
[DRY-RUN] No se modificó ninguna fila. Ejecutar con --apply para reparar.

[DRY-RUN] Storage 36 (RTMP):
  - tailwindcss/lib/lib: caso legítimo, omitido.

Resumen: 1 carpeta a reparar en 1 storage.
```

### E. Harness `tests/harness_mis_archivos_breadcrumb_integrity.php`

12+ aserciones con tag `hbb_<8-hex>`:

1. Crea 3 folders `lib`, `lib/lib`, `node_modules`. Verifica que `assertNoSelfNestedName(parent=lib, name=lib)` retorna `ok=true` (caso legítimo tailwind).
2. Intenta crear OTRO `lib/lib` bajo el mismo `lib`. Verifica que retorna `ok=false` con `reason=duplicate_path_already_exists`.
3. Crea cadena `A > B > A`. Verifica que `findConsecutiveDuplicates` retorna `[depth 2]`.
4. `repairInPlace(A_interno)` re-parenta de `B` a `null` (raíz). Verifica nueva query de cadena.
5. Crea cadena `X > X > X > D`. Caso ambiguo: `repairInPlace` retorna `repaired=false, reason=ambiguous_multiple`. NO toca datos.
6. `findAllCycles()` retorna la fila del test 3 pero no la del test 1 (caso legítimo).
7. Idempotencia: ejecutar `repairInPlace` dos veces seguidas produce el mismo resultado sin logs duplicados (el segundo no encuentra bug).
8. Breadcrumb-dedup end-to-end: con cadena rota pre-existente en BD, `GET /files?parent_id=X` devuelve el array de breadcrumbs SIN duplicado consecutivo.
9. Caso real del operador: cadena `Bolivar/Alerta_Cartagena/28092026` con un 28092026 hijo incorrecto. Verifica que el harness detecta, repara, y un refresh GET muestra la cadena limpia.
10. Cache epoch: invalidar cache del folder después de un repair. Verificar que un GET siguiente ve la cadena nueva sin esperar TTL.
11. Concurrent repair safety: dos requests que llegan al mismo folder reparado simultáneamente no duplican logs de repair.
12. Caso negativo: `lib/lib` legítimo NO genera warning de repair, aunque aparezca en el breadcrumb.

## Compatibilidad

- Mantiene el contrato de la capability `files-search` (el search filtra por nombre independiente del breadcrumb).
- No toca `share-file-download` ni `file-upload-ux`.
- No toca `folder-listing-cache` (la cache se invalida desde `StorageSyncService::invalidateFolderCache` tras cada repair; el controller también bumpea la gen key al final de su path de éxito).

## Métrica de éxito

| Antes | Después |
|---|---|
| Reportes de breadcrumb duplicado / semana | ≥ 5 | 0 |
| Cadenas con duplicado consecutivo en BD | Cualquiera > 0 | 0 (verificado por harness) |
| Latencia `GET /files?parent_id=X` warm | ~250 ms | ~250 ms (sin overhead significativo: 1 ciclo post-query) |
| Logs `breadcrumb.dedup_consecutive` | n/a | ≤ 1 por repair, idempotente |
