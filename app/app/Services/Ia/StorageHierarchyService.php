<?php

namespace App\Services\Ia;

use App\Models\StorageProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Fuente unica de la jerarquia de storages para el modulo API Transcriptor.
 *
 * Antes la relacion padre/hijo se deducia por LIKE de base_path en cuatro
 * sitios con criterios divergentes:
 *
 *   StorageProvider::computeInheritedTranscriptionScope  -> LIKE sin orden
 *   StorageFunnelService::computeRootIdFor               -> LIKE + LENGTH DESC
 *   DiskScannerService::computeExcludedSubpaths          -> LIKE sin orden
 *   StorageSyncService::findMoreSpecificStorage          -> str_starts_with
 *
 * Dos elegian "el ancestro mas largo" y dos no ordenaban, asi que podian
 * elegir ancestros distintos para la misma fila. Ahora el parentesco vive en
 * `storage_providers.parent_storage_id` (migracion 2026_09_16_200000) y este
 * servicio es el unico que lo interpreta.
 *
 * DOS PREGUNTAS DISTINTAS, y esta clase las expone por separado:
 *
 *   ownerOf($absolutePath)              -> quien puede PROCESAR (mira tx)
 *   resolveGeometricOwner($absolutePath) -> de quien ES la fila (NO mira tx)
 *
 * Confundirlas fue la fuga que cerraba la independencia: StorageSyncService
 * filtraba por transcriptionEnabled() para decidir dueño, asi que apagar la
 * transcripcion de un hijo cambiaba donde Mis Archivos asignaba archivos.
 *
 * Reglas de dueno efectivo (design.md D2):
 *   self tx=true            -> self
 *   self tx=false + ancestro tx=true -> el ancestro mas cercano habilitado
 *   ninguno habilitado      -> null
 *
 * Nodos equivalentes (design.md D10): dos storages con base_path normalizado
 * IDENTICO son el mismo nodo fisico con dos gestores. No se enlazan padre/hijo
 * entre si. El desempate en ownerOf() es: gana tx=true, luego el menor id.
 *
 * Cache: reusa el patron de StorageProvider::SCOPE_CACHE_PREFIX. El TTL sale de
 * SystemSetting('transcriptor_scope_cache_ttl') via StorageProvider, para no
 * duplicar la fuente de configuracion.
 */
class StorageHierarchyService
{
    /**
     * Cache del SET de storages (190 filas: id, base_path, tx, parent).
     *
     * POR QUE UN SET Y NO UNA CACHE POR RUTA: la version anterior cacheaba
     * `ownerOf($path)` con la ruta en la key (md5). Eso tenia dos problemas:
     *  1. Hit rate ~0. En el descubrimiento cada path es distinto, asi que la
     *     cache se llenaba de entradas de un solo uso. Peor: entre ticks (2 min)
     *     con TTL de 120 s, tampoco habia reuso.
     *  2. Staleness. `forget($storageId)` solo podia borrar la key del propio
     *     base_path, no la de todos los paths bajo ese storage. Un toggle de
     *     `transcription_enabled` tardaba hasta el TTL en verse reflejado.
     *
     * Con el set cacheado, la resolucion de dueño es puro PHP sobre 190 filas:
     * una query por ciclo (o ninguna, si esta caliente) y CERO staleness por
     * ruta. `forget()` limpia el set completo, asi que un toggle aplica
     * inmediatamente.
     */
    private const STORAGE_SET_CACHE_KEY = 'transcriptor.hierarchy.storage_set';
    private const STORAGE_SET_TTL = 60;

    /**
     * Mapas derivados del set cacheado, armados una vez por instancia (el
     * servicio se resuelve por request/por proceso).
     *
     * @var array<int,list<int>>|null
     */
    private ?array $childrenByParentCache = null;

    /** @var array<int,StorageProvider>|null */
    private ?array $byIdCache = null;

    /** @var array<int,bool>|null */
    private ?array $enabledSubtreeCache = null;

    /** TTL memoizado de `transcriptor_scope_cache_ttl`. */
    private ?int $cacheTtlCache = null;

