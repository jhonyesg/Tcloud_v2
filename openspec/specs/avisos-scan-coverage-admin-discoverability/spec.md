# avisos-scan-coverage-admin-discoverability Specification

## Purpose
Garantiza que el módulo de cobertura de watermarks sea descubrible desde la navegación admin estándar y que la cache pueda invalidarse manualmente cuando se hace una mutación directa en BD fuera del flujo del Reconciler.

## Requirements

### Requirement: Enlace visible del módulo en el sidebar admin

El sidebar admin SHALL tener un enlace titled "Avisos Inteligentes" con ícono `fa-radar` que apunta a `/ia/avisos-inteligentes?activeTab=cobertura`. El enlace SHALL renderizarse solo cuando el usuario autenticado tiene rol admin. Al hacer click, SHALL llevar a la pestaña Cobertura (por default). El enlace SHALL incluir data-nav-path para que el resaltado SPA funcione igual que los demás links del sidebar.

#### Scenario: Admin navega al módulo desde el sidebar
- **WHEN** el admin abre el sidebar y busca el módulo de avisos
- **THEN** ve el enlace "Avisos Inteligentes" en el grupo IA/Admin y al hacer click aterriza en la pestaña Cobertura

#### Scenario: Enlace se oculta para usuarios no-admin
- **WHEN** un usuario sin rol admin carga el sidebar
- **THEN** el enlace NO se renderiza (mismo gating que el resto de opciones admin)

#### Scenario: Click desde SPA
- **WHEN** el admin hace click desde la navegación del sidebar
- **THEN** la URL cambia a `/ia/avisos-inteligentes?activeTab=cobertura` y la pestaña Cobertura se muestra activa sin recarga completa

### Requirement: Comando CLI para invalidar cache de cobertura manualmente

El sistema SHALL ofrecer `php artisan avisos:reset-cache-coverage` (admin only conceptually — sin middleware porque corre desde CLI) que ejecuta `CacheEpoch::bump()` para invalidar TODA la cache de `coveragePaginated`. El comando SHALL imprimir el epoch antes y después, más un mensaje confirmando qué se invalidó (todas las combinaciones de filtros). SHALL ser idempotente: ejecutar N veces tiene el mismo efecto observable que ejecutar una vez + mutaciones reales entremedio.

#### Scenario: Admin invalida cache después de INSERT manual
- **WHEN** el admin ejecuta `php artisan avisos:reset-cache-coverage` después de un INSERT directo en `keyword_scan_watermarks`
- **THEN** el siguiente GET a `/scan/coverage` ve el cambio sin esperar 60s

#### Scenario: Comando idempotente
- **WHEN** el admin ejecuta el comando dos veces sin mutaciones entremedio
- **THEN** ambas ejecuciones retornan exit 0 y la UI sigue funcionando idénticamente

### Requirement: Documentación de caveats del módulo en AGENTS.md

El archivo `AGENTS.md` SHALL contener una sección "Caveats del módulo de cobertura de watermarks" listando las 3 situaciones donde el modelo tiene comportamiento conocido-no-óptimo aceptable: (a) mutaciones directas en `keyword_scan_watermarks` por SQL bypass que invalidan la cache solo si el admin ejecuta el comando de reset; (b) race condition teórica aceptada entre `CacheEpoch` y lecturas concurrentes donde el orden de bumps no garantiza qué lectura ve qué epoch; (c) `AvisosScanService::coverage()` deprecado pero existente — callers externos no se enteran de la deprecación. Cada caveat incluye el workaround concreto.

#### Scenario: Mantenedor futuro lee AGENTS.md sobre cache de cobertura
- **WHEN** un desarrollador revisa `AGENTS.md` para entender la cache de cobertura
- **THEN** encuentra la sección "Caveats" listando las 3 situaciones conocidas con su workaround (comando CLI, atomicidad, deprecation warning)
