## Why

Hay usuarios en la BD con correos inválidos (typos, direcciones muertas o fake) y la recuperación de contraseña les dispara correos que rebotan silenciosamente. En paralelo, los usuarios nuevos se crean hoy con un flag opcional `send_email` que depende de que el admin se acuerde de marcarlo. Necesitamos que (1) cualquier correo al que vayamos a enviar un mensaje sea entregable, (2) los usuarios nuevos reciban automáticamente la bienvenida con un enlace para establecer su contraseña, y (3) la recuperación de contraseña de los usuarios existentes funcione solo cuando el correo es válido, con respuesta genérica (sin leak de existencia) para los demás.

## What Changes

- Añade `EmailValidationService` que valida sintaxis RFC + lookup MX (DNS) + blocklist de dominios disposable (mailinator, guerrillamail, tempmail, etc.). Sin SMTP RCPT TO probe.
- Añade tabla `password_tokens` (token_hash, user_id, type `setup|reset`, expires_at, used_at, ip_created, ip_used) y `PasswordTokenService` que reemplaza el token en `Session` actual (bug latente: el link solo funciona en el mismo browser).
- Migra `AuthController::forgotPassword` y `AuthController::resetPassword` para usar `PasswordTokenService` (token en BD en lugar de sesión). El rate-limit y la respuesta genérica se mantienen.
- Nueva plantilla `bienvenida-setup` que reemplaza a `bienvenida`: incluye `{{set_password_url}}` con expiración de 24h.
- `UserController::store` valida el correo vía `EmailValidationService` antes de crear (422 si no es entregable). Al crear, siempre emite un token `setup` y envía `bienvenida-setup` (el flag `send_email` queda obsoleto pero se conserva por compatibilidad).
- Añade endpoints `GET /auth/setup-password/{token}` y `POST /auth/setup-password` para que el usuario nuevo establezca su contraseña; al confirmar, `users.password_hash` se materializa y `users.status` (nuevo) pasa a `active`.
- Añade columna `users.status` (`pending|active|disabled`, default `pending` para nuevos). `AuthController::login` rechaza `!= active` con mensaje claro.
- Cuando un usuario entra por setup o reset, el servicio emite side-effect por tipo: `setup` materializa password + status active (+ crea storage personal si username); `reset` solo cambia password.

**BREAKING**: `AuthController::forgotPassword` deja de guardar token en `$_SESSION`. El link de recuperación ahora se persiste en BD, por lo que sobrevive cambio de navegador/dispositivo. La URL pública del link (`/auth/reset-password/{token}`) no cambia.

## Capabilities

### New Capabilities
- `user-email-deliverability`: servicio que decide si un correo es entregable (sintaxis + MX + disposable), consumido por store de users, forgot-password y cualquier emisor futuro.
- `user-password-tokens`: tabla `password_tokens`, `PasswordTokenService` con `issue(type)`, `consume(token, ip)`, `invalidate(user, type)`. Soporta tipos `setup` y `reset` con side-effect diferenciado.
- `user-welcome-and-setup-password`: flujo de bienvenida automática para usuarios nuevos (plantilla `bienvenida-setup`, endpoints `/auth/setup-password/*`, columna `users.status`).

### Modified Capabilities
- (ninguna existente con cambios de requirement — `login-by-username` y `user-search-typeahead` no cambian su contrato).

## Impact

- **Migración**: nueva tabla `password_tokens`, nueva columna `users.status` (default `active` para los existentes para no romper logins; solo los nuevos que nazcan sin password serán `pending`).
- **Modelos**: nuevo `PasswordToken` (Eloquent), `User` extendido con `status`, helper `isActive()` / `isPending()`.
- **Servicios**: nuevo `App\Modules\Correo\Services\EmailValidationService`; nuevo `App\Modules\Correo\Services\PasswordTokenService` (o bajo `App\Services\Auth\`).
- **Controladores**: `UserController::store` validando correo y emitiendo token; `AuthController::forgotPassword`/`resetPassword`/`login` migrados a BD; nuevo método `setupPassword` y `showSetupPassword`.
- **Vistas**: nueva vista `auth.setup-password` (Blade + Alpine, mismo patrón que `auth.reset-password`).
- **Plantillas correo**: nueva `bienvenida-setup` (reemplaza a `bienvenida` para usuarios nuevos; `bienvenida` se conserva para usos manuales).
- **Rutas nuevas**: `GET /auth/setup-password/{token}`, `POST /auth/setup-password`.
- **Riesgos**: si el lookup MX agrega latencia perceptible al crear users o pedir recuperación, cachear resultado MX por TTL (Redis) con key `mx:{domain}`. Si un dominio tiene DNS flaky, la validación cae a "validar sintaxis y descartar disposable" sin romper el flow.

## Non-goals

- No se migran ni se limpian usuarios existentes con correos inválidos. Ellos siguen como están; el admin los rescata vía `UserController::update` cambiando password directo.
- No se agrega re-envío de bienvenida desde la UI admin (queda para otro change).
- No se agrega UI de "usuarios con correo inválido" en admin (queda para otro change).
- No se cambia el `send_email` flag en el form de creación de users — se conserva por compatibilidad aunque la bienvenida ya se envíe siempre que el correo sea válido.
- No se hace SMTP RCPT TO probe (lento, intrusivo, muchos servidores lo bloquean).
- No se agrega captcha ni rate-limit nuevo (el `throttle:5,1` actual cubre `/auth/reset-password`; `/auth/setup-password` recibe el mismo middleware).
