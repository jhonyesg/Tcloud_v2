## Context

Hoy conviven tres fricciones: (a) el token de recuperación vive en `$_SESSION`, lo que rompe el flow cuando el usuario abre el link en otro navegador; (b) el admin debe recordar marcar el flag `send_email` para que el usuario nuevo reciba bienvenida, y aun así el link no incluye un mecanismo para establecer contraseña; (c) los usuarios existentes con correos inválidos reciben correos que rebotan. Este diseño abordará las tres sin tocar los flujos de admin-edit-password, que siguen siendo el camino de rescate. La motivación detallada está en `proposal.md`.

## Goals / Non-Goals

**Goals:**
- Introducir un servicio de validación de correo reutilizable, cacheado por dominio, que decida entregabilidad sin filtrar existencia de usuario.
- Persistir los tokens de setup y reset en BD, unificando su ciclo de vida detrás de un solo servicio.
- Disparar la bienvenida con link de setup automáticamente en cada creación de usuario.
- Permitir que el usuario nuevo establezca su contraseña sin intervención del admin.

**Non-Goals:**
- No se migran ni limpian usuarios existentes con correos inválidos.
- No se agrega UI admin para re-enviar bienvenida ni para listar usuarios con correo inválido.
- No se modifica el flag `send_email` del form de creación; queda como compatibilidad, pero la bienvenida se envía siempre que el correo sea entregable.
- No se cambia el endpoint admin de update de password (UserController::update), que sigue siendo la salida para correos inválidos.

## Decisions

### Decision: EmailValidationService basado en DNS MX + blocklist local, sin SMTP probe
**Por qué MX:** consultar registros MX es barato (consulta DNS local, sin terceros) y filtra el grueso de typos y dominios muertos. **Por qué no SMTP RCPT TO:** es lento, muchos servidores lo bloquean, y en hosting compartido genera falsos negativos por listas grises. **Por qué blocklist local:** los dominios disposable cambian, pero una lista corta de los ~50 más comunes cubre los casos típicos. La lista vive en `config/email.php` para fácil mantenimiento.
**Alternativas consideradas:** APIs externas (Hunter, ZeroBounce) descartadas por costo y dependencia externa en un servidor dedicado self-hosted.

### Decision: Cache de MX en Redis con TTL 1h por dominio
**Por qué:** en una ráfaga de creación de usuarios (importación masiva) o recovery attacks, no queremos golpear DNS N veces para el mismo dominio. **Por qué Redis y no APCu:** ya hay Redis en el stack, y permite invalidación central si un dominio deja de tener MX (TTL corto = 1h es aceptable).
**Alternativas consideradas:** APCu descartado porque solo es local a un worker PHP-FPM; cache a nivel de query DNS descartado por complejidad.

### Decision: Token en tabla `password_tokens` con hash SHA-256, no encriptado
**Por qué:** solo necesitamos verificar la igualdad, no recuperar el token. SHA-256 del token aleatorio de 32 bytes da 256 bits de entropía efectiva y permite lookup por índice.
**Alternativas consideradas:** guardar el token encriptado con Laravel `Crypt` — más costoso, no aporta nada si la BD está comprometida (el atacante también tiene la llave).

### Decision: Una sola tabla para `setup` y `reset`, discriminada por `type`
**Por qué:** los side-effects están en el consumidor, no en la tabla. Compartir la tabla permite constraints cruzados (single-active-per-type) y un único cron de limpieza.
**Alternativas consideradas:** dos tablas separadas descartadas por duplicación de lógica y dificultad para limpiar tokens vencidos.

### Decision: Token TTL 24h, configurable vía `config('auth.password_token_ttl')`
**Por qué:** 24h es el estándar de la industria para este tipo de flow. Configurable para casos extremos.
**Alternativas consideradas:** 1h (UX frágil, usuario pierde el mail), 7 días (ventana de ataque más amplia).

