## Purpose

Lets clients with access to many storage providers (medios) quickly find and select specific medios inside the "Todos los medios" dropdown of the Mis Avisos module by typing a free-text filter that narrows the visible list as they type.

## ADDED Requirements

### Requirement: El dropdown de medios expone un input de búsqueda libre

El sistema SHALL mostrar un input de búsqueda visible dentro del popup del dropdown "Todos los medios", ubicado entre la opción "Todas (sin filtro)" y la lista de medios checkboxes. El input SHALL tener `placeholder` "Filtrar medios…" y SHALL ser de tipo `search`.

#### Scenario: cliente abre el dropdown
- **CUANDO** el cliente hace click en el botón trigger "Todos los medios"
- **ENTONCES** el popup se abre
- **Y** el input de búsqueda aparece visible dentro del popup, posicionado entre "Todas (sin filtro)" y la lista de medios

#### Scenario: input recibe autofocus al abrir
- **CUANDO** el popup se abre (vía click en el trigger)
- **ENTONCES** el cursor de texto se posiciona automáticamente dentro del input de búsqueda
- **Y** el cliente puede tipear inmediatamente sin un click previo

### Requirement: Filtrado en vivo client-side, insensible a acentos y case

El sistema SHALL filtrar la lista visible de medios en tiempo real a medida que el cliente tipea en el input, mostrando únicamente los medios cuyo `name` contenga la query. El matching SHALL ser case-insensitive y SHALL ignorar diacriticos: por ejemplo, "rcn" matchea "RCN Tv", "television" matchea "Televisión", "caracol" matchea "Caracol Tv".

#### Scenario: query con acentos
- **DADO** que la lista incluye "Televisión Nacional"
- **CUANDO** el cliente tipea "television" (sin tilde)
- **ENTONCES** "Televisión Nacional" permanece visible en la lista filtrada

#### Scenario: query en mayúsculas
- **DADO** que la lista incluye "RCN Tv"
- **CUANDO** el cliente tipea "rcn"
- **ENTONCES** "RCN Tv" permanece visible en la lista filtrada

#### Scenario: query sin matches
- **DADO** que la lista incluye "Caracol Tv", "RCN Tv", "City Tv"
- **CUANDO** el cliente tipea "xyz123"
- **ENTONCES** la lista visible queda vacía
- **Y** se muestra el mensaje "Sin coincidencias para «xyz123»"

#### Scenario: query vacía
- **CUANDO** el input de búsqueda está vacío (sin tipear nada o tras borrar)
- **ENTONCES** la lista visible muestra todos los medios (comportamiento idéntico al estado actual)

### Requirement: El estado del filtro es efímero y se resetea al cerrar el popup

El sistema SHALL resetear el valor del input de búsqueda a vacío cada vez que el popup se cierra, ya sea por click outside, tecla Escape, o por marcar la opción "Todas (sin filtro)". Al reabrir el popup el input SHALL estar vacío.

#### Scenario: cerrar por click outside
- **DADO** que el cliente tipeó "rcn" en el input
- **CUANDO** el cliente hace click fuera del popup
- **ENTONCES** el popup se cierra
- **Y** al reabrirlo, el input está vacío y la lista muestra todos los medios

#### Scenario: cerrar con Escape
- **DADO** que el popup está abierto y el input tiene foco
- **CUANDO** el cliente presiona la tecla Escape
- **ENTONCES** el popup se cierra
- **Y** al reabrirlo, el input está vacío

#### Scenario: marcar "Todas (sin filtro)"
- **DADO** que el popup está abierto con query "rcn" en el input
- **CUANDO** el cliente marca la opción "Todas (sin filtro)"
- **ENTONCES** `storage_ids` queda en `[]` (sin filtro de medios aplicado)
- **Y** al reabrir el popup, el input de búsqueda está vacío

### Requirement: El contador del trigger refleja la selección, no los visibles

El sistema SHALL mantener la semántica actual del label del botón trigger: cuando no hay medios seleccionados muestra "Todos los medios"; cuando hay N medios seleccionados muestra "N medio(s)". El número SHALL basarse en `storage_ids.length` (selección efectiva), no en el conteo de medios visibles bajo el filtro.

