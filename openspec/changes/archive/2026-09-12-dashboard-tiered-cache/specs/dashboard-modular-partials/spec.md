## MODIFIED Requirements

### Requirement: Dashboard admin expone resumen de módulos inteligentes

El sistema SHALL mostrar en `/dashboard` (rol admin) un nuevo bloque "Módulos inteligentes" debajo del bloque "Estadísticas de Uso" que contiene las tarjetas de los partials `_media-editor`, `_mis-avisos`, `_bg-jobs-active` y `_active-sessions`, siempre que sus respectivos flags agregados sean mayores que cero. Los datos de las tarjetas pueden provenir de cache de tiers (frío/tibio), por lo que cada tarjeta SHALL renderizarse con el valor disponible sin bloquear la carga. La tarjeta `_mis-avisos` SHALL reflejar mutaciones recientes de watermarks (invalidación por epoch) aun cuando el resto de sus KPIs provenga de cache.

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

#### Scenario: Cobertura refleja mutación reciente aunque los demás KPIs estén cacheados
- **WHEN** ocurre una mutación de watermarks que incrementa el epoch de cobertura
  y el admin carga `/dashboard` con el tier frío vigente en cache
- **THEN** la tarjeta `_mis-avisos` muestra el estado de cobertura posterior a la
  mutación, aunque `storage_used` u otros KPIs fríos provengan de cache
