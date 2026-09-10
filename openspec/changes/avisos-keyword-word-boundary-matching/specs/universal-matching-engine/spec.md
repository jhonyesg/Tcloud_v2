# Delta — universal-matching-engine

## ADDED Requirements

### Requirement: El conjunto de keywords del scan solo produce hits con frontera de palabra

El motor universal SHALL aplicar la frontera de palabra (no subcadena) al evaluar cada keyword contra cada segmento: una keyword se considera presente en un segmento solo si aparece como palabra completa bajo la normalización del motor. El pre-filtro rápido por subcadena SHALL poder conservarse como optimización, pero su resultado positivo SHALL validarse con la frontera antes de persistir el hit.

#### Scenario: El scan universal no persiste hits de prefijos
- **WHEN** el scan de una transcripción evalúa la keyword "petro" contra un segmento con "petróleo"
- **THEN** el segmento no produce hit para esa keyword, aunque el substring matchea

## MODIFIED Requirements

### Requirement: Las coincidencias se persisten compartidas e idempotentes

El sistema SHALL persistir cada coincidencia en una tabla compartida con unicidad por (transcripción, segmento, keyword), de modo que la coincidencia exista una sola vez sin importar cuántos usuarios la reciban, y re-ejecutar el scan no duplique filas. Una coincidencia SHALL existir solo cuando la keyword matchea con frontera de palabra; re-indexar una keyword tras un cambio de regla de matching SHALL reemplazar (no duplicar) sus hits previos para las transcripciones re-escaneadas.

#### Scenario: Coincidencia compartida se guarda una vez
- **WHEN** 3 clientes reciben el match de "caracol" en el segmento 5 de la misma transcripción
- **THEN** existe una sola fila de coincidencia para (esa transcripción, segmento 5, keyword caracol) y cada cliente la referencia por la intersección de su acceso

#### Scenario: Re-ejecución no duplica
- **WHEN** el scan se re-ejecuta para la misma transcripción
- **THEN** no se crean filas de coincidencia nuevas ni notificaciones duplicadas

#### Scenario: Re-indexación bajo nueva regla reemplaza hits
- **WHEN** la keyword "petro" se re-indexa tras activar la frontera de palabra y una transcripción contenía hits de "petróleo"
- **THEN** esos hits se eliminan y solo persiste el hit de la aparición con frontera válida, sin duplicados por la unicidad triple