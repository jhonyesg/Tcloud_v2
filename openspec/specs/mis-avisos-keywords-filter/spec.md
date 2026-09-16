## Purpose

Lets clients with many registered keywords quickly find and select a specific keyword inside the "Todas mis keywords" dropdown of the Mis Avisos module by typing a free-text filter that narrows the visible list as they type, mirroring the storages dropdown behavior from `mis-avisos-storages-filter`.

## Requirements

### Requirement: El dropdown de keywords expone un input de búsqueda libre

El sistema SHALL mostrar un input de búsqueda visible dentro del popup del dropdown "Todas mis keywords", ubicado entre el radio "Todas mis keywords" y la lista de keywords. El input SHALL tener `placeholder` "Filtrar keywords…" y SHALL ser de tipo `search`.

#### Scenario: cliente abre el dropdown
- **CUANDO** el cliente hace click en el botón trigger "Todas mis keywords"
- **ENTONCES** el popup se abre
- **Y** el input de búsqueda aparece visible dentro del popup, posicionado entre "Todas mis keywords" (radio) y la lista de keywords (radios)

#### Scenario: input recibe autofocus al abrir
- **CUANDO** el popup se abre (vía click en el trigger)
- **ENTONCES** el cursor de texto se posiciona automáticamente dentro del input de búsqueda
- **Y** el cliente puede tipear inmediatamente sin un click previo

### Requirement: Filtrado en vivo client-side, insensible a acentos y case

El sistema SHALL filtrar la lista visible de keywords en tiempo real a medida que el cliente tipea en el input, mostrando únicamente las keywords cuyo `text` contenga la query. El matching SHALL ser case-insensitive y SHALL ignorar diacríticos: por ejemplo, "petro" matchea "Petro", "fernando galan" matchea "Fernando Galan", "television" matchea "Televisión".

#### Scenario: query con acentos
- **DADO** que la lista incluye una keyword "Televisión"
- **CUANDO** el cliente tipea "television" (sin tilde)
- **ENTONCES** la keyword "Televisión" permanece visible en la lista filtrada

#### Scenario: query en minúsculas
- **DADO** que la lista incluye una keyword "PETRO"
- **CUANDO** el cliente tipea "petro"
- **ENTONCES** "PETRO" permanece visible en la lista filtrada

#### Scenario: query sin matches
- **DADO** que la lista incluye "petro", "fernando galan"
- **CUANDO** el cliente tipea "xyz123"
- **ENTONCES** la lista visible queda vacía
- **Y** se muestra el mensaje "Sin coincidencias para «xyz123»"

#### Scenario: query vacía
- **CUANDO** el input de búsqueda está vacío
- **ENTONCES** la lista visible muestra todas las keywords (comportamiento idéntico al estado actual)

### Requirement: Single-select con radio buttons

El sistema SHALL permitir seleccionar **una sola** keyword a la vez (no multi-select), usando radio buttons por cada keyword. SHALL haber además una opción "Todas mis keywords" como radio separado en la parte superior, que representa el estado "sin filtro de keyword" (`keyword_id = 0`).

#### Scenario: marcar una keyword desmarca la anterior
- **DADO** que `keyword_id = 42` (keyword "petro")
- **CUANDO** el cliente marca la radio de la keyword 99 ("fernando galan")
- **ENTONCES** `keyword_id` pasa a `99`
- **Y** la radio de la keyword 42 queda desmarcada

#### Scenario: marcar "Todas mis keywords" limpia keyword_id
- **DADO** que `keyword_id = 42`
- **CUANDO** el cliente marca el radio "Todas mis keywords"
- **ENTONCES** `keyword_id` pasa a `0`

### Requirement: El estado del filtro es efímero y se resetea al cerrar

El sistema SHALL resetear el valor del input de búsqueda a vacío cada vez que el popup se cierra (Escape, click outside, o al seleccionar cualquier radio). Al reabrir el popup el input SHALL estar vacío.

#### Scenario: cerrar con Escape
- **DADO** que el popup está abierto y el input tiene foco
- **CUANDO** el cliente presiona Escape
- **ENTONCES** el popup se cierra
- **Y** al reabrirlo, el input está vacío

#### Scenario: cerrar por click outside
- **DADO** que el cliente tipeó "petro" en el input
- **CUANDO** el cliente hace click fuera del popup
- **ENTONCES** el popup se cierra
- **Y** al reabrirlo, el input está vacío y la lista muestra todas las keywords

#### Scenario: seleccionar una keyword resetea el input
- **DADO** que el popup está abierto con query "petro" en el input
- **CUANDO** el cliente selecciona cualquier keyword (radio)
- **ENTONCES** el popup se cierra
- **Y** `keyword_id` queda con el valor seleccionado
- **Y** al reabrir el popup, el input de búsqueda está vacío

### Requirement: Trigger label refleja el texto de la keyword seleccionada

El sistema SHALL actualizar el label del botón trigger según el estado actual de selección:
- Cuando `keyword_id === 0`: mostrar `"Todas mis keywords"`.
- Cuando hay una keyword seleccionada: mostrar el **`text`** de la keyword (no un contador, porque el dominio es single-select).

