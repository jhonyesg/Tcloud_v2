# E2E tests

Suit de Playwright para validación end-to-end de los cambios con impacto de UI.

| Suite | Change asociado | Cobertura |
|---|---|---|
| `files-storages.spec.mjs` | `files-storages-fk-aware-prune` | A-E: 5 casos sobre transiciones de storage y FK |
| `validate-config-ui.spec.mjs` | `enrich-config-ui-transistor-settings` + 3 changes transcriptor | Render del panel Configuración: iconos, badges scope/state, acordeón de detalle, captura de warnings/errors de consola |

## `validate-config-ui.spec.mjs`

Suite de validación visual/de UI para los 4 cambios del módulo API Transcriptor
del 2026-09-15 (purge de vocabulario Redis, fixes de tests, exposición de
settings, enriquecimiento con iconos/badges/detalle).

### Prerrequisitos

1. APP_URL accesible.
2. Credenciales admin (campo `login` acepta email O username — ver
   `resources/views/auth/login.blade.php`).
3. `chromium-1234` instalado (`npx playwright install chromium`).

### Variables de entorno

| Variable | Default | Función |
|---|---|---|
| `APP_URL` | `https://cloud.mediaserver.com.co` | URL base del servidor |
| `ADMIN_LOGIN` | (sin default, requerido) | usuario o email admin |
| `ADMIN_PASSWORD` | (sin default, requerido) | password admin |
| `CHROMIUM_PATH` | `/root/.cache/ms-playwright/chromium-1234/chrome-linux64/chrome` | binario de Chromium |

### Ejecución

```bash
APP_URL=https://cloud.mediaserver.com.co \
ADMIN_LOGIN=jsuarez \
ADMIN_PASSWORD='...' \
node tests/e2e/validate-config-ui.spec.mjs
```

### Salida

- PASS/FAIL por escenario en stdout (5 escenarios: A login, B render,
  B.1 iconos grupo, B.2 iconos knob, B.3 badges, C acordeón, D
  warnings/errors).
- Screenshots en `tests/e2e/screenshots/validate-*.png`.
- Reporte JSON en `tests/e2e/screenshots/report-config-ui-<timestamp>.json`.

### Casos cubiertos

| ID | Escenario | Qué valida |
|---|---|---|
| A.0 | Login admin | El server responde sin 500 |
| B.0 | Render | ≥6 grupos y ≥40 knobs en panel Configuración |
| B.1 | Iconos grupo | 10 grupos con icono FontAwesome |
| B.2 | Iconos knob | Muestreo de 10 knobs con icono a la izquierda |
| B.3 | Badges scope | LOCAL en dispatch_paused; MIXTO+EXPERIMENTAL en submit_with_callback |
| C.1 | Acordeón | Click "Ver detalle" expande 3 secciones (alcance, cuándo tocar, riesgos) |
| D.1 | Captura | Warnings + errors de consola + page errors + failed requests |

### Notas

- **Credenciales nunca en el archivo**: ADMIN_LOGIN y ADMIN_PASSWORD son
  requeridas via env. Si faltan, aborta con código 2.
- El reporte JSON enmascara el admin_login (`js***@dominio` o `js***`).
- Las warnings de consola se reportan pero no son fail. Los uncaught
  exceptions en página sí son fail (suelen indicar bug real).
- Testeálo después de un deploy, antes de marcar un change como completo.

---

# (suite legacy)

A continuación, la documentación de la suite anterior:

## Prerrequisitos

1. Las dos migraciones aplicadas:
   ```bash
   php artisan migrate --force
   ```
2. Sesiones Redis sanas (ver `AGENTS.md` — bug histórico del 2026-09-05).
3. Al menos un storage con `kind='external'` y accesible.
4. Credenciales admin válidas.

## Variables de entorno

| Variable | Default | Función |
|---|---|---|
| `APP_URL` | `https://cloud.mediaserver.com.co` | URL base del servidor |
| `ADMIN_EMAIL` | `admin@local` | Email del admin para login |
| `ADMIN_PASSWORD` | `admin1234` | Password del admin |
| `TEST_STORAGE_ID` | `5` | ID del storage usado en los tests |

## Ejecución

```bash
APP_URL=https://cloud.mediaserver.com.co \
ADMIN_EMAIL=jsuarez@mediaclouding.com \
ADMIN_PASSWORD='...' \
TEST_STORAGE_ID=5 \
node tests/e2e/files-storages.spec.mjs
```

## Salida

- PASS/FAIL por escenario en stdout
- Screenshots en `tests/e2e/screenshots/`
- Reporte JSON en `tests/e2e/screenshots/report-<timestamp>.json`

## Casos cubiertos

| ID | Escenario | Qué valida |
|---|---|---|
| A.0 | Login admin | El server responde sin 500 |
| B.1 | Banner aparece | `is_accessible=false` → banner amarillo visible |
| B.2 | Banner desaparece | `is_accessible=true` → banner oculto tras navegación |
| C.1 | Transcripción preservada | DELETE no se ejecuta sobre filas con FK aguas abajo |
| C.2 | Fila preservada | `availability_state` cambia a `missing`/`unknown`, no DELETE |
| D.1 | Dry-run purga | `files:prune-unlinked-safe --dry-run` devuelve conteos |
| E.1 | Watchdog tick | `storage:health --once` registra transiciones |

## Notas

- Los tests **mutan BD** (UPDATE storage_providers, UPDATE files). El bloque
  final restaura `is_accessible=true` en el storage de prueba, pero si la
  suite se aborta entre B y Cleanup puede dejar el storage caído. Si pasa,
  ejecuta:
  ```bash
  PGPASSWORD=cloud123 psql -h 127.0.0.1 -U cloud -d tcloudstorage \
    -c "UPDATE storage_providers SET is_accessible = true WHERE id = 5;"
  ```
- Si el server no responde (HTTP 500 por bug de sesiones), la suite aborta
  en A.0 con código 2 y un screenshot del error. No se ejecuta el resto.