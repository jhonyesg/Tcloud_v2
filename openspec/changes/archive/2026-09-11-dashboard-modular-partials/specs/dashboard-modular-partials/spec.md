## Purpose

Define el contrato de los partials modulares del dashboard (admin y cliente) para que
cada módulo inteligente del sistema pueda exponer un resumen de su estado en
`/dashboard` sin que el view principal ni el controller tengan que conocer la lógica
interna del módulo. El patrón es auto-gated: cada partial decide si renderiza según
el flag del modelo del usuario.

## ADDED Requirements

### Requirement: Cada partial expone un gating explícito

El sistema SHALL provee un directorio `resources/views/dashboard/partials/` donde cada
partial Blade declara explícitamente el flag del modelo que controla su visibilidad.
Cuando el flag evalúa falso, el partial SHALL retornar vacío sin lanzar excepciones
ni romper el layout circundante.

#### Scenario: Módulo deshabilitado en cliente
- **WHEN** un cliente sin módulo de Editor de Medios habilitado (campo
  `users.media_editor_enabled = false` y rol distinto de admin) visita `/dashboard`
- **THEN** el partial `_media-editor` no renderiza ninguna tarjeta ni marcador visible

#### Scenario: Módulo habilitado en cliente
- **WHEN** un cliente con `media_editor_enabled = true` y `media_editor_clip_limit > 0`
  visita `/dashboard`
- **THEN** el partial `_media-editor` renderiza la tarjeta con el conteo
  `clips_usados / límite` del mes en curso

#### Scenario: Cliente sin módulo Mis Avisos configurado
- **WHEN** un cliente sin fila en `user_alerts_inteligentes` (o con
  `enabled = false`) visita `/dashboard`
- **THEN** el partial `_mis-avisos` no renderiza ninguna tarjeta

### Requirement: Parciales reciben un contexto explícito

El sistema SHALL pasar a cada partial una variable `$context` con valor `'admin'`
o `'client'` para que el mismo partial pueda servir a ambos dashboards sin
acoplamiento al rol del usuario. Adicionalmente SHALL pasar `$user` cuando aplique
gating por-usuario y `$data` con la forma documentada en el header del partial.

#### Scenario: Mismo partial usado en admin y cliente
- **WHEN** el `DashboardController` invoca el partial `_media-editor` con
  `$context = 'admin'` y `$data` con shape admin
- **THEN** el partial renderiza la card agregada del módulo (totales globales, top
  consumidores, KPI de cobertura)

- **WHEN** el `DashboardController` invoca el partial `_media-editor` con
  `$context = 'client'` y `$user` con `media_editor_enabled = true`
- **THEN** el partial renderiza la tarjeta personal con cuota mensual

### Requirement: Dashboard admin expone resumen de módulos inteligentes

El sistema SHALL mostrar en `/dashboard` (rol admin) un nuevo bloque "Módulos
inteligentes" debajo del bloque "Estadísticas de Uso" que contiene las tarjetas de
los partials `_media-editor`, `_mis-avisos`, `_bg-jobs-active` y `_active-sessions`,
siempre que sus respectivos flags agregados sean mayores que cero.

#### Scenario: Admin con módulo de Editor activo
- **WHEN** el admin visita `/dashboard` y existe al menos un usuario con
  `media_editor_enabled = true`
- **THEN** la tarjeta `_media-editor` muestra el total de clips globales del mes
  en curso y la cantidad de usuarios con el editor habilitado

#### Scenario: Admin con cobertura Mis Avisos saludable
- **WHEN** el admin visita `/dashboard` y `DashboardService::build()` retorna
  `drift_negative = 0`
- **THEN** la tarjeta `_mis-avisos` muestra los KPI `pairs_total`,
  `pairs_pending`, `pairs_with_hits` y el indicador de drift en verde sin
  acción visible

#### Scenario: Admin con drift negativo en cobertura
- **WHEN** el admin visita `/dashboard` y `DashboardService::build()` retorna
  `drift_negative > 0`
- **THEN** la tarjeta `_mis-avisos` muestra el número de drift en rojo

#### Scenario: Admin sin jobs de background activos
- **WHEN** el admin visita `/dashboard` y `BgJobRegistry` retorna lista vacía
- **THEN** el partial `_bg-jobs-active` no renderiza ningún bloque

### Requirement: Dashboard cliente expone tarjetas modulares en la grilla de acceso rápido

El sistema SHALL mostrar en `/dashboard` (rol cliente) las tarjetas de los partials
habilitados para ese usuario intercaladas en la grilla de acceso rápido existente,
ubicadas después de "Mi Espacio Personal" y antes de "Medios Puntuales" según el
orden declarado por el controller.

#### Scenario: Cliente con Editor de Medios y Mis Avisos habilitados
- **WHEN** un cliente con `media_editor_enabled = true` y
  `user_alerts_inteligentes.enabled = true` con `keywords_quota > 0` visita
  `/dashboard`