#### Scenario: filtro activo con selección intacta
- **DADO** que `storage_ids = [42, 55]` (2 medios seleccionados)
- **CUANDO** el cliente tipea "rcn" en el input y solo 1 medio queda visible
- **ENTONCES** el botón trigger sigue mostrando "2 medio(s)" (no "1 medio visible")

#### Scenario: filtro activo sin selección
- **DADO** que `storage_ids = []` (sin selección)
- **CUANDO** el cliente tipea "caracol" y filtra la lista
- **ENTONCES** el botón trigger sigue mostrando "Todos los medios"

### Requirement: Empty state diferenciado entre "sin medios" y "sin matches"

El sistema SHALL mostrar dos empty states distintos dentro del popup:
- Si `storages.length === 0` (el cliente no tiene acceso a ningún medio): "Sin medios con acceso."
- Si el filtro de búsqueda no retorna matches pero hay medios disponibles: "Sin coincidencias para «<query>»" donde `<query>` es el valor actual del input.

#### Scenario: cliente sin medios
- **DADO** que `storages = []`
- **CUANDO** el cliente abre el popup
- **ENTONCES** la lista está vacía y se muestra "Sin medios con acceso."

#### Scenario: filtro sin matches
- **DADO** que `storages` contiene 10 medios
- **CUANDO** el cliente tipea "zzz" y ningún medio matchea
- **ENTONCES** la lista está vacía y se muestra "Sin coincidencias para «zzz»"

### Requirement: La selección y el submit al backend no cambian

El sistema SHALL mantener intacto el contrato HTTP existente: el submit del formulario SHALL seguir enviando `storage_ids[]` como array de IDs al endpoint `/mis-avisos/history` (pestaña Histórico) o `/mis-avisos/feed` (pestaña En vivo). El input de búsqueda SHALL ser solo un helper visual client-side; su valor SHALL NO incluirse en el payload, en la URL ni en el backend.

#### Scenario: submit normal desde Histórico
- **DADO** que el popup está cerrado y `historyFilters.storage_ids = [42, 55]`
- **CUANDO** el cliente hace click en "Buscar"
- **ENTONCES** se ejecuta `GET /mis-avisos/history?storage_ids[]=42&storage_ids[]=55&...` con el mismo shape exacto que antes del cambio

#### Scenario: submit normal desde En vivo
- **DADO** que el popup está cerrado y `liveFilters.storage_ids = [42]`
- **CUANDO** el auto-poll ejecuta `pollLive()`
- **ENTONCES** se ejecuta `GET /mis-avisos/feed?storage_ids[]=42&...` con el mismo shape exacto que antes del cambio

#### Scenario: la query de búsqueda no viaja al backend
- **DADO** que el cliente tipeó "rcn" en el input
- **CUANDO** el cliente selecciona un medio y cierra el popup
- **ENTONCES** el request al backend NO contiene ningún parámetro relacionado con la query de búsqueda (ni `q_storage`, ni `storage_search`, ni similar)

### Requirement: El cambio aplica a ambas pestañas En vivo e Histórico

El sistema SHALL aplicar el comportamiento descrito en este spec al dropdown "Todos los medios" en ambas pestañas del módulo Mis Avisos (En vivo y Histórico), dado que ambas reutilizan el mismo partial Blade.

#### Scenario: dropdown en tab En vivo
- **CUANDO** el cliente abre el dropdown en el tab En vivo
- **ENTONCES** el input de búsqueda está presente y funcional

#### Scenario: dropdown en tab Histórico
- **CUANDO** el cliente abre el dropdown en el tab Histórico
- **ENTONCES** el input de búsqueda está presente y funcional

#### Scenario: estado independiente por tab
- **DADO** que el cliente tipeó "rcn" en el dropdown del tab Histórico (popup cerrado)
- **CUANDO** el cliente abre el dropdown en el tab En vivo
- **ENTONCES** el input de búsqueda del dropdown de En vivo está vacío (sin filtrar, sin arrastrar estado entre tabs)
