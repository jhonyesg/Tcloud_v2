<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StorageProvider;
use App\Services\MisArchivos\BackupMappingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Administración del vínculo "storage de origen → carpeta del árbol de backup".
 *
 * El módulo de solicitud de backup resuelve el material de producción a partir del par
 * declarado en `storage_providers.backup_storage_id` + `backup_folder_name`. Este controller
 * es la superficie de ADMINISTRACIÓN para declarar ese par; el cliente nunca lo ve.
 *
 * Toda la validación vive en `BackupMappingService` (misma clase que usa el comando
 * `backups:map-storages`), para que la consola y la UI no puedan divergir.
 */
class BackupMappingController extends Controller
{
    public function __construct(private readonly BackupMappingService $mappings)
    {
    }

    /**
     * Pantalla de configuración.
     */
    public function index(Request $request): View
    {
        $rootId = $request->integer('root') ?: null;

        $tree = $this->mappings->tree($rootId);
        $coverage = $this->mappings->coverage($rootId);
        $sources = $this->mappings->candidateSources($tree['root']['id'] ?? null);

        $destinations = StorageProvider::query()
            ->where('kind', 'local')
            ->whereNotNull('base_path')
            ->whereRaw("rtrim(base_path, '/') <> ''")
            ->orderBy('name')
            ->get(['id', 'name', 'base_path']);

        // Sugerencias invertidas: storage_id => carpeta propuesta.
        $suggestions = [];
        foreach ($coverage['suggestions'] as $folder => $suggested) {
            $suggestions[(int) $suggested['id']] = $folder;
        }

        $broken = [];
        foreach ($coverage['broken'] as $row) {
            $broken[(int) $row['id']] = $row['reason'];
        }

        $rows = [];
        foreach ($sources as $source) {
            $link = $source->backupMapping();
            $state = 'unmapped';
            $folder = null;
            $destinationName = null;

            if ($source->backup_storage_id !== null) {
                if ($link !== null) {
                    $state = 'mapped';
                    $folder = $link['folder'];
                    $destinationName = (string) $link['storage']->name;
                } else {
                    $state = 'broken';
                    $folder = (string) $source->backup_folder_name;
                }
            }

            $rows[] = [
                'id' => (int) $source->id,
                'name' => (string) $source->name,
                'base_path' => (string) $source->base_path,
                'state' => $state,
                'folder' => $folder,
                'destination' => $destinationName,
                'suggestion' => $suggestions[(int) $source->id] ?? null,
                'reason' => $broken[(int) $source->id] ?? null,
            ];
        }

        usort($rows, function (array $a, array $b) {
            $order = ['broken' => 0, 'unmapped' => 1, 'mapped' => 2];

            return [$order[$a['state']], $a['name']] <=> [$order[$b['state']], $b['name']];
        });

        return view('admin.backups', [
            'tree' => $tree,
            'coverage' => $coverage,
            'rows' => $rows,
            'destinations' => $destinations->map(fn (StorageProvider $s) => [
                'id' => (int) $s->id,
                'name' => (string) $s->name,
                'base_path' => (string) $s->base_path,
            ])->values(),
            'folders' => collect($tree['folders'])->map(fn (array $f) => [
                'name' => $f['name'],
                'days' => $f['days'],
                'last_day' => $f['last_day'],
                'mapped_to' => $f['mapped_to'],
            ])->values(),
            'messages' => BackupMappingService::errorMessages(),
        ]);
    }

    /**
     * Carpetas del árbol de backup, para el selector del modal.
     */
    public function folders(Request $request): JsonResponse
    {
        $rootId = $request->integer('root') ?: null;
        $tree = $this->mappings->tree($rootId, $request->boolean('fresh'));

        if ($tree['root'] === null) {
            return response()->json([
                'ok' => false,
                'message' => 'No hay un árbol de backup declarado. Configura BACKUP_ROOT_STORAGE_ID o elige el destino en el modal.',
                'folders' => [],
            ], 422);
        }

        if ($tree['mount_down']) {
            return response()->json([
                'ok' => false,
                'message' => 'El montaje de red del árbol de backup está caído: no se puede leer el árbol.',
                'folders' => [],
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'root' => $tree['root'],
            'folders' => $tree['folders'],
        ]);
    }

    /**
     * Declara (o replaces) el vínculo de un storage de origen.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_storage_id' => 'required|integer|exists:storage_providers,id',
            'backup_storage_id' => 'required|integer|exists:storage_providers,id',
            'backup_folder_name' => 'required|string|max:255',
        ], [], [
            'source_storage_id' => 'storage de origen',
            'backup_storage_id' => 'árbol de backup',
            'backup_folder_name' => 'carpeta de backup',
        ]);

        $result = $this->mappings->assign(
            (int) $validated['source_storage_id'],
            (int) $validated['backup_storage_id'],
            (string) $validated['backup_folder_name']
        );

        if (! $result['ok']) {
            return response()->json([
                'ok' => false,
                'errors' => $result['errors'],
                'message' => $this->firstMessage($result['errors']),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Vínculo declarado: ' . $result['storage']->name . ' → ' . $validated['backup_folder_name'],
        ]);
    }

    /**
     * Quita el vínculo de un storage de origen.
     */
    public function destroy(Request $request, int $storage): JsonResponse
    {
        $removed = $this->mappings->unassign($storage);

        if (! $removed) {
            return response()->json([
                'ok' => false,
                'message' => 'Ese storage no tiene vínculo de backup declarado.',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Vínculo eliminado.',
        ]);
    }

    /**
     * @param  list<string>  $errors
     */
    private function firstMessage(array $errors): string
    {
        $messages = BackupMappingService::errorMessages();

        foreach ($errors as $error) {
            if (isset($messages[$error])) {
                return $messages[$error];
            }
        }

        Log::warning('backup_mapping.unknown_error', ['errors' => $errors]);

        return 'No se pudo declarar el vínculo.';
    }
}
