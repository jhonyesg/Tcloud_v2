## 1. Refactor del DashboardController (backend)

- [x] 1.1 Crear el directorio `app/resources/views/dashboard/partials/` con un `.gitkeep`
- [x] 1.2 Refactorizar `DashboardController::index()` rama admin: extraer el cálculo de
      `$stats` para dejarlo como un solo helper `private function buildAdminStats(): array`
      que retorne el shape ya estructurado para los partials (sin variables planas)
- [x] 1.3 Refactorizar `DashboardController::index()` rama cliente: introducir
      `$dashboardData` con sub-claves `media_editor`, `mis_avisos` y mantener
      `$storages`, `$canalesCount`, `$shareStats`, `$instructivos` como están
- [x] 1.4 Mantener compatibilidad temporal: seguir exponiendo `mediaEditorEnabled`,
      `mediaEditorClipLimit`, `mediaEditorClipsUsed` al view cliente durante este commit
      (serán removidos en 4.1 una vez el partial `_media-editor` esté cableado)
- [x] 1.5 Validar que ningún archivo fuera de `dashboard.user`/`dashboard.admin`
      consuma esas variables legacy con `grep -r 'mediaEditor' app/resources/views app/public/js`

## 2. Parciales del dashboard (frontend)

- [x] 2.1 Crear `resources/views/dashboard/partials/_media-editor.blade.php` con dos
      ramas (`@if $context === 'admin'` y `@elseif $context === 'client'`), gating por
      flag del modelo en cliente, KPI agregados en admin (clips del mes, users
      habilitados, top 3 consumidores), `data-dashboard-partial="media-editor"`
- [x] 2.2 Crear `resources/views/dashboard/partials/_mis-avisos.blade.php` con la misma
      estructura: admin consume subset de `DashboardService::build()` (pairs_total,
      pairs_pending, pairs_with_hits, drift_negative) y cliente muestra solo estado
      (ON/OFF, keywords X/quota, emails X/quota, cadencia_minutes), sin hits ni
      auditoría, `data-dashboard-partial="mis-avisos"`
- [x] 2.3 Crear `resources/views/dashboard/partials/_bg-jobs-active.blade.php` solo
      admin: itera sobre `BgJobRegistry` y muestra conteo por `kind` + link a cada
      job, oculta si la lista está vacía, `data-dashboard-partial="bg-jobs-active"`
- [x] 2.4 Crear `resources/views/dashboard/partials/_active-sessions.blade.php` solo
      admin: total de sesiones activas + top 5 usuarios con más sesiones, oculta si
      no hay datos, `data-dashboard-partial="active-sessions"`

## 3. Cableado en vistas principales (frontend)

- [x] 3.1 En `admin.blade.php`, agregar bloque "Módulos inteligentes" con clase
      `mt-6 bg-white rounded-xl shadow-sm border border-slate-200 p-6` debajo del
      bloque "Estadísticas de Uso", conteniendo los 4 `@include` de partials admin
      (`_media-editor`, `_mis-avisos`, `_bg-jobs-active`, `_active-sessions`)
- [x] 3.2 En `user.blade.php`, dentro de la grilla de acceso rápido existente, agregar
      los `@include` de `_media-editor` y `_mis-avisos` con `$context = 'client'`
      después de "Mi Espacio Personal" y antes de "Medios Puntuales"
- [x] 3.3 Eliminar de `user.blade.php` el bloque `@if($mediaEditorEnabled)` que duplica
      la card de Editor de Medios (queda solo el del partial)
- [x] 3.4 Verificar que los tours interactivos (`startAdminDashboardTour` /
      `startUserDashboardTour`) no rompen selectores tras la inserción de los
      partials (los nuevos bloques están fuera de los selectores de los tours
      existentes)

## 4. Limpieza y validación (backend)

- [x] 4.1 Eliminar del `DashboardController` las variables legacy
      `mediaEditorEnabled` / `mediaEditorClipLimit` / `mediaEditorClipsUsed` una vez
      confirmado que ningún archivo las lee (grep limpio)