    /**
     * Set memoizado por instancia. Evita deserializar 190 modelos en cada
     * llamada a los metodos publicos.
     *
     * @var list<StorageProvider>|null
     */
    private ?array $storageSetCache = null;

    /** @var array<string,list<StorageProvider>>|null */
    private ?array $equivalentByPathCache = null;

    /**
     * Set de storages ordenado por profundidad de base_path DESC (el mas
     * especifico primero).
     *
     * MEMOIZADO POR INSTANCIA ademas de cacheado en Redis: cada cache hit
     * deserializa 190 modelos Eloquent, y los metodos publicos lo consultan en
     * cada llamada (70 storages × N metodos). Sin el memo por instancia eran
     * ~1700 ms por render del modulo (medido 2026-09-16).
     *
     * @return list<StorageProvider>
     */
    private function storageSet(): array
    {
        $ttl = $this->cacheTtl();

        // TTL 0 = BYPASS (freno de emergencia documentado en AGENTS.md): no se
        // cachea NADA, ni en Redis ni en memoria. Si aqui se memoizara, el
        // bypass quedaria a medias y una mutacion directa en BD (harness,
        // tinker, seeder) seguiria viendo la jerarquia vieja.
        if ($ttl === 0) {
            return $this->loadStorageSet();
        }

        if ($this->storageSetCache !== null) {
            return $this->storageSetCache;
        }

        return $this->storageSetCache = Cache::remember(
            self::STORAGE_SET_CACHE_KEY,
            min($ttl, self::STORAGE_SET_TTL),
            fn () => $this->loadStorageSet()
        );
    }

    /**
     * @return list<StorageProvider>
     */
    private function loadStorageSet(): array
    {
        return StorageProvider::query()
            ->whereNotNull('base_path')
            ->whereRaw("rtrim(base_path, '/') <> ''")
            ->get(['id', 'name', 'base_path', 'transcription_enabled', 'parent_storage_id'])
            ->all();
    }

    /**
     * Cadena de ancestros de un storage, del padre inmediato hacia arriba.
     *
     * @return list<StorageProvider> ordenados por cercania (padre primero)
     */
    public function ancestors(int $storageId): array
    {
        $out = [];
        $visited = [$storageId => true];
        $current = $this->byId()[$storageId] ?? null;

        while ($current !== null && $current->parent_storage_id !== null) {
            $parentId = (int) $current->parent_storage_id;

            if (isset($visited[$parentId])) {
                // Defensivo: un ciclo romperia el while. Imposible por
                // construccion, pero el guard evita un bucle infinito si un
                // UPDATE manual lo introduce.
                Log::warning('transcriptor.hierarchy.cycle_detected', [
                    'storage_id' => $storageId,
                    'at' => $parentId,
                ]);
                break;
            }

            $visited[$parentId] = true;
            $parent = $this->byId()[$parentId] ?? null;

            if ($parent === null) {
                break;
            }

            $out[] = $parent;
            $current = $parent;
        }

        return $out;
    }

    /**
     * @return list<int> IDs de ancestros, del padre inmediato hacia arriba
     */
    public function ancestorIds(int $storageId): array
    {
        return array_map(static fn (StorageProvider $s) => (int) $s->id, $this->ancestors($storageId));
    }

    /**
     * ID del storage raiz de la cadena (el que no tiene padre). Si el storage
     * mismo es raiz, devuelve su propio id.
     */
    public function rootIdOf(int $storageId): int
    {
        $ancestors = $this->ancestorIds($storageId);

        return $ancestors === [] ? $storageId : (int) end($ancestors);
    }

    /**
     * Descendientes de un storage (recursivo por parent_storage_id).
     *
     * Resuelve sobre el set cacheado + un mapa de hijos por padre armado una
     * sola vez por instancia. Antes hacia 1 query por nodo visitado, que en un
     * arbol de 20 descendientes son 20 queries por storage.
     *
     * @return list<StorageProvider>
     */
    public function descendants(int $storageId): array
    {
        $childrenByParent = $this->childrenByParent();
        $byId = $this->byId();

        $out = [];
        $queue = [$storageId];
        $visited = [$storageId => true];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($childrenByParent[$current] ?? [] as $childId) {
                if (isset($visited[$childId])) {
                    continue;
                }
                $visited[$childId] = true;
                if (isset($byId[$childId])) {
                    $out[] = $byId[$childId];
                }
                $queue[] = $childId;
            }
        }

