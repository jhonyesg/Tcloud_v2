# share-security Specification

## Purpose
Garantizar que los enlaces compartidos no expongan secretos ni metadatos de otros usuarios y que los clientes HTML, API y públicos reciban un flujo de autenticación consistente.

## Requirements

### Requirement: Serialización segura de shares

Las respuestas JSON de shares SHALL incluir solo los campos necesarios para administrar o usar el enlace. SHALL incluir `has_password` cuando corresponda, pero nunca SHALL incluir `password_hash` ni otros secretos derivados.

#### Scenario: Listado con enlace protegido
- **WHEN** se solicita el listado de shares de un usuario
- **THEN** el enlace protegido contiene `has_password = true` y no contiene `password_hash`

#### Scenario: Creación o edición de enlace protegido
- **WHEN** se crea o actualiza la contraseña de un share
- **THEN** la respuesta no devuelve el hash almacenado

### Requirement: Autorización uniforme de administración

Las operaciones de detalle, edición y revocación SHALL exigir que el usuario actual sea el creador del share o un administrador autorizado. Un usuario autenticado no SHALL obtener el token ni el recurso de otro usuario conociendo el ID numérico.

#### Scenario: Detalle de share ajeno
- **WHEN** un usuario solicita `GET /shares/{id}` para un share de otro usuario
- **THEN** recibe `403` o `404` sin datos del token, archivo o contraseña

#### Scenario: Creador autorizado
- **WHEN** el creador solicita el detalle o edita su share
- **THEN** la operación continúa y la respuesta sigue excluyendo secretos

### Requirement: Autenticación HTML mediante contraseña

El sistema SHALL ofrecer un endpoint POST específico para autenticar una contraseña de share desde el formulario HTML. Una contraseña válida SHALL crear la sesión `share_auth_{token}` y redirigir al GET público; una contraseña inválida SHALL conservar el formulario y devolver un error legible sin crear la sesión.

#### Scenario: Contraseña correcta desde navegador
- **WHEN** el usuario envía el formulario de contraseña de un share vigente
- **THEN** el servidor establece la autorización de sesión y redirige a la vista pública del token

#### Scenario: Contraseña incorrecta
- **WHEN** el usuario envía una contraseña incorrecta
- **THEN** recibe `401` para clientes JSON o la vista del formulario con error, y no se crea `share_auth_{token}`

#### Scenario: Share expirado en formulario
- **WHEN** el usuario intenta autenticarse contra un share expirado
- **THEN** recibe `410` o la vista de expirado sin validar ni guardar la contraseña

### Requirement: Metadatos públicos frescos

Una respuesta pública SHALL volver a validar la existencia del share y cargar el estado actual del recurso después de cambios de revocación, renombrado, eliminación o disponibilidad. Una entrada de caché obsoleta no SHALL permitir mostrar un enlace revocado como activo.

#### Scenario: Share revocado con caché previa
- **WHEN** existía una entrada de caché y luego el share se revoca
- **THEN** la siguiente petición pública recibe `404` y no renderiza el recurso cacheado

#### Scenario: Archivo renombrado
- **WHEN** se renombra el `File` de un share vigente
- **THEN** la vista pública muestra el nombre y metadatos actuales, no una copia cacheada del nombre anterior