#### Scenario: sin keyword seleccionada
- **DADO** que `keyword_id = 0`
- **CUANDO** se renderiza el trigger button
- **ENTONCES** el label es `"Todas mis keywords"`

#### Scenario: con keyword seleccionada
- **DADO** que `keyword_id = 42` y `keywords[42].text = "Petro"`
- **CUANDO** se renderiza el trigger button
- **ENTONCES** el label es `"Petro"`

#### Scenario: la keyword seleccionada se elimina del array
- **DADO** que `keyword_id = 42` y `keywords` ya no contiene la keyword 42
- **CUANDO** se renderiza el trigger button
- **ENTONCES** el label cae al fallback `"Todas mis keywords"` (no queda huérfano)

### Requirement: Empty state diferenciado entre "sin keywords" y "sin matches"

El sistema SHALL mostrar dos empty states distintos dentro del popup:
- Si `keywords.length === 0`: "Sin keywords registradas."
- Si el filtro no retorna matches pero hay keywords disponibles: "Sin coincidencias para «<query>»".

#### Scenario: cliente sin keywords
- **DADO** que `keywords = []`
- **CUANDO** el cliente abre el popup
- **ENTONCES** la lista está vacía y se muestra "Sin keywords registradas."

#### Scenario: filtro sin matches
- **DADO** que `keywords` contiene 10 keywords
- **CUANDO** el cliente tipea "zzz" y ninguna matchea
- **ENTONCES** la lista está vacía y se muestra "Sin coincidencias para «zzz»"

### Requirement: La selección y el submit al backend no cambian

El sistema SHALL mantener intacto el contrato HTTP existente: el submit SHALL seguir enviando `keyword_id` como único entero en la URL de `/mis-avisos/history` (pestaña Histórico) o `/mis-avisos/feed` (pestaña En vivo). El input de búsqueda SHALL ser solo un helper visual client-side; su valor SHALL NO incluirse en el payload, en la URL ni en el backend.

#### Scenario: submit normal desde Histórico
- **DADO** que `historyFilters.keyword_id = 42`
- **CUANDO** el cliente hace click en "Buscar"
- **ENTONCES** se ejecuta `GET /mis-avisos/history?keyword_id=42&...` con el mismo shape exacto que antes del cambio

#### Scenario: submit normal desde En vivo
- **DADO** que `liveFilters.keyword_id = 42`
- **CUANDO** el auto-poll ejecuta `pollLive()`
- **ENTONCES** se ejecuta `GET /mis-avisos/feed?keyword_id=42&...` con el mismo shape exacto que antes del cambio

#### Scenario: la query de búsqueda no viaja al backend
- **DADO** que el cliente tipeó "petro" en el input
- **CUANDO** el cliente selecciona la keyword "Petro" y cierra el popup
- **ENTONCES** el request al backend NO contiene ningún parámetro relacionado con la query de búsqueda (ni `q_keyword`, ni `keyword_search`, ni similar)

### Requirement: Comportamiento de auto-apply configurable por pestaña

El sistema SHALL permitir configurar si el cambio de keyword dispara un re-fetch inmediato (En vivo) o si espera al botón "Buscar" (Histórico). Esta configuración SHALL ser por inclusión del partial, no por estado interno.

#### Scenario: En vivo auto-aplica
- **DADO** que el dropdown en En vivo está configurado con auto-apply
- **CUANDO** el cliente selecciona una keyword distinta
- **ENTONCES** `keyword_id` se actualiza
- **Y** se dispara automáticamente un re-fetch al endpoint `/mis-avisos/feed`
- **Y** la lista de hits se actualiza sin necesidad de click adicional

#### Scenario: Histórico NO auto-aplica
- **DADO** que el dropdown en Histórico NO está configurado con auto-apply
- **CUANDO** el cliente selecciona una keyword distinta
- **ENTONCES** `keyword_id` se actualiza en el estado local
- **Y** NO se dispara ningún request al backend
- **Y** solo se dispara un re-fetch cuando el cliente hace click en "Buscar"

### Requirement: El cambio aplica a ambas pestañas En vivo e Histórico

El sistema SHALL aplicar el comportamiento descrito en este spec al dropdown de keywords en ambas pestañas del módulo Mis Avisos (En vivo e Histórico), dado que ambas reutilizan el mismo partial Blade con configuración distinta de auto-apply.

#### Scenario: dropdown en tab En vivo
- **CUANDO** el cliente abre el dropdown en el tab En vivo
- **ENTONCES** el input de búsqueda está presente y funcional, y la selección auto-aplica

#### Scenario: dropdown en tab Histórico
- **CUANDO** el cliente abre el dropdown en el tab Histórico
- **ENTONCES** el input de búsqueda está presente y funcional, y la selección NO auto-aplica (espera "Buscar")

#### Scenario: estado independiente por tab
- **DADO** que el cliente seleccionó la keyword 42 en el dropdown del tab Histórico
- **CUANDO** el cliente abre el dropdown en el tab En vivo
- **ENTONCES** el dropdown de En vivo muestra "Todas mis keywords" (no arrastra la selección entre tabs), igual que con los demás filtros.
