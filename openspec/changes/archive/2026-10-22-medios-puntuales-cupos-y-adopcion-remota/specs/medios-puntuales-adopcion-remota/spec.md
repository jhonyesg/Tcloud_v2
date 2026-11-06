## Purpose

Detecta, clasifica e integra de forma no destructiva los canales que existen en el grabador remoto y no tienen fila correspondiente en `canales`, para que lo que el operador ve en Medios Puntuales refleje lo que el equipo realmente está grabando y para que el cupo por usuario cuente la realidad.

## ADDED Requirements

### Requirement: Detección de canales remotos sin fila local

El sistema SHALL comparar, por cada `Grabador` activo, los canales devueltos por `GET {base_url}/canales` contra las filas de `canales` del mismo `grabador_id`, usando como claves de identidad `api_canal_id` y `slot_nombre` normalizado (`trim` + minúsculas). Un remoto SHALL considerarse **detectado** cuando no empareja por ninguna de las dos claves.

#### Scenario: Remoto sin fila local es detectado

- **WHEN** el grabador devuelve `{id: 202, codigo: "Solicitudes_05", nombre: "Solicitudes_05"}` y `canales` no tiene fila con `api_canal_id = 202` ni `slot_nombre` normalizado `solicitudes_05` para ese `grabador_id`
- **THEN** el canal aparece en `remotos_detectados`

#### Scenario: Remoto con fila local por codigo en minúsculas no es detectado

- **WHEN** el grabador devuelve `{id: 173, codigo: "solicitudes_01", nombre: "Solicitudes_01"}` y existe la fila local `slot_nombre = 'Solicitudes_01'`
- **THEN** el canal se clasifica como `vinculado` o `ya_ok`, NUNCA como detectado

#### Scenario: Canal local sin remoto se reporta como sin_match

- **WHEN** existe la fila local `slot_nombre = 'tv_Siglo_05'` y el grabador no devuelve ningún canal con ese código ni nombre
- **THEN** la fila se cuenta en `sin_match` y NO se elimina

### Requirement: Clasificación por confianza de adopción

Cada canal detectado SHALL clasificarse con `confianza ∈ {adoptable, sugerido, fuera_de_alcance}` aplicando la regla estricta: `adoptable` requiere que la `ruta_descarga` del remoto sea **hijo directo** del `ruta_base` de un par `(grabador, usuario)` y que el prefijo alfabético de su `codigo` coincida con el de al menos un `slot_nombre` local de ese par. Los remotos que viven bajo un `ruta_base` pero no cumplen la condición de prefijo SHALL clasificarse `sugerido`. El resto SHALL clasificarse `fuera_de_alcance`.

#### Scenario: Canal de la misma familia numerica es adoptable

- **WHEN** el remoto `Solicitudes_05` tiene `ruta_descarga` igual a `{ruta_base}/Solicitudes_05` del par `(1, 11)` y ese par tiene los slots locales `Solicitudes_01`…`Solicitudes_04`
- **THEN** `confianza = 'adoptable'`

#### Scenario: Canal de otra familia bajo el mismo ruta_base es solo sugerido

- **WHEN** el remoto `Telemedellin` tiene `ruta_descarga` igual a `{ruta_base}/Telemedellin` del par `(3, 11)` cuyos slots locales son `tv_Siglo_01`…`tv_Siglo_10`
- **THEN** `confianza = 'sugerido'`
- **AND** no se ofrece adopción en un solo clic

#### Scenario: Canal en un subdirectorio anidado no es adoptable

- **WHEN** el remoto tiene `ruta_descarga = {ruta_base}/RD1/Antioquia/BLU`
- **THEN** `confianza = 'sugerido'` como máximo, NUNCA `adoptable`

#### Scenario: Canal fuera de todo ruta_base conocido

- **WHEN** la `ruta_descarga` del remoto no está bajo el `ruta_base` de ningún par
- **THEN** `confianza = 'fuera_de_alcance'` y no se ofrece adopción

### Requirement: Adopción no destructiva

