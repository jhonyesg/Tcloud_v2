## 1. Migraciones (BD)

- [x] 1.1 Crear migración `add_status_to_users_table` con columna `status varchar(16) default 'active'` + CHECK constraint en `(pending, active, disabled)`
- [x] 1.2 Crear migración `create_password_tokens_table` con columnas `id, user_id (FK cascade), token_hash (index), type, expires_at, used_at (nullable), ip_created, ip_used, timestamps` + índice compuesto `(user_id, type, used_at)`
- [x] 1.3 Agregar índice único condicional sobre `token_hash` para evitar colisiones

## 2. Modelos y configuración

- [x] 2.1 Crear modelo `App\Models\PasswordToken` con fillable, casts (expires_at, used_at a datetime), relaciones (belongsTo User) y constantes `TYPE_SETUP`, `TYPE_RESET`
- [x] 2.2 Extender modelo `User` con `status` en `$fillable`, scopes `pending()`, `active()`, método helper `isActive()` y default `status = 'pending'` en boot/creating para nuevos registros
- [x] 2.3 Crear `config/email.php` con `disposable_domains` (array de ~50 dominios) y `mx_cache_ttl` (default 3600)
- [x] 2.4 Agregar `config('auth.password_token_ttl')` con default `1440` (24h en minutos) en `config/auth.php`

## 3. Servicios backend

- [x] 3.1 Crear `App\Modules\Correo\Services\EmailValidationService` con método `validate(string $email): array` que ejecuta: sintaxis, MX lookup con cache Redis (`mx:{domain}`), blocklist disposable. Manejar timeout DNS con fallback
- [x] 3.2 Crear `App\Services\Auth\PasswordTokenService` con métodos: `issue(User $user, string $type): string` (devuelve token raw), `consume(string $token, ?string $ip): ?User`, `invalidate(User $user, string $type): void`
- [x] 3.3 En `PasswordTokenService::issue`, invalidar tokens previos del mismo `(user, type)` antes de crear el nuevo
- [x] 3.4 En `PasswordTokenService::consume`, aplicar side-effect por tipo: `setup` materializa password + status active + crea storage si username; `reset` solo cambia password_hash
- [x] 3.5 Registrar `PasswordTokenService` y `EmailValidationService` en `AppServiceProvider` con sus dependencias

## 4. Plantilla de correo

- [x] 4.1 Agregar plantilla `bienvenida-setup` en `App\Modules\Correo\Database\Seeders\CorreoPlantillaSeeder` con subject "Bienvenido a TCloud - {{nombre_usuario}}", variables `nombre_usuario, set_password_url, expiracion` y body HTML con botón "Establecer contraseña"
- [x] 4.2 Ejecutar seeder para registrar/actualizar la plantilla sin duplicar (`updateOrCreate` por `name`)

## 5. Controladores (backend)

- [x] 5.1 Inyectar `EmailValidationService` y `PasswordTokenService` en `UserController` por constructor
- [x] 5.2 En `UserController::store`, agregar validación de entregabilidad del email antes del `User::create` (422 si falla); pasar `status='pending'` al crear; emitir token `setup` y enviar `bienvenida-setup` automáticamente (mantener compat del flag `send_email`)
- [x] 5.3 En `AuthController::login`, después de validar password, rechazar con mensaje claro si `user->status !== 'active'`
- [x] 5.4 En `AuthController::forgotPassword`, inyectar `EmailValidationService` y `PasswordTokenService`; validar entregabilidad antes de buscar user; emitir token `reset` solo si user existe y está `active`; siempre devolver la misma respuesta genérica
- [x] 5.5 En `AuthController::resetPassword`, reemplazar lookup en `Session` por lookup en BD vía `PasswordTokenService::consume`; al consumir exitosamente, redirigir al login con mensaje de éxito
- [x] 5.6 Añadir métodos `showSetupPassword(string $token)` y `setupPassword(Request $request)` en `AuthController` que usen el token `setup`; en `setupPassword` materializar password, activar user, crear storage si username

## 6. Vistas y rutas

- [x] 6.1 Crear vista `resources/views/auth/setup-password.blade.php` clonando el patrón de `resources/views/auth/reset-password.blade.php` (Blade + Alpine, mínimo un password + password_confirmation + token hidden)
- [x] 6.2 Agregar rutas en `routes/web.php`: `GET /auth/setup-password/{token}` (middleware `throttle:5,1`) y `POST /auth/setup-password` (mismo throttle)

## 7. Pruebas manuales y validación

- [x] 7.1 Levantar entorno y verificar que creación de user con correo válido dispara `bienvenida-setup` y deja al user en `pending`
- [x] 7.2 Verificar que el link de setup permite setear password y transicionar a `active`
- [x] 7.3 Verificar que login es rechazado para user `pending` con mensaje claro
- [x] 7.4 Verificar que `forgot-password` con correo inválido o user inexistente devuelve la MISMA respuesta genérica sin enviar correo
- [x] 7.5 Verificar que `forgot-password` con correo válido y user existente `active` envía `recuperar-password` con link que funciona al abrirlo en otro navegador (token en BD)
- [x] 7.6 Verificar que el admin puede seguir cambiando password vía `UserController::update` para users con correo inválido (path de rescate intacto)
- [x] 7.7 Verificar que `UserController::destroy` limpia los `password_tokens` asociados (cascade via FK ya cubre)

## 8. Documentación y limpieza

- [x] 8.1 Actualizar `README.md` con una nota breve sobre el nuevo flow de bienvenida + set-password
- [x] 8.2 Correr `php artisan config:cache`, `route:cache`, `view:clear` en deploy
- [x] 8.3 Validar el change con `openspec validate user-email-validation-and-welcome --strict`
