<?php

namespace App\Services\MisArchivos;

use App\Models\Medio;
use App\Models\StorageProvider;
use App\Models\User;
use App\Services\MountGuard;
use App\Support\Utf8;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Catálogo de medios de TV y Radio: qué medios existen, cuáles puede pedir
 * cada usuario y cómo se detectan los sub-canales de los contenedores
 * Emisoras 01/02/03/04.
 *
 * Un medio es un índice `(storage_id, subpath)` sobre la tabla `medios`, NO
 * un `storage_providers`. Motivo (design.md D1): los sub-canales de RD1,
 * RD4, RD6 y Radios/Bogota viven DENTRO del `base_path` del storage
 * contenedor. Crearles un storage propio hace que el cron
 * `storage:sync --all` los vuelva a catalogar bajo el contenedor: el mismo
 * archivo en dos catálogos paralelos y la navegación BD-first huérfana.
 *
 * Dos primitivas:
 *
 *  1. `availableFor()` — el COMPLEMENTO de lo que el usuario tiene
 *     asignado. Un medio cuenta como asignado si el usuario tiene lectura
 *     sobre su storage o sobre cualquier ancestro suyo por ruta física: en
 *     ese caso ya puede entrar al canal en Mis Archivos y el medio no le
 *     aparece en el módulo.
 *
 *  2. `detectSubchannels()` — la detección física de los canales dentro de
 *     un storage contenedor, en los dos layouts que existen:
 *       - plano: el canal es hijo directo del storage (`Radios/Bogota/CANDELA`).
 *       - agrupado por subcarpeta: el canal cuelga de una región
 *         (`RD1/Antioquia/BLU`).
 */
class MedioCatalogService
{
    /**
     * Carpetas que nunca son un canal.
     *
     * Son metadatos del sistema de archivos o del sistema operativo: se
     * detectan por nombre porque su contenido es identico al de un canal
     * (`$RECYCLE.BIN` de un disco Windows recycle un directorio de sistema
     * con subcarpetas de fecha).
     */
    /**
     * Regiones conocidas. Si la carpeta padre es una de estas, es la REGIÓN
     * y la hoja es la emisora, aunque el nombre del archivo empiece por el
     * padre ("11_cundinamarca_punto5" es Punto5, de Cundinamarca, no una
     * marca llamada "Cundinamarca").
     *
     * @var list<string>
     */
    public const REGIONES = [
        'antioquia', 'atlantico', 'bolivar', 'boyaca', 'caldas', 'caqueta', 'casanare',
        'cauca', 'cesar', 'choco', 'cordoba', 'cundinamarca', 'guainia', 'guaviare',
        'huila', 'laguajira', 'guajira', 'magdalena', 'meta', 'narino', 'nortesantander',
        'norsantander', 'putumayo', 'quindio', 'risaralda', 'sanandres', 'santander',
        'sucre', 'tolima', 'vallecauca', 'valle', 'vaupes', 'vichada', 'arauca',
        'amazonas', 'bogota', 'manizales', 'popayan', 'santamarta', 'pereira',
        'armenia', 'neiva', 'ibague', 'pasto', 'monteria', 'villavicencio', 'cucuta',
        'barranquilla', 'cartagena', 'bucaramanga', 'sincelejo', 'medellin', 'cali',
    ];

    public const DENYLIST = [
        'System Volume Information',
        '$RECYCLE.BIN',
        '.Recycle_bin',
        'lost+found',
    ];

    /** Extensiones que delatan un medio de TV. */
    private const VIDEO_EXTENSIONS = [
        'mp4', 'mkv', 'avi', 'mov', 'm4v', 'webm', 'mpg', 'mpeg', 'ts', 'm2ts',
    ];

    /**
     * Profundidad máxima de búsqueda de canales dentro de un contenedor.
     *
     * 1 = canal directo (`Radios/Bogota/CANDELA`), 2 = canal tras una región
     * (`RD1/Antioquia/BLU`), 3 = familia de variantes
     * (`Radios/Bogota/TROPICANA_REGIONAL/barranquilla`).
     */
    private const MAX_DEPTH = 3;

