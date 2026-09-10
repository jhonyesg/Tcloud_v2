# avisos-keyword-word-boundary Specification

## Purpose

Define que el matching de menciones de keywords opere por palabra completa (fronteras Unicode-safe) en vez de por subcadena cruda, y que las keywords ya escaneadas puedan re-indexarse bajo la nueva regla sin duplicar datos ni perder auditoría de watermarks.

## Requirements

### Requirement: El matching de keywords solo acepta coincidencias con frontera de palabra

El motor de menciones SHALL aceptar una coincidencia de keyword sobre el texto de un segmento únicamente cuando la posición del match esté delimitada por fronteras de palabra: el carácter anterior al inicio del match y el carácter posterior al final NO SHALLN ser letra ni dígito Unicode (`\p{L}` / `\p{N}`). La comparación SHALL operar sobre la misma normalización del motor (lowercase + transliteración ASCII), de modo que "petro" matchee "Petro" y "Gustavo Petro" pero NO "petróleo", "Petromil" ni "petrismo". Un match de subcadena que falle la frontera SHALL descartarse sin persistir hit ni contarse como aparición. Este requisito aplica a todos los motores de matching (universal, backfill retroactivo y fallback legacy).

#### Scenario: Prefijo de palabra derivada se descarta
- **WHEN** un segmento contiene "puede ser Petróleo" y la keyword normalizada es "petro"
- **THEN** no se persiste hit para ese segmento

#### Scenario: Marca con prefijo se descarta
- **WHEN** un segmento contiene "Bienvenidos a Petromil" y la keyword normalizada es "petro"
- **THEN** no se persiste hit para ese segmento

#### Scenario: Palabra completa aislada matchea
- **WHEN** un segmento contiene "el gobierno de Petro" y la keyword normalizada es "petro"
- **THEN** se persiste un hit para ese segmento

#### Scenario: Nombre compuesto matchea sin keyword adicional
- **WHEN** un segmento contiene "Gustavo Petro" y la keyword normalizada es "petro"
- **THEN** se persiste un hit (la palabra completa "petro" existe dentro de la frase)

#### Scenario: Acento en la palabra vecina no rompe la frontera
- **WHEN** un segmento contiene "Petro, dijo" (coma después) o "á Petro á" y la keyword es "petro"
- **THEN** se persiste el hit (puntuación y espacios son fronteras válidas; el carácter UTF-8 multibyte adyacente se evalúa completo, no por byte)

#### Scenario: Subcadena interna de una palabra se descarta
- **WHEN** un segmento contiene "subpetrolero" y la keyword normalizada es "petro"
- **THEN** no se persiste hit (frontera izquierda falla)

### Requirement: El conteo de apariciones por segmento solo cuenta coincidencias con frontera

Al generar un hit, el conteo de `occurrences` SHALL contar únicamente las apariciones de la keyword que cumplen la frontera de palabra dentro del segmento, y el número de resaltados navegables del visor SHALL coincidir con ese conteo.

#### Scenario: Segmento con mezcla de coincidencias válidas e inválidas
- **WHEN** un segmento contiene "petróleo, Petro y Petromil" y la keyword es "petro"
- **THEN** el hit persiste `occurrences = 1` y el visor muestra un solo resaltado navegable para ese segmento

### Requirement: Las keywords ya escaneadas pueden re-indexarse bajo la nueva regla

El sistema SHALL exponer un comando de mantenimiento que, para una keyword dada: (a) elimine sus hits existentes en `segment_keyword_hits`, (b) rebobine los watermarks de sus pares `(keyword_id, storage_provider_id)` vía el servicio central de watermarks (no SQL inline), y (c) re-escanee las transcripciones candidatos con el motor vigente. La operación SHALL registrar la mutación de watermark en el log de auditoría y SHALL poder ejecutarse en seco (`--dry-run`) para contar los hits a eliminar sin mutar nada.

#### Scenario: Re-escaneo limpia falsos positivos históricos
- **WHEN** el operador ejecuta la re-indexación para la keyword "petro" y luego consulta sus hits
- **THEN** los hits derivados de "petróleo", "Petromil" y similares ya no existen, y los hits de "Petro" como palabra completa siguen presentes

#### Scenario: Dry-run no muta
- **WHEN** el operador ejecuta la re-indexación con la bandera de simulación
- **THEN** se reporta el número de hits que serían eliminados y no se borra ni re-escanea nada

#### Scenario: Rewind queda auditado
- **WHEN** la re-indexación rebobina los watermarks de la keyword
- **THEN** el log de auditoría de watermarks registra la operación con actor, acción y valores before/after

### Requirement: La creación de keywords rechaza textos por debajo de la longitud mínima

La creación de keywords (feed del cliente y panel del admin) SHALL rechazar un texto cuya forma normalizada tenga menos de 3 caracteres, con mensaje de validación visible, para impedir keywords genéricas que matchearían casi todo el corpus.

#### Scenario: Keyword demasiado corta se rechaza
- **WHEN** el cliente intenta crear la keyword "el" o "a"
- **THEN** la petición falla con error de validación y no se crea la keyword ni se consume cuota

#### Scenario: Keyword de longitud justa se acepta
- **WHEN** el cliente crea la keyword "petro" (5 caracteres normalizados)
- **THEN** la creación procede normalmente