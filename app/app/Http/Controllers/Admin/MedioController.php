<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Medio;
use App\Models\StorageProvider;
use App\Services\MisArchivos\MedioCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Administración del catálogo de medios de TV y Radio.
 *
 * Esta es la superficie de ADMINISTRACIÓN para dar de alta los canales que
 * el cliente puede pedir en el módulo "Solicitud Puntual". El cliente nunca
 * ve esta pantalla: él solo ve el complemento de lo que no tiene asignado.
 *
 * Toda la detección y las reglas viven en `MedioCatalogService`, la misma
 * clase que usa el comando `medios:remapear`. No hay una puerta trasera por
 * consola con reglas más débiles: la UI y la consola comparten servicio.
 *
 * Dos acciones:
 *  - `detect` + `remapear`: la previsualización y el alta masiva de los
 *    sub-canales de un contenedor (Emisoras 01/02/03/04). La previsualización
 *    no escribe nada.
 *  - `store`/`update`/`destroy`: el alta y la edición puntual de un medio.
 */
class MedioController extends Controller
{
    public function __construct(
        private readonly MedioCatalogService $catalog,
    ) {
    }

    /**
     * Pantalla de administración del catálogo.
     */
    public function index(Request $request): View|JsonResponse
    {
        if ($request->wantsJson() || $request->ajax()) {
            return $this->indexJson($request);
        }

        $search = trim((string) $request->query('q', ''));
        $kind = $request->query('kind');

        $medios = $this->queryMedios($search, $kind);
        $containers = $this->containers();

        return view('admin.medios', [
            'medios' => $medios,
            'containers' => $containers,
            'search' => $search,
            'kind' => $kind,
            'kinds' => Medio::KINDS,
            'totalMedios' => Medio::query()->count(),
        ]);
    }