    /** Extensiones que delatan un medio de radio. */
    private const AUDIO_EXTENSIONS = [
        'mp3', 'm4a', 'aac', 'wav', 'flac', 'ogg', 'oga', 'opus', 'wma', 'amr',
    ];

    /** Ruta del árbol de producción cacheada en la instancia (null = no configurado). */
    private ?string $productionRoot = null;

    /** ¿La raíz del árbol de producción ya se consultó? */
    private bool $productionRootResolved = false;

    public function __construct(private readonly MountGuard $mountGuard) {}

    // ------------------------------------------------------------------
    // Canales planos (semilla del catálogo)
    // ------------------------------------------------------------------

    /**
     * Storages que son un canal plano: sus carpetas-día cuelgan
     * DIRECTAMENTE del `base_path`.
     *
     * Es una prueba ESTRUCTURAL, no de nombre: el árbol de producción
     * (`30 Television Bk`) tiene carpetas de canal y no días, así que no
     * entra; la prensa y las aplicaciones tampoco. Y evita por completo
     * agregar `mime_type` sobre 1,2 M filas de `files` (medido: 18,9 s).
     *
     * EXCLUYE el árbol de producción del backup (`Disco_D/backup`): es un
     * maestro DISTINTO del que se sirve el módulo (otra bitrate, otra
     * grilla, 13 días de retención y montaje NFS `hard`), y el design lo
     * descarta explícitamente como fuente. `35 Red Mas BK` (storage 131)
     * cuelga de ese árbol y no puede ser un medio.
     *
     * @return Collection<int,StorageProvider>
     */
    public function flatChannelStorages(): Collection
    {
        return StorageProvider::query()
            ->where('kind', 'local')
            ->where('enabled', true)
            ->where('is_personal', false)
            ->orderBy('name')
            ->get()
            ->filter(function (StorageProvider $storage) {
                $base = rtrim((string) $storage->base_path, '/');

                if ($base === '' || ! is_dir($base)) {
                    return false;
                }

                if ($this->isProductionTree($storage)) {
                    return false;
                }

                if ($this->mountGuard->isNetworkMountDown($base)) {
                    return false;
                }

                return $this->hasDayFolders($base);
            })
            ->values();
    }

    /**
     * ¿Este storage vive dentro del árbol de producción del backup?
     *
     * La raíz del árbol es el storage declarado en
     * `config('backups.root_storage_id')` (`30 Television Bk`, base_path
     * `Tcloud/Disco_D/backup`). Todo storage cuya ruta cuelgue de ahí es
     * material de producción: otro maestro, otra cadencia y otra ventana de
     * retención. Este módulo sirve el árbol de ORIGEN.
     */
    public function isProductionTree(StorageProvider $storage): bool
    {
        $rootPath = $this->productionRootPath();
        $base = rtrim((string) $storage->base_path, '/');

        if ($rootPath === null || $base === '') {
            return false;
        }

        return $base === $rootPath || str_starts_with($base . '/', $rootPath . '/');
    }

    /**
     * Ruta base del árbol de producción del backup, o null si no está
     * configurado.
     */
    private function productionRootPath(): ?string
    {
        if ($this->productionRootResolved) {
            return $this->productionRoot;
        }

        // 1) La raíz declarada por configuración (`BACKUP_ROOT_STORAGE_ID`).
        $rootId = config('backups.root_storage_id');
        $root = $rootId === null ? null : StorageProvider::find((int) $rootId);

        // 2) Si no está declarada, se deriva de los datos: la raíz del
        //    árbol de producción es el storage al que apuntan los
        //    `backup_storage_id` de los canales mapeados.
        if ($root === null) {
            $derived = StorageProvider::query()
                ->select('backup_storage_id')
                ->whereNotNull('backup_storage_id')
                ->groupBy('backup_storage_id')
                ->orderByDesc(\Illuminate\Support\Facades\DB::raw('COUNT(*)'))
                ->value('backup_storage_id');

            $root = $derived === null ? null : StorageProvider::find((int) $derived);
        }

        $this->productionRoot = $root === null
            ? null
            : (rtrim((string) $root->base_path, '/') ?: null);
        $this->productionRootResolved = true;

        return $this->productionRoot;
    }

