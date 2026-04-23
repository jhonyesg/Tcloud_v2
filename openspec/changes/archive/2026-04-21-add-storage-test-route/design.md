## Context

El `StorageProviderController::test()` (línea 85) ya está implementado pero no tiene ruta definida. El método:
- Para storage local: verifica `file_exists()`, `is_dir()` y `is_readable()` del `base_path`
- Para storage S3: verifica credenciales con `headBucket()`

El problema es solo que falta la ruta en `web.php`.

## Goals / Non-Goals

**Goals:**
- Agregar la ruta `GET /admin/storages/{storage}/test`

**Non-Goals:**
- No modificar el método `test()` del controlador (ya funciona)
- No cambiar la lógica de verificación

## Decisions

**Decisión**: Usar Route::get() explícito en lugar de agregar al resource

El resource de Laravel no incluye métodos custom como `test()`. Se necesita agregar la ruta explícitamente dentro del grupo admin.

```php
Route::get('/storages/{storage}/test', [App\Http\Controllers\StorageProviderController::class, 'test']);
```

Esta ruta se agrega junto con las otras rutas custom de storages (líneas 21-24 del web.php actual).

## Risks / Trade-offs

- **Riesgo**: None - es una ruta simple que apunta a método existente
- **Trade-off**: Ninguno - es la forma correcta de extender resource routes

## Migration Plan

1. Agregar `Route::get('/storages/{storage}/test', ...)` en `web.php`
2. Verificar que el botón "Probar" funciona

**Rollback**: Eliminar la línea de ruta agregada