        return $out;
    }

    /**
     * Mapa parent_id => [child_id, ...] armado desde el set cacheado.
     *
     * @return array<int,list<int>>
     */
    private function childrenByParent(): array
    {
        if ($this->cacheTtl() === 0) {
            return $this->buildChildrenByParent();
        }

        if ($this->childrenByParentCache === null) {
            $this->childrenByParentCache = $this->buildChildrenByParent();
        }

        return $this->childrenByParentCache;
    }

    /** @return array<int,list<int>> */
    private function buildChildrenByParent(): array
    {
        $map = [];
        foreach ($this->storageSet() as $s) {
            if ($s->parent_storage_id !== null) {
                $map[(int) $s->parent_storage_id][] = (int) $s->id;
            }
        }

        return $map;
    }

    /**
     * Mapa id => StorageProvider desde el set cacheado.
     *
     * @return array<int,StorageProvider>
     */
    private function byId(): array
    {
        // Con TTL 0 (bypass) no se memoiza: el caller quiere el estado fresco.
        if ($this->cacheTtl() === 0) {
            $map = [];
            foreach ($this->storageSet() as $s) {
                $map[(int) $s->id] = $s;
            }

            return $map;
        }

        if ($this->byIdCache === null) {
            $map = [];
            foreach ($this->storageSet() as $s) {
                $map[(int) $s->id] = $s;
            }
            $this->byIdCache = $map;
        }

        return $this->byIdCache;
    }

    /**
     * Dueño EFECTIVO para el pipeline de transcripcion: el storage mas
     * profundo de la cadena que cubre la ruta Y tiene transcription_enabled.
     *
     * Resuelve primero por geometria (que storages cubren el path) y luego
     * aplica el filtro de transcripcion. Devuelve null si ningun storage de la
     * cadena transcribe.
     */
    public function ownerOf(string $absolutePath): ?StorageProvider
    {
        return $this->computeOwner($absolutePath);
    }

    private function computeOwner(string $absolutePath): ?StorageProvider
    {
        $cubren = $this->storagesCovering($absolutePath);

        if ($cubren === []) {
            return null;
        }

        // Mas profundo primero (base_path mas largo). En empate de ruta
        // identica (nodos equivalentes, D10): tx=true gana, luego menor id.
        $habilitados = array_values(array_filter(
            $cubren,
            static fn (StorageProvider $s) => (bool) $s->transcription_enabled
        ));

        if ($habilitados === []) {
            return null;
        }

        usort($habilitados, function (StorageProvider $a, StorageProvider $b) {
            $lenA = strlen(rtrim((string) $a->base_path, '/'));
            $lenB = strlen(rtrim((string) $b->base_path, '/'));
            if ($lenA !== $lenB) {
                return $lenB <=> $lenA;
            }

            return ((int) $a->id) <=> ((int) $b->id);
        });

        return $habilitados[0];
    }

    /**
     * Dueño GEOMETRICO: el storage mas profundo que cubre la ruta, SIN mirar
     * transcription_enabled. Es lo que debe consumir Mis Archivos para decidir
     * a que gestor pertenece una fila.
     *
     * Si esta funcion mirara tx, apagar la transcripcion de un hijo cambiaria
     * el dueño de la fila y por tanto el comportamiento de Mis Archivos.
     */
    public function resolveGeometricOwner(string $absolutePath): ?StorageProvider
    {
        $cubren = $this->storagesCovering($absolutePath);

        if ($cubren === []) {
            return null;
        }

        usort($cubren, function (StorageProvider $a, StorageProvider $b) {
            $lenA = strlen(rtrim((string) $a->base_path, '/'));
            $lenB = strlen(rtrim((string) $b->base_path, '/'));
            if ($lenA !== $lenB) {
                return $lenB <=> $lenA;
            }

            return ((int) $a->id) <=> ((int) $b->id);
        });

        return $cubren[0];
    }

    /**
     * True si algun storage de la CADENA de ancestros de la ruta (incluyendo
     * el dueño geometrico) tiene transcription_enabled.
     *
     * Es la pregunta correcta para el panel y el estimador: un archivo es
     * target si algun ancestro lo cubre con tx. Medido 2026-09-16: filtrar por
     * el storage DE LA FILA perdia 9.074 archivos de hoy (28.530 elegibles por
     * cadena vs 19.456 por fila, casi todos de `00 Discos`) — design.md D9.
     */
    public function isCoveredByEnabledChain(string $absolutePath): bool
    {
        return $this->ownerOf($absolutePath) !== null;
    }

    /**
     * Subpaths de descendientes que transcriben: los que un ancestro debe
     * saltar para no duplicar. Reemplaza DiskScannerService::computeExcludedSubpaths.
     *
     * @return list<string> rutas absolutas normalizadas
     */
    public function enabledDescendantBasePaths(int $storageId): array
    {
        return array_values(array_map(
            static fn (StorageProvider $s) => rtrim((string) $s->base_path, '/'),
            array_filter(
                $this->descendants($storageId),
                static fn (StorageProvider $s) => (bool) $s->transcription_enabled
            )
        ));
    }

    /**
     * Mapa masivo de solapamiento para TODOS los storages habilitados.
     *
     * POR QUE EXISTE: `hierarchyInfo()` resuelve un storage a la vez (ancestros
     * + descendientes + equivalentes), y llamarlo en un loop de 70 storages
     * cuesta ~800 ms y >500 queries por ser un N+1 sobre `descendants()`.
     * Medido 2026-09-16 en `ApiTranscriptorController::indexData()`.
     *
     * Esta version resuelve todo con DOS queries:
     *  1. El set completo de storages (una query, ya cacheado).
     *  2. El mapa de hijos por padre, calculado en memoria.
     *
     * Un storage tiene solapamiento si transcribe y ademas:
     *  - algun ANCESTRO suyo (por parent_storage_id) transcribe, o
     *  - algun DESCENDIENTE suyo transcribe.
     *
     * @return array<int,bool> storageId => overlap_warning
     */
    public function overlapMap(): array
    {
        $all = $this->storageSet();
        $childrenByParent = $this->childrenByParent();
        $byId = $this->byId();

        // enabledSubtree[id] = true si el storage o algun descendiente
        // transcribe. Recorrido post-orden memoizado por instancia.
        if ($this->enabledSubtreeCache === null) {
            $enabledSubtree = [];
            $walk = function (int $id) use (&$walk, &$enabledSubtree, $childrenByParent, $byId): bool {
                if (isset($enabledSubtree[$id])) {
                    return $enabledSubtree[$id];
                }

                $self = isset($byId[$id]) && (bool) $byId[$id]->transcription_enabled;
                $enabledSubtree[$id] = $self;

                foreach ($childrenByParent[$id] ?? [] as $childId) {
                    if ($walk($childId)) {
                        $enabledSubtree[$id] = true;
                    }
                }

                return $enabledSubtree[$id];
            };

            foreach (array_keys($byId) as $id) {
                $walk($id);
            }

            $this->enabledSubtreeCache = $enabledSubtree;
        }

        $enabledSubtree = $this->enabledSubtreeCache;

        $out = [];
        foreach ($all as $s) {
            $id = (int) $s->id;

            if (!$s->transcription_enabled) {
                continue;
            }

            // Ancestro habilitado: sube por parent_storage_id.
            $hasEnabledAncestor = false;
            $cur = $s->parent_storage_id;
            $guard = 0;
            while ($cur !== null && $guard < 32) {
                $parent = $byId[(int) $cur] ?? null;
                if ($parent === null) {
                    break;
                }
                if ($parent->transcription_enabled) {
                    $hasEnabledAncestor = true;
                    break;
                }
                $cur = $parent->parent_storage_id;
                $guard++;
            }

            if ($hasEnabledAncestor) {
                $out[$id] = true;
                continue;
            }

            // Descendiente habilitado: algun hijo cuyo subtree transcriba.
            foreach ($childrenByParent[$id] ?? [] as $childId) {
                if (!empty($enabledSubtree[$childId])) {
                    $out[$id] = true;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Informacion estructurada para la respuesta del guardarrail de alta/edicion.
     *
     * @return array{
     *     self: ?StorageProvider,
     *     ancestor: ?StorageProvider,
     *     ancestors: list<StorageProvider>,
     *     descendants: list<StorageProvider>,
     *     equivalent_nodes: list<StorageProvider>,
     *     overlap_warning: bool
     * }
     */
    public function hierarchyInfo(int $storageId): array
    {
        $self = $this->byId()[$storageId] ?? null;

        if ($self === null) {
            return [
                'self' => null,
                'ancestor' => null,
                'ancestors' => [],
                'descendants' => [],
                'equivalent_nodes' => [],
                'overlap_warning' => false,
            ];
        }

        $ancestors = $this->ancestors($storageId);
        $descendants = $this->descendants($storageId);
        $equivalentes = $this->equivalentNodes($self);

        $ancestroHabilitado = array_filter(
            $ancestors,
            static fn (StorageProvider $s) => (bool) $s->transcription_enabled
        );

        return [
            'self' => $self,
            'ancestor' => $ancestors[0] ?? null,
            'ancestors' => $ancestors,
            'descendants' => $descendants,
            'equivalent_nodes' => $equivalentes,
            'overlap_warning' => $self->transcription_enabled
                && ($ancestroHabilitado !== [] || $this->hasEnabledDescendant($descendants)),
        ];
    }

    /**
     * Storages con base_path normalizado IDENTICO al del storage dado.
     * Son nodos equivalentes (misma ruta fisica), no padre/hijo.
     *
     * Usa el indice `equivalentesPorRuta` (base normalizada -> [storages]),
     * armado UNA vez sobre el set cacheado. Antes recorria las 190 filas
     * comparando `rtrim(base_path,'/')` en cada iteracion: 1698 ms para 70
     * llamadas (medido 2026-09-16).
     *
     * @return list<StorageProvider>
     */
    public function equivalentNodes(StorageProvider $storage): array
    {
        $base = rtrim((string) $storage->base_path, '/');

        if ($base === '') {
            return [];
        }

        $out = [];
        foreach ($this->equivalentByPath()[$base] ?? [] as $s) {
            if ((int) $s->id === (int) $storage->id) {
                continue;
            }
            $out[] = $s;
        }

        usort($out, static fn ($a, $b) => ((int) $a->id) <=> ((int) $b->id));

        return $out;
    }

    /**
     * Indice base_path normalizado => lista de storages con esa ruta.
     *
     * @return array<string,list<StorageProvider>>
     */
    private function equivalentByPath(): array
    {
        if ($this->cacheTtl() === 0) {
            return $this->buildEquivalentByPath();
        }

        if ($this->equivalentByPathCache === null) {
            $this->equivalentByPathCache = $this->buildEquivalentByPath();
        }

        return $this->equivalentByPathCache;
    }

    /** @return array<string,list<StorageProvider>> */
    private function buildEquivalentByPath(): array
    {
        $map = [];
        foreach ($this->storageSet() as $s) {
            $base = rtrim((string) $s->base_path, '/');
            if ($base === '') {
                continue;
            }
            $map[$base][] = $s;
        }

        return $map;
    }

    /**
     * Invalida la cache del set de storages y los mapas derivados.
     *
     * El set se limpia entero (no por storage) porque:
     *  - resuelve pertenencia por ruta, y una sola mutacion de `base_path` o
     *    `transcription_enabled` puede cambiar el dueño de rutas que hoy
     *    pertenecen a OTRO storage (un ancestro que se apaga, un nodo que
     *    aparece);
     *  - son 190 filas: recargarlo es trivial.
     *
     * Los mapas por instancia (`childrenByParentCache`, `byIdCache`) se limpian
     * también: si no, un `recomputeParent()` en el mismo request seguiria
     * resolviendo sobre la jerarquia vieja.
     */
    public function forget(int $storageId): void
    {
        $this->forgetAll();

        Log::info('transcriptor.hierarchy.forgotten', ['storage_id' => $storageId]);
    }

    /**
     * Invalida la cache de la jerarquia COMPLETA (todos los storages). Se usa
     * cuando la mutacion no se puede acotar a un storage, como el toggle de
     * `transcription_enabled`, que altera la elegibilidad de las rutas de
     * TODA la descendencia.
     */
    public function forgetAll(): void
    {
        Cache::forget(self::STORAGE_SET_CACHE_KEY);

        $this->childrenByParentCache = null;
        $this->byIdCache = null;
        $this->enabledSubtreeCache = null;
        $this->storageSetCache = null;
        $this->equivalentByPathCache = null;
    }

    /**
     * Recalcula y persiste parent_storage_id de un storage. Lo llama el
     * guardarrail al guardar base_path.
     *
     * Reglas:
     *  - ancestro = prefijo estricto mas largo
     *  - ruta normalizada identica NO genera enlace (nodos equivalentes)
     *  - sin ancestro -> null
     */
    public function recomputeParent(StorageProvider $storage): ?int
    {
        $base = rtrim((string) $storage->base_path, '/');

        if ($base === '') {
            $storage->forceFill(['parent_storage_id' => null])->saveQuietly();

            return null;
        }

        $parent = StorageProvider::where('id', '!=', $storage->id)
            ->whereNotNull('base_path')
            ->whereRaw("rtrim(base_path, '/') <> ''")
            ->whereRaw("? LIKE (rtrim(base_path, '/') || '/%')", [$base])
            ->orderByRaw("LENGTH(rtrim(base_path, '/')) DESC")
            ->first();

        $parentId = $parent?->id !== null ? (int) $parent->id : null;

        $previo = $storage->parent_storage_id !== null ? (int) $storage->parent_storage_id : null;

        if ($previo !== $parentId) {
            $storage->forceFill(['parent_storage_id' => $parentId])->saveQuietly();

            // La jerarquia cambio: invalidar el set y los mapas derivados.
            $this->forgetAll();
        }

        return $parentId;
    }

    /**
     * Storages que cubren (por prefijo de base_path) la ruta absoluta dada.
     * Incluye el storage exacto si la ruta coincide con su base_path.
     *
     * Puro PHP sobre el set cacheado: sin query por ruta.
     *
     * @return list<StorageProvider>
     */
    private function storagesCovering(string $absolutePath): array
    {
        $abs = rtrim($absolutePath, '/');

        if ($abs === '') {
            return [];
        }

        $out = [];
        foreach ($this->storageSet() as $s) {
            $base = rtrim((string) $s->base_path, '/');

            if ($base === '' || $base === $abs) {
                continue;
            }

            if (str_starts_with($abs . '/', $base . '/')) {
                $out[] = $s;
            }
        }

        return $out;
    }

    private function hasEnabledDescendant(array $descendants): bool
    {
        foreach ($descendants as $d) {
            if ($d->transcription_enabled) {
                return true;
            }
        }

        return false;
    }


    private function cacheTtl(): int
    {
        // Memoizado por instancia: `storageSet()` y `ancestors()` lo consultan
        // en cada llamada, y sin memoizar son 70+ queries a `system_settings`
        // por render del modulo (medido 2026-09-16).
        if ($this->cacheTtlCache !== null) {
            return $this->cacheTtlCache;
        }

        $raw = \App\Models\SystemSetting::get('transcriptor_scope_cache_ttl');

        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return $this->cacheTtlCache = 300;
        }

        $ttl = (int) $raw;

        return $this->cacheTtlCache = (($ttl < 0 || $ttl > 3600) ? 300 : $ttl);
    }
}