    /**
     * Propuesta de medios para los canales planos.
     *
     * Un canal plano es el storage completo: el medio va con `subpath`
     * vacío. El tipo (TV o radio) se deduce de la extensión de un archivo
     * real del canal.
     *
     * @return list<array{storage_id:int,subpath:string,name:string,region:?string,media_kind:string,declared:bool}>
     */
    public function bootstrapProposals(): array
    {
        $declared = $this->declaredTargets();
        $out = [];

        foreach ($this->flatChannelStorages() as $storage) {
            $extension = $this->sampleExtension(rtrim((string) $storage->base_path, '/'));

            if ($extension === null) {
                continue;
            }

            if (in_array($extension, self::AUDIO_EXTENSIONS, true)) {
                $kind = Medio::KIND_RADIO;
            } elseif (in_array($extension, self::VIDEO_EXTENSIONS, true)) {
                $kind = Medio::KIND_TV;
            } else {
                // No es material de audio ni de video (imágenes, PDF, texto):
                // no es un medio de TV/Radio.
                continue;
            }

            $out[] = [
                'storage_id' => $storage->id,
                'subpath' => '',
                'name' => Utf8::repair((string) $storage->name),
                'region' => null,
                'media_kind' => $kind,
                'declared' => $declared[$storage->id . '|'] ?? false,
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Complemento no asignado
    // ------------------------------------------------------------------

    /**
     * Medios que el usuario NO tiene asignados: el catálogo del módulo.
     *
     * EXCEPCIÓN: el ADMIN ve el catálogo COMPLETO. Tiene por definición
     * acceso a todo (y de hecho `00 Discos`, la raíz del árbol, entre sus
     * permisos), así que el filtro de complemento le dejaría el catálogo
     * vacío — un módulo que no muestra nada. Para el admin el módulo es una
     * herramienta de emisión de entregas con registro contable, no una
     * petición de material ajeno.
     *
     * @return Collection<int,Medio>
     */
    public function availableFor(User $user): Collection
    {
        $medios = Medio::query()
            ->with('storage')
            ->where('activo', true)
            ->orderBy('name')
            ->get();

        if ($medios->isEmpty()) {
            return $medios;
        }

        if ($user->isAdmin()) {
            return $medios->values();
        }

        $assigned = $this->assignedBasePaths($user);

        if ($assigned === []) {
            return $medios->values();
        }

        return $medios
            ->reject(fn (Medio $medio) => $this->isAssignedTo($medio, $assigned))
            // El árbol de producción no es fuente de este módulo: ni
            // siquiera debe aparecer en el catálogo del cliente.
            ->reject(function (Medio $medio) {
                $storage = $medio->storage;

                return $storage !== null && $this->isProductionTree($storage);
            })
            ->values();
    }

    /**
     * Rutas base de los storages donde el usuario tiene lectura.
     *
     * Los storages con `base_path` vacío se descartan: son rutas rotas y no
     * deben hacer de ancestro universal (el prefijo vacío matchearía todo).
     *
     * @return list<string>
     */
    public function assignedBasePaths(User $user): array
    {
        return $user->userStorages()
            ->with('storageProvider')
            ->get()
            ->map(function ($userStorage) {
                $path = (string) ($userStorage->storageProvider?->base_path ?? '');
                return $path === '' ? null : rtrim($path, '/');
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * ¿El usuario ya tiene este medio?
     *
     * Un medio está asignado si algún storage donde el usuario tiene lectura
     * es el medio mismo o un ANCESTRO de su ruta física. El caso inverso
     * (el usuario tiene un sub-canal, no el medio completo) NO cuenta: el
     * medio completo sigue siendo material que no tiene.
     *
     * @param list<string> $assignedPaths
     */
    public function isAssignedTo(Medio $medio, array $assignedPaths): bool
    {
        $path = $medio->physicalPath();

        if ($path === '') {
            return false;
        }

        foreach ($assignedPaths as $base) {
            if ($path === $base) {
                return true;
            }

            // La comparación exige la barra final: `/discos/canal` no puede
            // hacer de ancestro de `/discos/canal2`.
            if (str_starts_with($path . '/', $base . '/')) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Detección de sub-canales
    // ------------------------------------------------------------------

    /**
     * Propuesta de medio para un sub-canal detectado.
     *
     * El destino NO es necesariamente el contenedor. Si el canal ya tiene un
     * storage propio (`Antioquia/BLU` → storage 72 "12 Blu Medellin"), ese
     * storage es el dueño de la ruta física y de sus filas en `files`: el
     * medio se declara sobre él, no sobre el contenedor. Declararlo sobre el
     * contenedor crearía una TERCERA identidad para el mismo árbol.
     *
     * @param array<string,bool> $declared
     * @return array{subpath:string,target_storage_id:int,target_subpath:string,name:string,region:?string,media_kind:string,declared:bool,is_storage:bool}
     */
    private function proposal(StorageProvider $storage, string $subpath, array $declared): array
    {
        $channelPath = rtrim((string) $storage->base_path, '/') . '/' . trim($subpath, '/');
        $proposed = $this->proposeName($subpath, $this->sampleFilePrefix($channelPath));
        [$targetStorageId, $targetSubpath, $isStorage] = $this->resolveTarget($storage, $subpath);

        return [
            'subpath' => $subpath,
            'target_storage_id' => $targetStorageId,
            'target_subpath' => $targetSubpath,
            'name' => $proposed['name'],
            'region' => $proposed['region'],
            'media_kind' => $this->detectKind($storage, $subpath),
            'declared' => $declared[$targetStorageId . '|' . $targetSubpath] ?? false,
            'is_storage' => $isStorage,
        ];
    }

    /**
     * Resuelve el storage donde debe declararse el medio de un sub-canal.
     *
     * @return array{0:int,1:string,2:bool} [storage_id, subpath, ¿era storage propio?]
     */
    public function resolveTarget(StorageProvider $container, string $subpath): array
    {
        $subpath = trim($subpath, '/');

        if ($subpath === '') {
            return [$container->id, '', false];
        }

        $physical = rtrim((string) $container->base_path, '/') . '/' . $subpath;
        $owner = StorageProvider::findByNormalizedPath(strtolower($physical), (string) $container->kind);

        if ($owner !== null && $owner->id !== $container->id) {
            // El canal ya es un storage: ese storage es el dueño de la ruta
            // física y de sus filas en `files`.
            return [$owner->id, '', true];
        }

        return [$container->id, $subpath, false];
    }

    /**
     * Detecta los canales que viven dentro de un storage contenedor.
     *
     * Recorre el árbol del contenedor buscando carpetas cuyas subcarpetas
     * directas sean días `DDMMYYYY` válidos: eso es un canal. Lo que no es un
     * canal se sigue bajando, porque puede ser una región (`RD1/Antioquia`)
     * o una familia de variantes (`Radios/Bogota/TROPICANA_REGIONAL`).
     *
     * La profundidad está acotada a propósito: los layouts conocidos son de
     * 1 y 2 niveles, y el tercer nivel aparece en `TROPICANA_REGIONAL`.
     *
     * @return list<array{subpath:string,target_storage_id:int,target_subpath:string,name:string,region:?string,media_kind:string,declared:bool,is_storage:bool}>
     */
    public function detectSubchannels(StorageProvider $storage): array
    {
        $base = rtrim((string) $storage->base_path, '/');

        if ($base === '' || ! is_dir($base)) {
            return [];
        }

        // Los exports son `hard`: un `stat()` sobre un montaje caído deja el
        // proceso esperando. La guarda va ANTES de tocar el disco.
        if ($this->mountGuard->isNetworkMountDown($base)) {
            Log::warning('medios.detect_mount_detached', [
                'storage_id' => $storage->id,
                'base_path' => $base,
                'mount_point' => $this->mountGuard->enclosingNetworkMount($base),
            ]);

            return [];
        }

        $declared = $this->declaredTargets();
        $out = [];

        $this->collectChannels($storage, $base, '', 1, $declared, $out);

        return $out;
    }

    /**
     * Recorre un nivel del contenedor y acumula los canales que encuentre.
     *
     * @param array<string,bool> $declared
     * @param list<array{subpath:string,target_storage_id:int,target_subpath:string,name:string,region:?string,media_kind:string,declared:bool,is_storage:bool}> $out
     */
    private function collectChannels(
        StorageProvider $storage,
        string $base,
        string $relative,
        int $depth,
        array $declared,
        array &$out,
    ): void {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        foreach ($this->childDirectories($base . '/' . $relative) as $child) {
            if ($this->isDenied($child)) {
                continue;
            }

            $childPath = $relative === '' ? $child : $relative . '/' . $child;

            if ($this->hasDayFolders($base . '/' . $childPath)) {
                $out[] = $this->proposal($storage, $childPath, $declared);
                continue;
            }

            // No es un canal: puede ser una región o una familia de
            // variantes. Se sigue bajando.
            $this->collectChannels($storage, $base, $childPath, $depth + 1, $declared, $out);
        }
    }

    /**
     * ¿La carpeta padre es la MARCA (y la hoja el dial), o es la REGIÓN?
     *
     * La evidencia es el NOMBRE DEL ARCHIVO que escribe el grabador, que es
     * la única fuente que dice qué es cada cosa:
     *
     *     Antioquia/La_Fm      -> 09_la_fm_antioquia      padre al FINAL  -> REGION
     *     TROPICANA_REGIONAL/  -> tropicanabarranquilla    padre al INICIO -> MARCA
     *       barranquilla
     *
     * Cuando el padre está al final del prefijo, la hoja es la emisora y el
     * padre la región ("La Fm de Antioquia"). Cuando el padre está al
     * principio, la hoja es el DIAL y el padre la marca ("Tropicana
     * Barranquilla"): el nombre visible debe llevar la marca, o doce
     * emisoras distintas se leerían como departamentos sueltos.
     */
    private function parentLooksLikeBrand(string $parent, string $leaf, string $prefijoArchivo): bool
    {
        $limpiar = function (string $texto): string {
            $texto = $this->normalizarParaComparar($texto);

            return preg_replace('/^[0-9]+/', '', $texto) ?? $texto;
        };

        $padre = $limpiar($parent);
        $hoja = $limpiar($leaf);
        $prefijo = $limpiar($prefijoArchivo);

        if ($padre === '' || $prefijo === '') {
            return false;
        }

        // 0) Si el padre es una región conocida, NO es marca. Es la señal
        //    más fuerte: "Cundinamarca/Punto5" es Punto5 de Cundinamarca,
        //    por más que el archivo se llame "11_cundinamarca_punto5".
        $marcaSinSufijo = preg_replace('/(regional|nacional)$/', '', $padre) ?? $padre;

        if (in_array($marcaSinSufijo, self::REGIONES, true)) {
            return false;
        }

        // 1) El padre aparece al FINAL del prefijo: es la REGIÓN que el
        //    grabador puso detrás del nombre del canal.
        //    ("11_cundinamarca_punto5" termina en "cundinamarca")
        if (str_ends_with($prefijo, $padre)) {
            return false;
        }

        // 2) El padre ABRE el prefijo y la hoja lo cierra: MARCA + dial.
        //    ("tropicanabarranquilla": abre "tropicana", cierra "barranquilla")
        $marca = preg_replace('/(regional|nacional)$/', '', $padre) ?? $padre;

        if ($marca !== '' && str_starts_with($prefijo, $marca) && str_ends_with($prefijo, $hoja)) {
            return true;
        }

        // 3) El padre abre el prefijo pero la hoja no lo cierra
        //    ("tropicana" + "barranquilla" con la hoja en otro orden): se
        //    exige que el padre no sea un departamento conocido para no
        //    inventar marcas.
        if ($marca !== '' && str_starts_with($prefijo, $marca) && ! str_ends_with($prefijo, $marca)) {
            return true;
        }

        return false;
    }

    /**
     * Nombre visible de una emisora cuya carpeta padre es la marca.
     *
     * `TROPICANA_REGIONAL/barranquilla` -> "Tropicana Barranquilla".
     * La marca sale de la carpeta con una grafía legible y el dial se
     * capitaliza palabra por palabra.
     */
    private function nombreDeMarca(string $parent, string $leaf): string
    {
        $marca = $this->humanize($parent);

        // "REGIONAL" y "NACIONAL" describen el alcance de la carpeta, no la
        // marca: "TROPICANA REGIONAL" se lee "Tropicana".
        $marca = preg_replace('/\b(REGIONAL|NACIONAL)\b/i', '', $marca) ?? $marca;
        $marca = trim(preg_replace('/\s+/', ' ', $marca) ?? $marca);

        if ($marca === '') {
            return $this->titulo($leaf);
        }

        // El nombre del archivo ya trae la marca: si el dial la repite, no
        // se duplica.
        $dial = $this->titulo($leaf);

        if (mb_stripos($this->normalizarParaComparar($dial), $this->normalizarParaComparar($marca)) === 0) {
            return $this->titulo($dial);
        }

        return $this->titulo($marca) . ' ' . $dial;
    }

    /**
     * Capitaliza cada palabra sin cambiar las mayúsculas internas.
     */
    private function titulo(string $texto): string
    {
        $texto = trim($this->humanize($texto));

        if ($texto === '') {
            return '';
        }

        // Si el nombre ya tiene mayúsculas mezcladas (una marca como
        // "BLURADIO"), se respeta tal cual.
        if (preg_match('/[A-Z]/', $texto) && ! preg_match('/^[A-Z\s]+$/', $texto)) {
            return $texto;
        }

        return mb_convert_case(mb_strtolower($texto), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Normaliza para comparar nombres de carpeta con prefijos de archivo:
     * sin acentos, sin números de orden, sin separadores.
     */
    private function normalizarParaComparar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));

        // "é" -> "e": los nombres de archivo del grabador a veces traen
        // acentos y la carpeta no, o al revés.
        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);

        $texto = preg_replace('/[^a-z0-9]+/', '', $texto) ?? $texto;

        // El prefijo numérico de orden ("09_la_fm") no es parte del nombre.
        return preg_replace('/^[0-9]+/', '', $texto) ?? $texto;
    }

    /**
     * Prefijo del nombre de archivo que escribe el grabador en un canal.
     *
     * Devuelve, por ejemplo, `09_la_fm_antioquia` o `tropicanabarranquilla`.
     * Es la evidencia de qué es cada carpeta.
     */
    private function sampleFilePrefix(string $channelPath): ?string
    {
        if (! is_dir($channelPath)) {
            return null;
        }

        $days = array_values(array_filter($this->childDirectories($channelPath), fn (string $d) => preg_match('/^\d{8}$/', $d)));
        sort($days);

        foreach ($days as $day) {
            $dir = $channelPath . '/' . $day;
            $entries = @scandir($dir);

            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || ! is_file($dir . '/' . $entry)) {
                    continue;
                }

                if (preg_match('/^(.*?)_([0-9]{8})_([0-9]{6})\.[a-z0-9]+$/i', $entry, $m)) {
                    return $m[1];
                }
            }
        }

        return null;
    }

    /**
     * Nombre y región propuestos para un sub-canal.
     *
     * La región desambigua los homónimos: `Antioquia/Caracol` y
     * `Radios/Bogota/CARACOL` son dos emisoras distintas. Sin carpeta
     * regional no hay región que inferir: el nombre queda como el de la
     * carpeta y el administrador lo edita si hace falta.
     *
     * @return array{name:string,region:?string}
     */
    public function proposeName(string $subpath, ?string $prefijoArchivo = null): array
    {
        $subpath = trim($subpath, '/');

        // Sin subpath el medio ES el storage: no hay carpeta que interpretar
        // y el nombre lo pone quien declara (el nombre del storage).
        if ($subpath === '') {
            return ['name' => '', 'region' => null];
        }

        $segments = array_values(array_filter(explode('/', $subpath)));
        $channel = (string) array_pop($segments);
        $parent = $segments === [] ? null : (string) end($segments);

        // Sin padre no hay nada que decidir: la hoja es el canal.
        if ($parent === null) {
            return [
                'name' => $this->humanize($channel),
                'region' => null,
            ];
        }

        // Con evidencia del archivo y padre que parece la MARCA, la hoja es
        // el dial y el nombre visible lleva la marca: "Tropicana
        // Barranquilla". Si no hay evidencia, se mantiene el criterio de
        // región (el padre es el departamento).
        if ($prefijoArchivo !== null && $this->parentLooksLikeBrand($parent, $channel, $prefijoArchivo)) {
            // La marca es el padre sin el sufijo de la carpeta
            // ("TROPICANA_REGIONAL" -> "Tropicana") y con la grafía del
            // nombre de la emisora, que es la que el cliente reconoce.
            return [
                'name' => $this->nombreDeMarca($parent, $channel),
                'region' => null,
            ];
        }

        return [
            'name' => $this->humanize($channel),
            'region' => $parent,
        ];
    }

    /**
     * Declara un medio. No toca `storage_providers` ni `files`.
     *
     * Si el medio ya existe y está dado de baja, se reactiva: el índice
     * único es parcial (`activo = true`), así que una fila apagada no
     * bloquea el alta.
     */
    public function declare(
        StorageProvider $storage,
        string $subpath,
        ?string $name = null,
        ?string $region = null,
        ?string $mediaKind = null,
    ): Medio {
        $subpath = trim($subpath, '/');
        $proposed = $this->proposeName($subpath);

        if ($this->isProductionTree($storage)) {
            throw new \InvalidArgumentException(
                'Un medio no puede declararse sobre el árbol de producción del backup.'
            );
        }

        if ($storage->is_personal) {
            throw new \InvalidArgumentException(
                'Un medio no puede declararse sobre un storage personal.'
            );
        }

        if (! $storage->enabled) {
            throw new \InvalidArgumentException(
                'Un medio no puede declararse sobre un storage deshabilitado.'
            );
        }

        $medio = Medio::query()
            ->where('storage_id', $storage->id)
            ->where('subpath', $subpath)
            ->first();

        if ($medio === null) {
            $medio = new Medio();
            $medio->storage_id = $storage->id;
            $medio->subpath = $subpath;
        }

        $medio->name = $name !== null && trim($name) !== '' ? trim($name) : $proposed['name'];
        $medio->region = $region !== null ? $region : $proposed['region'];
        $medio->media_kind = $mediaKind !== null && in_array($mediaKind, Medio::KINDS, true)
            ? $mediaKind
            : ($medio->media_kind ?? $this->detectKind($storage, $subpath));
        $medio->activo = true;
        $medio->save();

        return $medio->refresh();
    }

    /**
     * ¿Este sub-canal ya está declarado como medio?
     *
     * La clave es el DESTINO del medio (`storage_id|subpath`), no el
     * subpath dentro del contenedor: un canal que ya tiene storage propio se
     * declara sobre ese storage, con subpath vacío.
     */
    public function isDeclared(StorageProvider $storage, string $subpath): bool
    {
        [$targetId, $targetSubpath] = $this->resolveTarget($storage, $subpath);

        return $this->declaredTargets()[$targetId . '|' . $targetSubpath] ?? false;
    }

    /**
     * Medios ya declarados, indexados por su destino.
     *
     * @return array<string,bool> "storage_id|subpath" => declarado
     */
    private function declaredTargets(): array
    {
        return Medio::query()
            ->where('activo', true)
            ->get(['storage_id', 'subpath'])
            ->mapWithKeys(fn (Medio $medio) => [$medio->storage_id . '|' . $medio->subpath => true])
            ->all();
    }

    /**
     * Deduce si el canal es de TV o de radio.
     *
     * La señal primaria es la EXTENSIÓN de un archivo real del canal: el
     * disco es la verdad, y un `.m4a` no es un `.mp4` por mucho que el
     * catálogo diga otra cosa. Si no hay archivo que muestre (canal recién
     * creado, por ejemplo), se cae al mime dominante del storage.
     */
    private function detectKind(StorageProvider $storage, string $subpath): string
    {
        $base = rtrim((string) $storage->base_path, '/');
        $channelPath = $subpath === '' ? $base : $base . '/' . trim($subpath, '/');

        $extension = $this->sampleExtension($channelPath);

        if ($extension !== null) {
            if (in_array($extension, self::AUDIO_EXTENSIONS, true)) {
                return Medio::KIND_RADIO;
            }

            if (in_array($extension, self::VIDEO_EXTENSIONS, true)) {
                return Medio::KIND_TV;
            }
        }

        return $this->dominantKind($storage);
    }

    /**
     * Extensión del primer archivo encontrado en un canal.
     *
     * Recorre las carpetas-día hasta encontrar una con archivos.
     */
    private function sampleExtension(string $channelPath): ?string
    {
        if (! is_dir($channelPath)) {
            return null;
        }

        foreach ($this->childDirectories($channelPath) as $day) {
            if (! preg_match('/^\d{8}$/', $day)) {
                continue;
            }

            $dayPath = $channelPath . '/' . $day;
            $entries = @scandir($dayPath);

            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $full = $dayPath . '/' . $entry;

                if (! is_file($full)) {
                    continue;
                }

                $extension = strtolower((string) pathinfo($entry, PATHINFO_EXTENSION));

                if ($extension !== '') {
                    return $extension;
                }
            }
        }

        return null;
    }

    /**
     * Tipo dominante de un storage, leído del catálogo.
     */
    private function dominantKind(StorageProvider $storage): string
    {
        $mime = \Illuminate\Support\Facades\DB::table('files')
            ->where('storage_provider_id', $storage->id)
            ->where('is_folder', false)
            ->whereNotNull('mime_type')
            ->value('mime_type');

        if (is_string($mime) && str_starts_with($mime, 'audio/')) {
            return Medio::KIND_RADIO;
        }

        return Medio::KIND_TV;
    }

    /**
     * @return list<string> nombres de subdirectorios
     */
    private function childDirectories(string $path): array
    {
        $entries = @scandir($path);

        if ($entries === false) {
            return [];
        }

        $out = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (! is_dir($path . '/' . $entry)) {
                continue;
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * ¿La carpeta tiene subcarpetas de día válidas?
     */
    private function hasDayFolders(string $path): bool
    {
        foreach ($this->childDirectories($path) as $child) {
            if (preg_match('/^\d{8}$/', $child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿El nombre está en la lista de exclusión?
     *
     * Además de la lista explícita, todo lo que empiece por punto queda
     * fuera: son directorios ocultos del sistema, no canales.
     */
    private function isDenied(string $name): bool
    {
        if (in_array($name, self::DENYLIST, true)) {
            return true;
        }

        if (str_starts_with($name, '.')) {
            return true;
        }

        if (str_starts_with(strtoupper($name), '$RECYCLE')) {
            return true;
        }

        return false;
    }

    /**
     * Nombre de carpeta → nombre legible.
     *
     * `Alerta_Bogota` → `Alerta Bogota`. No se cambian las mayúsculas: los
     * acrónimos (`LA_W`, `BLU`) se leen mejor como están.
     */
    private function humanize(string $folder): string
    {
        $name = trim(str_replace('_', ' ', $folder));
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        if ($name === '') {
            return $folder;
        }

        return Utf8::repair($name);
    }
}