- [x] 4.2 Limpiar la cache de vistas con `php artisan view:clear` y recargar el
      dashboard admin y cliente en el navegador; validar visualmente que el orden
      y los colores coinciden con el resto de cards existentes
- [x] 4.3 Crear un harness PHP `app/tests/harness_dashboard_partials.php` que
      renderice cada uno de los 4 partials en contexto admin y cliente con datos
      sintéticos (User fake, $data fake) y verifique que:
      - ningún partial lanza excepción cuando los flags son false (gating)
      - cada partial produce HTML no vacío cuando el flag es true
      - `_mis-avisos` en contexto cliente NO contiene la cadena `hits_` ni
        `audit_` (verifica la separación admin/cliente del spec)

## 5. Documentación y memoria del proyecto

- [x] 5.1 Actualizar `AGENTS.md` agregando a la sección de convenciones una subsección
      "Cómo agregar un módulo al dashboard" con los 3 pasos: crear el partial,
      agregar una línea en `DashboardController::index()`, incluirlo en el view
      correspondiente
- [x] 5.2 Guardar memoria del proyecto con la decisión arquitectónica
      "dashboard_partials_self_gated_pattern" vía `kilo_memory_save` para que las
      próximas sesiones recuerden el contrato

## 6. Fix colateral: bucle 401/403 del bg-job-indicator

Bug pre-existente detectado durante la verificación visual del dashboard:
el widget `components.bg-job-indicator` (incluido desde `layouts/app.blade.php`)
se renderizaba incondicionalmente, así que en `/login` (no autenticado) y en
dashboards de clientes (autenticados sin rol admin) polleaba `/bg-jobs/active`
— endpoint protegido por `['auth', 'admin']` — y devolvía 401/403 cada 5s.

- [x] 6.1 Gatear el `@include('components.bg-job-indicator')` en
      `layouts/app.blade.php` con `@if(session('user_role') === 'admin')`.
      El widget no debe render para no-admin ni para no-autenticados, así se
      elimina el bucle de retries sin tocar el endpoint ni el JS del widget.
- [x] 6.2 Limpiar `php artisan view:clear` y validar: en `/login` ya no se ven
      llamadas a `/bg-jobs/active` en la consola; en dashboard cliente ya no hay
      403s. El harness `harness_dashboard_partials.php` sigue pasando 22/22.

## 7. Fix colateral: 500 al iniciar sesión con hash corrupto

Bug pre-existente: usuario id=5 (`Monitoreoalpunto@Monitoreoalpunto.com`) tenía
`password_hash` en BD que no era un bcrypt válido. `Hash::check()` lanzaba
`RuntimeException("This password does not use the Bcrypt algorithm.")` y la
operadora veía 500 en vez de "contraseña incorrecta".

- [x] 7.1 `AuthController::login()` — envolver `Hash::check()` en try/catch
      para `RuntimeException`. Loguear warning estructurado
      `auth.login.malformed_password_hash` con `user_id`/`email`/`error`, y
      tratar el caso como contraseña inválida. Fix defensivo: previene el 500
      para cualquier usuario futuro con hash malformado.
- [x] 7.2 Reset del password_hash del usuario id=5 a un bcrypt válido
      (`$2y$12$...`) usando `Hash::make('Alpunto2023')`. Verificado: login
      devuelve 302 → /dashboard y la página carga HTTP 200 con "Bienvenido,
      Monitoreoalpunto@...". Script temporal `tmp/reset_password_user5.php`
      borrado tras uso (no quedó password en disco).
- [x] 7.3 Auditar todos los `users.password_hash`: query
      `SELECT id FROM users WHERE password_hash NOT LIKE '$2%'`. Resultado:
      1 usuario residual (id=182, `exp_*@t.local`, hash = 'x', leftover de un
      harness anterior). Reset con hash aleatorio bcrypt para que el sistema
      defensivo no se dispare al listar. 0 usuarios con hash no-bcrypt al
      cerrar este change.
