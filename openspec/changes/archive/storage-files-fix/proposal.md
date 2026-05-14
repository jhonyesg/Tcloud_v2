## Why

El módulo "Mis Archivos" tiene tres problemas relacionados con el manejo de storages locales/NFS:

**1. UNIQUE constraint global en `path`**
La tabla `files` tiene `UNIQUE(path)` global. Los archivos se guardan con rutas relativas (ej. `Puntual_01`), sin incluir el storage al que pertenecen. Cuando dos storages diferentes tienen archivos con el mismo nombre en root, el segundo intento de sync lanza una excepción de DB — 500 silencioso que el frontend convierte en lista vacía. Confirmado: storage 58 ("Puntuales Media") falla porque storage 130 ya registró `Puntual_01`, `Puntual_02`, `Puntual_03`.

**2. Sync on-demand bloqueante y lento**
Cada vez que el usuario entra a un storage o navega a una carpeta, el backend escanea el disco en tiempo real (`scandir` sobre NFS). En discos montados via NFS esto puede tardar varios segundos por carpeta. El usuario percibe la UI como lenta o congela el browse. Storages con muchos archivos en subdirectorios son especialmente lentos.

**3. Badge "Accesible" demasiado llamativo**
157 de 165 storages tienen `is_accessible = false` y muestran un badge rojo con texto "No Accesible". La vista de storages queda visualmente dominada por rojo. El usuario solo necesita saber de un vistazo si hay problema — un círculo de color es suficiente.

## What Changes

### A — Corrección de UNIQUE constraint en `files.path`

- Migración que elimina el UNIQUE constraint global sobre `path` y lo reemplaza por un índice compuesto UNIQUE `(path, storage_provider_id)`.
- Actualmente hay 491 registros sin colisiones, la migración no requiere limpieza de datos existentes.
- El constraint actual también bloquea el correcto funcionamiento del `SyncStorage` artisan command existente para storages con paths comunes.

### B — Sistema de pre-indexación periódica (background sync)

En lugar de escanear el disco en cada request:

- **Artisan command mejorado**: extender `storage:sync` para soportar `--all` y sincronizar todos los storages locales.
- **Scheduler**: registrar en `routes/console.php` un schedule que corra `storage:sync --all` cada 15 minutos.
- **Comportamiento de browse**:
  - Si el storage YA tiene archivos indexados en DB → servir desde DB directo (sin tocar el disco).
  - Si el storage NO tiene archivos indexados aún → hacer sync on-demand una vez y guardar en DB.
  - Agregar un botón "Actualizar" en la vista de archivos que fuerce el sync del storage actual.
- **is_accessible update**: al correr el sync, actualizar `is_accessible` y `last_checked_at` del storage según si el path es accesible o no.

### C — Badge de accesibilidad como dot

Reemplazar el badge de texto en la tabla de storages (`files/index.blade.php`) por un círculo de 10px:

```
Actual:  [bg-red "No Accesible"]  [bg-green "Accesible"]
Nuevo:   ●  (rojo)                ●  (verde)
```

Con tooltip al hover mostrando "Accesible" / "No verificado" / fecha de último check.

## Non-goals

- No implementar sync de storages tipo S3 (solo local/NFS en este cambio).
- No modificar la estructura de paths ya guardados en la BD (solo el constraint).
- No hacer sync en tiempo real vía websockets ni filesystem watchers.
- No modificar el flujo de S3 ni las rutas de admin.

## Affected Files

- `app/database/migrations/` — nueva migración para fix de UNIQUE constraint
- `app/app/Console/Commands/SyncStorage.php` — extender con `--all` y update de `is_accessible`
- `app/routes/console.php` — agregar schedule cada 15 minutos
- `app/app/Http/Controllers/FileController.php` — cambiar lógica: servir desde DB si ya indexado, sync solo si vacío
- `app/resources/views/files/index.blade.php` — badge → dot con tooltip, botón "Actualizar" en vista de archivos
