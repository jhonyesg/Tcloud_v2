# Delta — mention-occurrence-detail

## MODIFIED Requirements

### Requirement: Cada hit registra cuántas veces la keyword aparece en su segmento

El motor SHALL contar, al generar cada hit, cuántas veces aparece la keyword dentro del texto del segmento (conteo insensible a mayúsculas y tildes, misma normalización del motor, contando únicamente apariciones con frontera de palabra) y SHALL persistirlo en la columna `occurrences` del hit. Los hits existentes SHALL ser recalculados por backfill en la migración. La columna SHALL ser visible para el cliente en feed e histórico como contador de apariciones ("×N") cuando sea mayor a 1.

#### Scenario: Palabra repetida dentro del mismo segmento
- **WHEN** el motor genera un hit cuyo segmento contiene la keyword 5 veces como palabra completa
- **THEN** el hit persiste `occurrences = 5` y el visor lo muestra como "×5"

#### Scenario: Apariciones sin frontera no cuentan
- **WHEN** el motor genera un hit sobre un segmento con "petro, petrolero y Petromil" y la keyword es "petro"
- **THEN** el hit persiste `occurrences = 1` (solo la palabra completa cuenta)

#### Scenario: Hits históricos retroalimentados
- **WHEN** corre la migración sobre hits existentes
- **THEN** cada hit tiene su `occurrences` recalculado a partir del texto actual de su segmento

### Requirement: El modal permite navegar a cada aparición dentro del segmento

El modal de transcripción SHALL resaltar cada aparición de la keyword dentro de un segmento y SHALL permitir al cliente pulsar cada aparición para que el reproductor busque su tiempo aproximado, interpolado desde la posición relativa del match dentro del texto del segmento (entre `start_seconds` y `end_seconds`). El conteo del hit SHALL coincidir con el número de resaltados navegables del segmento ancla. El resaltado de la keyword de la mencion SHALL usar la misma regla de frontera que el motor, de modo que subcadenas internas ("petróleo" para "petro") no generen resaltados fantasma que inflen el conteo navegable; el resaltado de la búsqueda manual libre conserva su comportamiento por subcadena.

#### Scenario: El cliente salta a la tercera aparición
- **WHEN** un hit marca 5 ocurrencias en el segmento ancla y el cliente pulsa la tercera aparición resaltada
- **THEN** el reproductor busca el tiempo interpolado correspondiente a esa aparición

#### Scenario: Conteo y resaltados coinciden
- **WHEN** el modal abre con el ancla de un hit con `occurrences = 1` sobre un segmento con "petro, petrolero y Petromil"
- **THEN** el segmento ancla muestra exactamente 1 resaltado navegable de la keyword (el de frontera válida) y "petróleo"/"Petromil" quedan sin resaltar