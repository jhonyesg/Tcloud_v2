# E2E tests para `files-storages-fk-aware-prune`

Suite Playwright que cubre los 5 casos de uso del change.

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