Adoptar un canal detectado SHALL consistir únicamente en un `INSERT` en `canales` que espeje los datos del remoto, asignando `grabador_id` y `usuario_id` del par cuyo `ruta_base` contiene la ruta. La adopción NUNCA SHALL emitir `POST`, `PUT` ni `DELETE` contra el grabador. La adopción SHALL ser idempotente: repetirla para un `api_canal_id` ya adoptado SHALL reportarse como `ya_ok` sin duplicar filas.

#### Scenario: Adoptar Solicitudes_05 crea la fila local

- **WHEN** el operador adopta el remoto `{id: 202, codigo: 'Solicitudes_05', ruta_descarga: '{base}/Solicitudes_05'}` en el par `(1, 11)`
- **THEN** se crea una fila en `canales` con `grabador_id = 1`, `usuario_id = 11`, `slot_nombre = 'Solicitudes_05'`, `api_canal_id = 202`, `ruta_destino` igual a la ruta remota y `link_origen`/`detalle` copiados del remoto
- **AND** el grabador remoto no recibe ninguna petición de escritura

#### Scenario: Adopción repetida es idempotente

- **WHEN** el operador adopta dos veces el mismo `api_canal_id`
- **THEN** la segunda invocación reporta `ya_ok`
- **AND** `canales` mantiene exactamente una fila para ese `api_canal_id`

#### Scenario: Adopción re-verifica el remoto antes de insertar

- **WHEN** el canal remoto desapareció entre la detección y la adopción (`GET /canales/{id}` devuelve 404)
- **THEN** ese ítem se omite con `warning`
- **AND** no se crea ninguna fila local

#### Scenario: Formato de salida nulo se resuelve por tipo de grabador

- **WHEN** el remoto trae `formato_salida = null`
- **THEN** la fila local se crea con el default del `grabadores.tipo` del par (`.mp3` para `radio`, `.mp4` para `tv`)
- **AND** el hecho queda registrado en el log de la adopción

#### Scenario: Adopción no altera el cupo de otros usuarios

- **WHEN** se adopta un canal del par `(1, 11)`
- **THEN** el `limite_canales` y el uso de `(1, 9)` y `(3, 11)` permanecen sin cambios

### Requirement: La adopción no está sujeta al cupo

Adoptar un canal detectado SHALL proceder aunque el resultado deje `usados > limite_canales` para el par. El canal ya existe y graba en el grabador: negarse a registrarlo no lo desactiva, solo lo vuelve invisible. La respuesta de adopción SHALL informar el estado resultante del par (`usados`, `limite`, `excedido`) para que el administrador ajuste el límite desde el mismo panel.

#### Scenario: Adoptar deja el par en el tope

- **WHEN** el par `(1, 11)` tiene `limite_canales = 5` y 4 canales, y el operador adopta `Solicitudes_05`
- **THEN** la fila local se crea
- **AND** la respuesta reporta `usados = 5`, `limite = 5`, `excedido = false`

#### Scenario: Adoptar cuando el par ya excede el límite

- **WHEN** el par tiene `limite_canales = 4` y 4 canales, y se adopta un remoto nuevo
- **THEN** la fila local se crea igualmente
- **AND** la respuesta reporta `usados = 5`, `limite = 4`, `excedido = true`
- **AND** el panel muestra el par en rojo con la acción de subir el límite

### Requirement: Detección accesible desde CLI

El comando `canales:detectar-remotos` SHALL estar disponible con `--grabador=`, `--user=`, `--json` y `--adoptar`, SHALL ejecutar en modo dry-run por defecto y SHALL imprimir por cada canal detectado su `id`, `codigo`, `ruta_descarga`, par propuesto y `confianza`.

#### Scenario: Dry-run no muta la base

- **WHEN** se ejecuta `php artisan canales:detectar-remotos --grabador=1`
- **THEN** imprime los canales detectados con su confianza
- **AND** el conteo de filas en `canales` no cambia

#### Scenario: Adopción solo de confianza adoptable

- **WHEN** se ejecuta `php artisan canales:detectar-remotos --adoptar --grabador=1`
- **THEN** solo se insertan los canales con `confianza = 'adoptable'`
- **AND** los `sugerido` se listan pero no se insertan

#### Scenario: Salida JSON para operación

- **WHEN** se ejecuta con `--json`
- **THEN** la salida es un objeto JSON con los cubos `vinculados`, `ya_ok`, `sin_match`, `remotos_detectados` y `fuera_de_alcance`
