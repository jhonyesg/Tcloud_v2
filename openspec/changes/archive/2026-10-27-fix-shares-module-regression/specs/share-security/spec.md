## MODIFIED Requirements

### Requirement: Serialización segura de shares

Las respuestas JSON de shares SHALL incluir solo los campos necesarios para administrar o usar el enlace. SHALL incluir `has_password` cuando corresponda, pero nunca SHALL incluir `password_hash` ni otros secretos derivados.

Esta obligación SHALL alcanzar **toda** respuesta que devuelva un share, incluidos el alta, el detalle y la edición. El campo `password_hash` SHALL quedar excluido de la serialización del modelo de share, de modo que ninguna ruta de la API pueda filtrarlo por descuido. La respuesta de alta y la de edición SHALL incluir el identificador del enlace y los campos de gestión, y SHALL NOT incluir el valor cifrado de la contraseña.

#### Scenario: Listado con enlace protegido

- **WHEN** se solicita el listado de shares de un usuario
- **THEN** el enlace protegido contiene `has_password = true` y no contiene `password_hash`

#### Scenario: Creación o edición de enlace protegido

- **WHEN** se crea o actualiza la contraseña de un share
- **THEN** la respuesta no devuelve el hash almacenado
- **AND** la respuesta no contiene ninguna clave derivada del secreto

#### Scenario: Detalle de enlace protegido

- **WHEN** se solicita el detalle de un share protegido por contraseña
- **THEN** la respuesta indica `has_password = true` y no expone `password_hash`

### Requirement: Autorización uniforme de administración

Las operaciones de detalle, edición y revocación SHALL exigir que el usuario actual sea el creador del share o un administrador autorizado. Un usuario autenticado no SHALL obtener el token ni el recurso de otro usuario conociendo el ID numérico.

El detalle de un share SHALL exigir la misma condición que la edición y la revocación. Un share ajeno SHALL responder con `403` o `404` y SHALL NOT revelar si el ID existe, a quién pertenece ni cuál es su token.

#### Scenario: Detalle de share ajeno

- **WHEN** un usuario solicita `GET /shares/{id}` para un share de otro usuario
- **THEN** recibe `403` o `404` sin datos del token, archivo o contraseña

#### Scenario: Creador autorizado

- **WHEN** el creador solicita el detalle o edita su share
- **THEN** la operación continúa y la respuesta sigue excluyendo secretos

#### Scenario: Usuario no autenticado

- **WHEN** una petición de administración de shares llega sin sesión
- **THEN** el servidor responde con error de autorización y no ejecuta la operación

#### Scenario: Identificador inexistente

- **WHEN** un usuario solicita el detalle de un identificador que no existe
- **THEN** recibe `404` y la respuesta no distingue ese caso del share ajeno

### Requirement: Autenticación HTML mediante contraseña

El sistema SHALL ofrecer un endpoint POST específico para autenticar una contraseña de share desde el formulario HTML. Una contraseña válida SHALL crear la sesión `share_auth_{token}` y redirigir al GET público; una contraseña inválida SHALL conservar el formulario y devolver un error legible sin crear la sesión.

El endpoint SHALL estar registrado y ser alcanzable desde el formulario de contraseña que el sistema presenta al visitante. El formulario SHALL apuntar a ese endpoint, y el sistema SHALL NOT presentar un formulario de contraseña cuyo envío no pueda completarse. El endpoint SHALL rechazar la autenticación contra un share expirado antes de evaluar la contraseña.

#### Scenario: Contraseña correcta desde navegador

- **WHEN** el usuario envía el formulario de contraseña de un share vigente
- **THEN** el servidor establece la autorización de sesión y redirige a la vista pública del token

#### Scenario: Contraseña incorrecta

- **WHEN** el usuario envía una contraseña incorrecta
- **THEN** recibe `401` para clientes JSON o la vista del formulario con error, y no se crea `share_auth_{token}`

#### Scenario: Share expirado en formulario

- **WHEN** el usuario intenta autenticarse contra un share expirado
- **THEN** recibe `410` o la vista de expirado sin validar ni guardar la contraseña

#### Scenario: El formulario de contraseña siempre es operable

- **WHEN** el sistema presenta el formulario de contraseña de un share protegido
- **THEN** el envío del formulario es atendido por un endpoint registrado y funcional
- **AND** el visitante SHALL NOT recibir un error de servidor al confirmar su contraseña

### Requirement: Metadatos públicos frescos

Una respuesta pública SHALL volver a validar la existencia del share y cargar el estado actual del recurso después de cambios de revocación, renombrado, eliminación o disponibilidad. Una entrada de caché obsoleta no SHALL permitir mostrar un enlace revocado como activo.

La caché pública SHALL almacenar únicamente el identificador del share y su expiración, y el estado del recurso SHALL resolverse en cada respuesta a partir de la fila vigente. Un share revocado SHALL invalidar su entrada de caché en el momento de la revocación, tanto individual como en bloque, de modo que la siguiente petición pública no pueda resolverlo.

#### Scenario: Share revocado con caché previa

- **WHEN** existía una entrada de caché y luego el share se revoca
- **THEN** la siguiente petición pública recibe `404` y no renderiza el recurso cacheado

#### Scenario: Archivo renombrado

- **WHEN** se renombra el `File` de un share vigente
- **THEN** la vista pública muestra el nombre y metadatos actuales, no una copia cacheada del nombre anterior

#### Scenario: Revocación en bloque

- **WHEN** se revocan varios shares en una misma operación
- **THEN** ninguno de los tokens revocados resuelve en la siguiente petición pública
