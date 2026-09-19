## Purpose

Establece la jerarquía de storages via `parent_storage_id` con FK auto-referencial, la normalización de `base_path` con `physical_path_normalized`, y los helpers para resolver ancestros y descendientes. Es la base sobre la cual el transcriptor decide la elegibilidad, el sync delega, y el permission check hace defense-in-depth.

## Requirements

### Requirement: La relación padre/hijo es una columna con FK

El sistema SHALL registrar en `storage_providers.parent_storage_id` (bigint nullable, FK a `storage_providers.id`, ON DELETE SET NULL) el ancestro inmediato de cada storage. El ancestro SHALL ser el storage cuyo `base_path` normalizado (`rtrim(base_path,'/')`) es prefijo estricto del propio, eligiendo el **prefijo de mayor longitud**. Si no existe ancestro, la columna SHALL quedar `NULL`.

#### Scenario: Hijo bajo un padre contenedor
- **WHEN** el storage "03 La W Bogota" (`/…/Radios/Bogota/LA_W`) existe junto a "01 Emisoras 01" (`/…/Radios/Bogota`)
- **THEN** `parent_storage_id` de "03 La W Bogota" apunta a "01 Emisoras 01"

#### Scenario: Storage raíz sin ancestro
- **WHEN** el storage "00 Discos" (`/www/wwwroot/data.mediaserver.com.co/Tcloud`) no tiene otro `base_path` que sea su prefijo
- **THEN** su `parent_storage_id` es `NULL`

#### Scenario: Cadena de tres niveles
- **WHEN** existen A (`/x`), B (`/x/y`) y C (`/x/y/z`)
- **THEN** B apunta a A y C apunta a B (no a A), y la profundidad se resuelve recorriendo la cadena

### Requirement: Base_path normalizado idéntico se reporta, no se automatiza

Si dos o más storages tienen `base_path` normalizado idéntico, el sistema SHALL NOT inventar una relación automática entre ellos. El backfill SHALL registrar el grupo en log estructurado y dejar el `parent_storage_id` de todos ellos sin resolver entre sí, para que el operador decida.

#### Scenario: Par con ruta normalizada idéntica
- **WHEN** los storages 132 y 142 tienen ambos `base_path` = `/…/Radios/Bogota/La_voz` (uno con barra final, otro sin)
- **THEN** el backfill emite un reporte con los ids y rutas del grupo y ninguno apunta al otro
- **AND** el pipeline sigue funcionando: ambos resuelven como raíces de su propio scope

### Requirement: Un servicio único resuelve ancestros, cobertura y dueño

El sistema SHALL exponer `App\Services\Ia\StorageHierarchyService` con al menos: ancestros de un storage (cadena completa), descendientes, cobertura de un conjunto de storages, y `resolveOwnerFor(string $absolutePath): ?StorageProvider`. Ningún consumidor SHALL resolver jerarquía con `LIKE` inline sobre `base_path`.

#### Scenario: Dueño efectivo de un archivo bajo padre e hijo
- **WHEN** se resuelve el dueño del archivo `/…/Radios/Bogota/LA_W/20082026/x.mp3` y tanto el padre "01 Emisoras 01" como el hijo "03 La W Bogota" tienen `transcription_enabled = true`
- **THEN** el servicio devuelve el **hijo** (el storage habilitado de mayor profundidad)

#### Scenario: Hijo sin transcripción cede al padre habilitado
- **WHEN** el hijo "69 Radio Uno Bogota" tiene `transcription_enabled = false` y su padre "01 Emisoras 01" lo tiene en `true`
- **THEN** el servicio devuelve el **padre** como dueño efectivo (ningún otro habilitado cubre la ruta)

#### Scenario: Ruta sin ningún storage habilitado en la cadena
- **WHEN** ni el storage que contiene la ruta ni sus ancestros tienen `transcription_enabled = true`
- **THEN** el servicio devuelve `null` y el archivo no entra al pipeline de transcripción

### Requirement: La exclusión de subárboles se deriva de la jerarquía

El descubrimiento del transcriptor SHALL excluir de un storage los subárboles que pertenecen a descendientes con `transcription_enabled = true`, usando el servicio de jerarquía en lugar de recomputar la relación por `LIKE`. Un descendiente con `transcription_enabled = false` NO SHALL excluirse, porque el ancestro habilitado es su dueño efectivo.

#### Scenario: Padre salta al hijo habilitado y no duplica
- **WHEN** el padre "02 Emisoras 01 Reg" escanea su árbol y el hijo "12 Blu Medellin" tiene `transcription_enabled = true`
- **THEN** el padre no crea candidatos bajo la ruta del hijo

#### Scenario: Padre cubre al hijo apagado
- **WHEN** el padre "02 Emisoras 01 Reg" escanea su árbol y el hijo "73 Radio Libertad" tiene `transcription_enabled = false`
- **THEN** el padre crea la transcripción del archivo bajo la ruta del hijo (único habilitado en la cadena)

### Requirement: El alta y edición de storages advierte el solapamiento

Al guardar un storage con `base_path`, el sistema SHALL recalcular `parent_storage_id`, invalidar las caches de scope y responder al operador con la jerarquía resultante (ancestro, descendientes, scope heredado). Si el storage queda anidado dentro de otro con `transcription_enabled = true` y ambos habilitados, SHALL incluir una advertencia de solapamiento.

#### Scenario: Alta de un hijo dentro de un padre habilitado
- **WHEN** el admin crea un storage con `base_path` dentro del árbol de un storage con `transcription_enabled = true`
- **THEN** el sistema guarda el storage, setea su `parent_storage_id`, e informa que el padre salta ese subárbol mientras ambos estén habilitados

#### Scenario: Edición de base_path mueve el storage de rama
- **WHEN** el admin cambia el `base_path` de un storage existente a otra rama
- **THEN** el sistema recalcula `parent_storage_id`, invalida `StorageProvider::forgetInheritedTranscriptionScope()` del root anterior y del nuevo, y responde con la jerarquía nueva

#### Scenario: Sin solapamiento no hay advertencia
- **WHEN** el admin crea un storage cuya ruta no está dentro de ningún otro storage
- **THEN** la respuesta indica `parent_storage_id: null` sin advertencia de solapamiento
