# Delta spec: mentions-historical-export (modificación por throttle 429)

## ADDED Requirements

### Requirement: Interacción normal del UI de Histórico NO dispara 429

El sistema SHALL permitir que un cliente complete un flujo completo de interacción en la pestaña Histórico de Mis Avisos (abrir la pestaña, aplicar 3-5 atajos de fecha consecutivos, cambiar `per_page` 2-3 veces, alternar entre TV/Radio/Todas, hacer click en "Buscar", y modificar el filtro de storage vía checkboxes) dentro de una ventana de 60 segundos sin recibir ninguna respuesta HTTP 429.

#### Scenario: flujo típico de filtrado en 60 segundos

- **CUANDO** el cliente abre el tab Histórico y aplica secuencialmente: atajo "Hoy" → atajo "Últimos 7 días" → atajo "Últimos 30 días" → cambio de per_page de 25 a 50 → cambio de per_page de 50 a 100 → click en "Buscar" → toggle de TV → toggle de Radio → click en "Limpiar filtros"
- **ENTONCES** el sistema devuelve HTTP 200 en cada respuesta sin emitir 429

#### Scenario: recargas rápidas de deep-link con `?date_field=program`

- **CUANDO** el cliente recarga la página `/mis-avisos?date_field=program` tres veces en menos de 60 segundos (caso típico de debugger / refresh manual durante ajustes)
- **ENTONCES** cada una de las llamadas a `/mis-avisos/history` devuelve HTTP 200

#### Scenario: combinación de recarga + filtros en 60 segundos

- **CUANDO** el cliente hace una recarga de la página y luego aplica 4-5 filtros consecutivos en la pestaña Histórico
- **ENTONCES** el total de llamadas a `/mis-avisos/history` en esa ventana es ≤ 30 y todas devuelven HTTP 200

### Requirement: 429 entrega mensaje específico al usuario

Cuando la respuesta del endpoint `/mis-avisos/history` es HTTP 429, la UI SHALL mostrar un toast efímero con el mensaje "Demasiadas solicitudes en poco tiempo. Espera un momento y vuelve a buscar." y SHALL preservar el contenido de la tabla actual (no vaciarla), de modo que el operador distinga entre "no hay resultados" y "se agotó el cupo temporal".

#### Scenario: 429 con tabla previamente cargada

- **DADO** la tabla de Histórico muestra 50 filas de resultados
- **CUANDO** el cliente aplica un filtro que dispara la request número 31 dentro de 60 segundos
- **ENTONCES** el sistema responde HTTP 429
- **Y** la UI muestra el toast específico "Demasiadas solicitudes en poco tiempo. Espera un momento y vuelve a buscar."
- **Y** la tabla sigue mostrando las 50 filas previas (no se vacía)

#### Scenario: 429 con tabla vacía

- **DADO** la tabla de Histórico está vacía (sin resultados previos)
- **CUANDO** el cliente aplica un filtro que dispara el 429
- **ENTONCES** la UI muestra el mismo toast específico
- **Y** la tabla sigue mostrando el estado vacío original (no muestra un error genérico "Error al buscar")

### Requirement: Cambios rápidos de filtro coalescen en una sola request

La UI SHALL aplicar un debounce de 250 ms sobre `searchHistory()` de modo que cambios consecutivos al set de filtros (atajos de fecha, per_page, media_type, keyword_id, storage_ids) generados por interacción humana normal dentro de esa ventana generen una sola llamada HTTP al endpoint `/mis-avisos/history`, en lugar de una por cada cambio.

#### Scenario: clic rápido en 3 atajos de fecha consecutivos

- **CUANDO** el cliente hace click en "Hoy", "Ayer" y "Últimos 7 días" en menos de 250 ms entre clicks (caso típico de doble/triple click accidental)
- **ENTONCES** la UI dispara **una sola** llamada a `/mis-avisos/history` con los filtros del último atajo (no tres)

#### Scenario: cambio de per_page no es debounceable

- **CUANDO** el cliente cambia `per_page` de 25 a 50 (caso intencional, no ráfaga)
- **ENTONCES** la UI dispara la llamada después de los 250 ms del debounce (no instantáneo)
- **Y** esa es la única llamada (no hay un request previo con `per_page=25`)

#### Scenario: input de búsqueda libre NO usa debounce (mantiene Enter explícito)

- **DADO** el cliente escribe en el input de búsqueda libre del Histórico
- **CUANDO** completa su texto y presiona Enter
- **ENTONCES** la llamada se dispara inmediatamente (sin debounce de 250 ms), conservando el patrón existente `@keydown.enter="searchHistory(1)"`

### Requirement: El throttle protege contra abuso automatizado

El sistema SHALL mantener protección efectiva contra abuso: el endpoint `/mis-avisos/history` SHALL rechazar con HTTP 429 cuando el número de requests desde la misma sesión exceda el límite por minuto configurado en la ruta (actualmente `throttle:30,1` post-cambio).

#### Scenario: script automatizado que dispara 31 requests

- **CUANDO** un script automatizado ejecuta 31 requests a `/mis-avisos/history` desde la misma sesión en menos de 60 segundos
- **ENTONCES** la request número 31 (y siguientes) responde HTTP 429
- **Y** el script no puede saltarse el throttle variando headers o cookies (la clave de throttle usa la sesión autenticada)

#### Scenario: comportamiento tras pasar la ventana

- **CUANDO** el cliente recibe HTTP 429
- **Y** esperan 60 segundos sin hacer más requests
- **ENTONCES** la siguiente request a `/mis-avisos/history` devuelve HTTP 200 normalmente
