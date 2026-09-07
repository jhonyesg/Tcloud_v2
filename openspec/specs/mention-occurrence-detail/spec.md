# mention-occurrence-detail Specification

## Purpose
TBD - created by archiving change 2026-09-07-mention-occurrence-counting. Update Purpose after archive.

## Requirements

### Requirement: Cada hit registra cuántas veces la keyword aparece en su segmento

El motor SHALL contar, al generar cada hit, cuántas veces aparece la keyword dentro del texto del segmento (conteo insensible a mayúsculas y tildes, misma normalización del motor) y SHALL persistirlo en la columna `occurrences` del hit. Los hits existentes SHALL ser recalculados por backfill en la migración. La columna SHALL ser visible para el cliente en feed e histórico como contador de apariciones ("×N") cuando sea mayor a 1.

#### Scenario: Palabra repetida dentro del mismo segmento
- **WHEN** el motor genera un hit cuyo segmento contiene la keyword 5 veces
- **THEN** el hit persiste `occurrences = 5` y el visor lo muestra como "×5"

#### Scenario: Hits históricos retroalimentados
- **WHEN** corre la migración sobre hits existentes
- **THEN** cada hit tiene su `occurrences` recalculado a partir del texto actual de su segmento

### Requirement: El modal permite navegar a cada aparición dentro del segmento

El modal de transcripción SHALL resaltar cada aparición de la keyword dentro de un segmento y SHALL permitir al cliente pulsar cada aparición para que el reproductor busque su tiempo aproximado, interpolado desde la posición relativa del match dentro del texto del segmento (entre `start_seconds` y `end_seconds`). El conteo del hit SHALL coincidir con el número de resaltados navegables del segmento ancla.

#### Scenario: El cliente salta a la tercera aparición
- **WHEN** un hit marca 5 ocurrencias en el segmento ancla y el cliente pulsa la tercera aparición resaltada
- **THEN** el reproductor busca el tiempo interpolado correspondiente a esa aparición

#### Scenario: Conteo y resaltados coinciden
- **WHEN** el modal abre con el ancla de un hit con `occurrences = 5`
- **THEN** el segmento ancla muestra exactamente 5 resaltados navegables de la keyword

### Requirement: Atajo de filtro por keyword desde el chip de la mencion

El chip "mencion: <keyword>" del header del modal SHALL ser un boton que activa el filtro de la transcripcion por esa keyword con un clic (mismo filtro que la busqueda manual, con el conteo visible), y SHALL desactivarlo con un segundo clic. La busqueda manual dentro del modal sigue funcionando de forma independiente.

#### Scenario: Un clic filtra por la keyword de la mencion
- **WHEN** el cliente pulsa el chip "mencion: alvaro uribe"
- **THEN** la lista de segmentos queda filtrada a los que contienen la keyword, con el chip resaltado como activo y el conteo visible

#### Scenario: Segundo clic quita el filtro
- **WHEN** el filtro por keyword esta activo y el cliente vuelve a pulsar el chip
- **THEN** el filtro se retira y la lista vuelve a mostrar la ventana completa

### Requirement: La tabla muestra el total de apariciones de la keyword en toda la grabación

Las filas de feed e historico SHALL incluir el total de apariciones de la keyword en TODA la grabacion (suma de ocurrencias de todos sus hits para esa keyword), calculado en el servidor en una consulta agrupada por pagina. La tabla SHALL presentarlo como columna propia ("Apariciones"), y cuando un punto concreto tenga varias apariciones en su segmento SHALL indicarlo como detalle secundario ("xN aqui").

#### Scenario: Varias apariciones distribuidas en la grabacion
- **WHEN** una grabacion tiene la keyword 3 veces en 2 segmentos distintos
- **THEN** cada fila de esa grabacion muestra la columna "Apariciones" con x3, y la fila cuyo segmento contiene 2 de ellas anade "x2 aqui"