- **THEN** la grilla de acceso rápido contiene tanto la tarjeta de Editor de
  Medios (con cuota mensual) como la tarjeta de Mis Avisos (con estado del módulo)

#### Scenario: Cliente con Mis Avisos deshabilitado
- **WHEN** un cliente con `user_alerts_inteligentes.enabled = false` o sin fila
  en `user_alerts_inteligentes` visita `/dashboard`
- **THEN** la grilla de acceso rápido no contiene la tarjeta de Mis Avisos

### Requirement: Card cliente de Mis Avisos solo expone estado, no actividad

El sistema SHALL restringir la tarjeta `_mis-avisos` en contexto cliente a mostrar
únicamente el estado del módulo (ON/OFF, cuota de keywords usada y total, emails
configurados vs cuota, cadencia en minutos). SHALL NOT incluir hits de los últimos
períodos, último match, alertas enviadas, ni ninguna métrica que dependa de las
tablas `keyword_matches` o `alert_logs`.

#### Scenario: Cliente con keywords y emails configurados
- **WHEN** un cliente con el módulo Mis Avisos habilitado visita `/dashboard`
- **THEN** la tarjeta muestra el formato `X / Y keywords`, la cantidad de emails
  configurados y la cadencia elegida en minutos, sin métricas de actividad

### Requirement: Datos de auditoría y drift quedan restringidos al admin

El sistema SHALL mostrar los datos derivados de `watermark_audit_log` y el detalle
del desglose de drift (`missing`, `orphan`) únicamente en el contexto admin. El
cliente SHALL NOT recibir en ningún partial datos de estas tablas ni sus agregados.

#### Scenario: Cliente no recibe datos de auditoría
- **WHEN** el `DashboardController` arma el payload para contexto cliente
- **THEN** las variables pasadas a los partials no contienen claves con datos de
  `watermark_audit_log` ni con `drift_missing` / `drift_orphan`

### Requirement: Parciales son testeables de forma aislada

Cada partial SHALL poder renderizarse en un test unitario instanciando solo el
view con un array de variables, sin necesidad de un `User::find()` ni un request
HTTP completo. La forma documentada en el header del partial SHALL ser suficiente
para invocar `view('dashboard.partials._X', [...])` directamente.

#### Scenario: Render directo del partial sin modelo
- **WHEN** un test invoca `view('dashboard.partials._media-editor',
  ['context' => 'client', 'user' => $fakeUser, 'data' => [...]])`
- **THEN** el partial renderiza la tarjeta esperada con los datos provistos,
  sin requerir queries adicionales

### Requirement: Costo de agregar un módulo nuevo al dashboard

El sistema SHALL permitir agregar un módulo nuevo al dashboard mediante la creación
de un único partial en `resources/views/dashboard/partials/` y una sola línea
adicional en `DashboardController::index()` que pase `$context` y `$data`. SHALL
NOT requerir ediciones a `admin.blade.php` ni a `user.blade.php` para módulos que
ya tengan su ubicación visual determinada.

#### Scenario: Nuevo módulo hipotético "Reportes Avanzados"
- **WHEN** un mantenedor crea `resources/views/dashboard/partials/_reportes.blade.php`
  con gating por `$data['enabled']` y agrega `@include('dashboard.partials._reportes',
  ['context' => 'client', 'user' => $user, 'data' => $data['reportes']])` en el
  `DashboardController`
- **THEN** la tarjeta aparece automáticamente en el dashboard del cliente sin haber
  editado `admin.blade.php` ni `user.blade.php`, y respeta el gating cuando el flag
  es false

### Requirement: Widget global de jobs no se renderiza para no-admin

El widget global `components.bg-job-indicator` (incluido desde
`layouts/app.blade.php`) SHALL renderizarse únicamente cuando
`session('user_role') === 'admin'`. Cuando el usuario no está autenticado
(por ejemplo en `/login`) o está autenticado con rol distinto de admin,
el widget SHALL NO incluirse en el HTML resultante.

#### Scenario: Login sin sesión
- **WHEN** un visitante no autenticado carga `/login`
- **THEN** el HTML resultante no contiene el contenedor del widget
  `bg-job-indicator` y por tanto no se dispara ninguna llamada a
  `/bg-jobs/active` en la consola del navegador

#### Scenario: Cliente autenticado
- **WHEN** un usuario con rol `user` carga cualquier vista que extienda
  `layouts.app` (incluido `/dashboard`)
- **THEN** el HTML resultante no contiene el contenedor del widget
  `bg-job-indicator` y no se disparan llamadas a `/bg-jobs/active`

#### Scenario: Admin autenticado
- **WHEN** un usuario con rol `admin` carga cualquier vista que extienda
  `layouts.app`
- **THEN** el HTML resultante incluye el contenedor del widget
  `bg-job-indicator` y el polling a `/bg-jobs/active` ocurre normalmente
