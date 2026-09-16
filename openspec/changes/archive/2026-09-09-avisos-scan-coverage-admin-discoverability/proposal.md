## Why

El módulo de avisos inteligentes tiene UI completa (pestañas Cobertura, Auditoría, polling) pero un admin nuevo no la descubre salvo que conozca la URL interna `/ia/avisos-inteligentes`. Además, la cache de cobertura se invalida automáticamente vía `CacheEpoch::bump()` cuando el `WatermarkReconciler` muta, pero un admin que hace `INSERT` directo en `keyword_scan_watermarks` (operación documentada como rara pero posible) no tiene cómo forzar la invalidación. Cierra los huecos #1 y #4 del análisis del módulo pendientes.

## What Changes

- **(1) Discoverability**: enlace visible en el sidebar admin (`@/admin`) que lleva a `/ia/avisos-inteligentes?activeTab=cobertura` con ícono y label "Avisos Inteligentes". Los enlaces existentes del módulo no se tocan. Tabla de cobertura + auditoría accesible con 1 click desde el sidebar.
- **(2) Invalidación manual de cache**: nuevo comando CLI `avisos:reset-cache-coverage` que ejecuta `CacheEpoch::bump()` (incrementa el contador, invalida toda la cache key de `coveragePaginated`). Sin opciones. Output: nuevo epoch + qué se invalidó (mensaje claro).
- **(3) Documentación de caveats**: en `AGENTS.md`, agregar una sección "Caveats del módulo de cobertura" listando las 3 situaciones donde la cache puede mentir al admin (mutationes directas por SQL bypass, race condition teórica aceptada entre CacheEpoch y SELECT, `coverage()` deprecado). Sin código nuevo — solo docblock.
- Sin migraciones. Sin breaking changes.

## Capabilities

### New Capabilities
- `avisos-scan-coverage-admin-discoverability`: entry point desde navegación admin + invalidación manual de cache.

### Modified Capabilities
- (ninguno — sin cambio de comportamiento observable)

## Impact

- **Archivos modificados**:
  - `app/resources/views/layouts/app.blade.php` (agregar 1 enlace en grupo admin)
  - `app/app/Console/Commands/ResetCoverageCacheCommand.php` (nuevo)
  - `AGENTS.md` (sección nueva con caveats)
- **Tests nuevos**:
  - `tests/harness_coverage_cache_invalidation.php`: verifica que el comando incrementa el epoch y que la siguiente lectura de coverage ve datos frescos.
- **Sin downtime, sin migración**.
- **Riesgo**: ninguno operativo. El comando CLI es idempotente y el sidebar link es puramente visual. El doc no afecta comportamiento.