    /**
     * Mismo listado en JSON, para el filtrado en vivo de la pantalla.
     */
    public function indexJson(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));
        $kind = $request->query('kind');

        $medios = $this->queryMedios($search, $kind);

        return response()->json([
            'medios' => $medios->map(fn (Medio $m) => [
                'id' => (int) $m->id,
                'name' => (string) $m->name,
                'region' => $m->region,
                'media_kind' => (string) $m->media_kind,
                'subpath' => (string) $m->subpath,
                'activo' => (bool) $m->activo,
                'storage' => ['name' => (string) ($m->storage?->name ?? '')],
            ])->values(),
            'containers' => $this->containers(),
            'total' => Medio::query()->count(),
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int,Medio>
     */
    private function queryMedios(string $search, ?string $kind)
    {
        return Medio::query()
            ->with('storage')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'ilike', '%' . $search . '%')
                        ->orWhere('region', 'ilike', '%' . $search . '%')
                        ->orWhere('subpath', 'ilike', '%' . $search . '%');
                });
            })
            ->when(in_array($kind, Medio::KINDS, true), fn ($query) => $query->where('media_kind', $kind))
            ->orderBy('name')
            ->get();
    }

    /**
     * Contenedores con sub-canales por declarar.
     *
     * @return list<array{id:int,name:string,base_path:string,detectados:int,pendientes:int}>
     */
    private function containers(): array
    {
        return StorageProvider::query()
            ->where('kind', 'local')
            ->where('folder_layout', 'grouped_by_subfolder')
            ->orderBy('name')
            ->get()
            ->map(function (StorageProvider $container) {
                $proposals = $this->catalog->detectSubchannels($container);

                return [
                    'id' => (int) $container->id,
                    'name' => (string) $container->name,
                    'base_path' => (string) $container->base_path,
                    'detectados' => count($proposals),
                    'pendientes' => count(array_filter($proposals, fn (array $p) => ! $p['declared'])),
                ];
            })
            ->all();
    }

    /**
     * Previsualización de los sub-canales de un contenedor.
     *
     * NO escribe nada: es el equivalente HTTP del dry-run de
     * `medios:remapear`.
     */
    public function detect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'storage_id' => ['required', 'integer', 'exists:storage_providers,id'],
        ]);

        $container = StorageProvider::find($validated['storage_id']);

        if ($container === null) {
            return response()->json(['message' => 'El storage no existe.'], 404);
        }

        $proposals = $this->catalog->detectSubchannels($container);

        $targets = StorageProvider::query()
            ->whereIn('id', array_unique(array_column($proposals, 'target_storage_id')))
            ->pluck('name', 'id');

        return response()->json([
            'storage' => ['id' => (int) $container->id, 'name' => (string) $container->name],
            'total' => count($proposals),
            'pendientes' => count(array_filter($proposals, fn (array $p) => ! $p['declared'])),
            'canales' => array_map(function (array $p) use ($targets) {
                $p['target_storage_name'] = $targets[$p['target_storage_id']] ?? '—';

                return $p;
            }, $proposals),
        ]);
    }

    /**
     * Alta masiva: declara los sub-canales pendientes de un contenedor.
     *
     * Acepta un cuerpo opcional con la lista de `subpath` a declarar; sin
     * él, declara todos los pendientes. Es la acción del botón "Remapear".
     */
    public function remapear(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'storage_id' => ['required', 'integer', 'exists:storage_providers,id'],
            'subpaths' => ['nullable', 'array'],
            'subpaths.*' => ['string', 'max:500'],
        ]);

        $container = StorageProvider::find($validated['storage_id']);

        if ($container === null) {
            return response()->json(['message' => 'El storage no existe.'], 404);
        }

        $only = isset($validated['subpaths']) && is_array($validated['subpaths'])
            ? array_map('trim', $validated['subpaths'])
            : null;

        $proposals = $this->catalog->detectSubchannels($container);

        if ($only !== null) {
            $proposals = array_values(array_filter(
                $proposals,
                fn (array $p) => in_array($p['subpath'], $only, true)
            ));
        }

        $written = 0;
        $skipped = 0;

        foreach ($proposals as $proposal) {
            if ($proposal['declared']) {
                $skipped++;

                continue;
            }

            $target = StorageProvider::find($proposal['target_storage_id']);

            if ($target === null) {
                $skipped++;

                continue;
            }

            $this->catalog->declare(
                $target,
                $proposal['target_subpath'],
                $proposal['name'],
                $proposal['region'],
                $proposal['media_kind'],
            );

            $written++;
        }

        return response()->json([
            'escritos' => $written,
            'omitidos' => $skipped,
            'detectados' => count($proposals),
        ]);
    }

    /**
     * Alta de un medio puntual.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'storage_id' => ['required', 'integer', 'exists:storage_providers,id'],
            'subpath' => ['nullable', 'string', 'max:500'],
            'name' => ['required', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:120'],
            'media_kind' => ['required', Rule::in(Medio::KINDS)],
        ]);

        $storage = StorageProvider::find($validated['storage_id']);

        if ($storage === null) {
            return response()->json(['message' => 'El storage no existe.'], 404);
        }

        $medio = $this->catalog->declare(
            $storage,
            (string) ($validated['subpath'] ?? ''),
            (string) $validated['name'],
            $validated['region'] ?? null,
            (string) $validated['media_kind'],
        );

        return response()->json(['medio' => $this->serialize($medio)], 201);
    }

    /**
     * Edición de un medio.
     */
    public function update(Request $request, Medio $medio): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'region' => ['sometimes', 'nullable', 'string', 'max:120'],
            'media_kind' => ['sometimes', Rule::in(Medio::KINDS)],
            'activo' => ['sometimes', 'boolean'],
        ]);

        $medio->fill($validated);
        $medio->save();

        return response()->json(['medio' => $this->serialize($medio->refresh())]);
    }

    /**
     * Da de baja un medio.
     *
     * Se desactiva en lugar de borrarse: la tabla tiene un índice único
     * parcial sobre los medios activos, y las solicitudes de un medio dado de
     * baja deben seguir siendo legibles (son el registro contable).
     */
    public function destroy(Medio $medio): JsonResponse
    {
        $medio->activo = false;
        $medio->save();

        return response()->json(['medio' => $this->serialize($medio->refresh())]);
    }

    /**
     * @return array<string,mixed>
     */
    private function serialize(Medio $medio): array
    {
        return [
            'id' => (int) $medio->id,
            'storage_id' => (int) $medio->storage_id,
            'storage_name' => (string) ($medio->storage?->name ?? ''),
            'subpath' => (string) $medio->subpath,
            'name' => (string) $medio->name,
            'region' => $medio->region,
            'media_kind' => (string) $medio->media_kind,
            'activo' => (bool) $medio->activo,
        ];
    }
}
