## ADDED Requirements

### Requirement: Los enlaces gestionados por un flujo de solicitud están protegidos

Un enlace compartido que pertenece a una solicitud gestionada por un flujo de entrega (solicitud de backup de producción o solicitud puntual de TV y Radio) SHALL NOT ser modificable ni eliminable desde el módulo de compartidos.

La protección SHALL aplicarse a la edición del enlace, al borrado individual y a la depuración masiva. La depuración masiva SHALL excluir los enlaces gestionados del conjunto a eliminar y SHALL reportar cuántos fueron excluidos y cuáles fueron.

El enlace SHALL seguir siendo accesible por su URL pública mientras la solicitud que lo respalda esté vigente, y SHALL dejar de servir el material cuando la solicitud se revoque.

#### Scenario: Edición de un enlace gestionado

- **WHEN** el propietario intenta cambiar la fecha de vencimiento de un enlace que pertenece a una solicitud gestionada
- **THEN** el sistema rechaza la operación e informa que el enlace pertenece a una solicitud y que debe revocarse desde el módulo que la originó

#### Scenario: Borrado de un enlace gestionado

- **WHEN** el propietario intenta eliminar un enlace que pertenece a una solicitud gestionada
- **THEN** el sistema rechaza la operación y el enlace conserva su vigencia original

#### Scenario: Depuración masiva con enlaces gestionados

- **WHEN** una depuración masiva incluye enlaces gestionados y no gestionados
- **THEN** el sistema elimina solo los no gestionados
- **AND** la respuesta reporta el conteo y los identificadores de los enlaces protegidos

#### Scenario: El enlace sigue sirviendo mientras la solicitud está vigente

- **WHEN** el titular abre la URL pública de un enlace gestionado por una solicitud viva
- **THEN** el enlace sirve el material correctamente

### Requirement: El conteo de la previsualización es el que se confirma

La previsualización de la depuración masiva SHALL devolver como `count` el número de enlaces que la operación va a eliminar, es decir, la selección menos los enlaces gestionados por un flujo de solicitud, y SHALL reportar aparte cuántos quedan protegidos en `protected_count`.

La depuración masiva SHALL comparar el `confirm_count` recibido contra ese mismo número, y SHALL rechazar la operación con `409` si no coinciden. El valor que confirma la interfaz es el `count` de la previsualización.

#### Scenario: Previsualización con enlaces protegidos

- **WHEN** la selección incluye 3 enlaces gestionados por una solicitud y 7 normales
- **THEN** la previsualización devuelve `count` 7 y `protected_count` 3

#### Scenario: Confirmación con el conteo de la previsualización

- **WHEN** el cliente confirma la depuración con `confirm_count` 7
- **THEN** la operación elimina los 7 enlaces no gestionados, deja intactos los 3 protegidos y los reporta en `protected_count` y `protected_ids`

#### Scenario: Conteo desactualizado

- **WHEN** el cliente confirma con un `confirm_count` que no corresponde a la previsualización
- **THEN** el servidor responde `409` y no borra nada