### Decision: `users.status` con default `active` para existentes, `pending` para nuevos
**Por qué:** los usuarios actuales ya tienen password y pueden loguear; forzar `pending` los bloquearía a todos. La migración debe preservar operatividad.
**Alternativas consideradas:** forzar setup a todos los existentes descartado por costo operacional enorme.

### Decision: Reemplazar `bienvenida` por `bienvenida-setup` solo para usuarios nuevos
**Por qué:** la actual `bienvenida` no tiene link de setup; crear una nueva evita romper el envío manual que hoy hace el admin al crear (que NO requiere set-password porque el admin ya puso contraseña).
**Alternativas consideradas:** modificar `bienvenida` agregando link opcional — agrega complejidad condicional al template; preferimos un template dedicado.

### Decision: Mantener `send_email` flag del form admin, ignorarlo si el correo es válido
**Por qué:** compat hacia atrás con integraciones y tests existentes; el flag se respeta solo cuando el correo NO es válido (no se envía nada en ese caso).
**Alternativas consideradas:** eliminar el flag — rompe compat y tests, sin valor agregado real.

### Decision: Login bloqueado si `status != 'active'`
**Por qué:** un usuario `pending` no tiene password materializado; cualquier intento de login falla porque el hash es aleatorio no-usable. Mejor rechazar explícitamente con mensaje claro.
**Alternativas consideradas:** dejar que el login falle por password incorrecta — genera confusión y consultas al admin; preferimos mensaje explícito.

## Risks / Trade-offs

- **MX lookup agrega latencia a `forgot-password`** → mitigación: cache de MX en Redis con TTL 1h, fallback de timeout a "sintaxis + disposable only" si DNS no responde.
- **DNS del propio servidor caído** → fallback a sintaxis + disposable; el flow sigue funcionando, solo se pierde la capa MX.
- **Lista disposable desactualizada** → un dominio nuevo no incluido podría colar correos temporales; mitigación aceptable: no es un vector crítico, solo reduce UX.
- **Migración `users.status` con default `active` deja la BD en estado implícito** → mitigación: la columna se llena en el INSERT; los nuevos que llegan sin password serán `pending`. No hay riesgo de inconsistencia.
- **Re-envío de bienvenida no implementado en este change** → un admin que se equivoca al crear debe borrar y volver a crear (o esperar a otro change). Mitigación: documentar en tasks.md.
- **Token de reset podría usarse desde otro país/dispositivo del atacante si el user filtra el link** → mitigación: el link llega al correo del user; mientras el correo no esté comprometido, está seguro. TTL 24h limita la ventana.

## Migration Plan

1. **Desplegar migraciones** (en este orden estricto):
   - `add_status_to_users_table` (default `active` para preservar existentes; nuevos a `pending` se manejan en código).
   - `create_password_tokens_table`.
2. **Desplegar modelos y servicios** (código nuevo, sin uso aún):
   - `App\Models\PasswordToken`.
   - `App\Modules\Correo\Services\EmailValidationService`.
   - `App\Services\Auth\PasswordTokenService`.
3. **Desplegar plantilla `bienvenida-setup`** vía seeder (idempotente con `updateOrCreate`).
4. **Switch de comportamiento** en `UserController::store` y `AuthController::forgotPassword`/`resetPassword`/`login` — esta es la release flag real.
5. **Rollback**: revertir migraciones con `down()`, el flag `send_email` queda intacto (compat). El único dato persistido de valor son los tokens emitidos; al revertir la migración se pierden sin pérdida de negocio (un admin puede re-enviar bienvenida recreando el user).

## Open Questions

- ¿La validación MX debe ejecutarse también al actualizar el email de un user existente? Hoy `UserController::update` no valida entregabilidad. Lo dejamos para otro change; en este no se toca.
- ¿Debe `bienvenida-setup` incluir el username sugerido en el correo? Hoy no lo hace. Lo dejamos para iteración futura.
