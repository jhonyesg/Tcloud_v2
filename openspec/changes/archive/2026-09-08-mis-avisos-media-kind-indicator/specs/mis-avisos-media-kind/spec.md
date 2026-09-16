# Especificación: Indicador visual y filtro por tipo de medio (TV/Radio) en Mis Avisos

## Purpose

Cada archivo detectado en Histórico y En vivo de Mis Avisos muestra un ícono que identifica si es video (TV) o audio (Radio), derivado del `mime_type`. El cliente puede además filtrar la lista por tipo de medio (Todas / TV / Radio) sin perder los demás filtros.

## ADDED Requirements

### Requirement: Cada hit expone `media_kind` derivado del mime_type
El sistema SHALL poblar el campo `media_kind ∈ {tv, radio, other}` en cada fila devuelta por `MentionsSearchService::hitRow()`, calculado desde `files.mime_type`:
- `mime_type LIKE 'video/%'` → `media_kind = 'tv'`
- `mime_type LIKE 'audio/%'` → `media_kind = 'radio'`
- cualquier otro valor (incluyendo NULL) → `media_kind = 'other'`

#### Scenario: archivo de video
- **DADO** un hit cuyo `files.mime_type = 'video/mp4'`
- **CUANDO** el row se mapea via `hitRow()`
- **ENTONCES** el campo `media_kind` del row es `'tv'`

#### Scenario: archivo de audio
- **DADO** un hit cuyo `files.mime_type = 'audio/mpeg'` o `'audio/mp4'`
- **CUANDO** el row se mapea via `hitRow()`
- **ENTONCES** el campo `media_kind` del row es `'radio'`

#### Scenario: archivo de tipo desconocido
- **DADO** un hit cuyo `files.mime_type = 'application/pdf'` o NULL
- **CUANDO** el row se mapea via `hitRow()`
- **ENTONCES** el campo `media_kind` del row es `'other'`

### Requirement: Cada fila de la tabla muestra el ícono de tipo al inicio del nombre de archivo
El sistema SHALL renderizar un ícono FontAwesome inmediatamente antes del texto del nombre de archivo en cada fila resumen (collapsed) y en cada hit del sub-panel expandido.

#### Scenario: archivo de video en la fila resumen
- **DADO** un grupo cuyo `media_kind = 'tv'`
- **CUANDO** se renderiza la fila resumen del grupo
- **ENTONCES** el filename aparece con prefijo `📺` (FontAwesome `fas fa-tv`, color gris-medio)

#### Scenario: archivo de audio en el sub-panel
- **DADO** un hit del sub-panel cuyo `media_kind = 'radio'`
- **CUANDO** se renderiza la fila del hit
- **ENTONCES** el filename aparece con prefijo `📻` (FontAwesome `fas fa-radio`, color gris-medio)

#### Scenario: archivo de otro tipo
- **DADO** un grupo o hit cuyo `media_kind = 'other'`
- **CUANDO** se renderiza
- **ENTONCES** el filename aparece SIN ícono (sin prefijo gráfico)

### Requirement: Botones de filtro rápido Todas / TV / Radio
El sistema SHALL mostrar un grupo de 3 botones toggle en la barra de filtros superior de Mis Avisos, tanto en el tab En vivo como en el tab Histórico, con los labels `Todas`, `TV` y `Radio`. El botón activo se identifica visualmente (mismo patrón visual que los botones "Hoy/Ayer/3d/7d" existentes).

#### Scenario: cliente abre Histórico
- **CUANDO** se carga el tab Histórico de Mis Avisos
- **ENTONCES** el botón `Todas` aparece marcado por default
- **Y** los filtros aplicados al backend usan `media_type = 'all'` (sin restricción)

#### Scenario: cliente hace click en `TV`
- **CUANDO** el cliente hace click en el botón `[📺 TV]`
- **ENTONCES** el filtro se actualiza a `media_type = 'tv'`
- **Y** se dispara un re-fetch al endpoint `/mis-avisos/history` con el query param `media_type=tv`
- **Y** solo aparecen filas cuyo `files.mime_type LIKE 'video/%'`

#### Scenario: cliente hace click en `Radio`
- **CUANDO** el cliente hace click en el botón `[📻 Radio]`
- **ENTONCES** el filtro se actualiza a `media_type = 'radio'`
- **Y** solo aparecen filas cuyo `files.mime_type LIKE 'audio/%'`

### Requirement: El filtro combina con los demás filtros existentes
El filtro `media_type` SHALL combinarse con los demás filtros (búsqueda libre, rango de fechas, storage, keyword) usando AND. No los reemplaza — los complementa.

#### Scenario: combinar TV con storage específico
- **CUANDO** el filtro `media_type = 'tv'` está activo Y además hay `storage_ids = [42]`
- **ENTONCES** el query agrega `mime_type LIKE 'video/%'` al WHERE existente con `storage_provider_id IN (42)`

#### Scenario: volver a "Todas"
- **CUANDO** el cliente hace click en `Todas` después de haber seleccionado `TV`
- **ENTONCES** `media_type` retorna a `'all'` y el query se ejecuta sin restricción de mime_type

### Requirement: State separado por tab
El filtro `media_type` SHALL mantenerse en `liveFilters` y `historyFilters` independientemente. Cambiar de tab (En vivo ↔ Histórico) NO arrastra el filtro, igual que `storage_ids` ya hace.

#### Scenario: filtro solo aplica a su tab
- **DADO** `liveFilters.media_type = 'tv'` activo
- **CUANDO** el cliente cambia al tab Histórico
- **ENTONCES** `historyFilters.media_type` sigue en `'all'` (default) hasta que el cliente interactúe con los botones de ese tab

### Requirement: Backend rechaza valores inválidos
El endpoint `/mis-avisos/history` y `/mis-avisos/feed` SHALL rechazar con HTTP 422 cualquier valor de `media_type` que no sea `tv`, `radio` o `all`. La whitelist valida antes de propagar al query.

#### Scenario: valor inválido
- **CUANDO** un cliente o bot envía `?media_type=video` (sin 'tv')
- **ENTONCES** el controller devuelve HTTP 422 con JSON `{error: 'media_type debe ser tv, radio o all'}`

#### Scenario: sin param
- **CUANDO** un cliente envía la URL sin query param `media_type`
- **ENTONCES** el controller trata como `'all'` (sin filtro) y devuelve 200 con todos los resultados
