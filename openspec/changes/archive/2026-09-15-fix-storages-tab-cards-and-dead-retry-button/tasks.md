# Tasks: fix-storages-tab-cards-and-dead-retry-button

## 1. Tarjetas del header

- [x] 1.1 Eliminar tarjetas "Pendientes hoy" y "Listos hoy" de `app/resources/views/ia/api-transcriptor/index.blade.php` (bloque de tarjetas, líneas ~152-161); dejar grid `lg:grid-cols-2` con Cantidad Total + Cantidad Real
- [x] 1.2 Eliminar helpers JS `pendientesTotal()` y `listosTotal()` (líneas ~1383-1388) y verificar que ninguna otra referencia JS/x-text los use

## 2. Botón muerto de reintento

- [x] 2.1 Eliminar el `<form method="POST" action="/ia/api-transcriptor/retry-batch">` completo (líneas ~169-179) del header de la tabla
- [x] 2.2 Grep de confirmación: cero referencias a `retry-batch` en `app/resources/views/` (solo deben quedar el comando CLI y el schedule en `routes/console.php`)

## 3. Errores en la celda del snapshot

- [x] 3.1 En la celda "Snapshot transcriptor" (líneas ~398-412), agregar línea condicional `⛔ N errores` (rojo) cuando `snapshot.current.error_count > 0`, con title con la fecha `captured_at`
- [x] 3.2 Guard de privacidad: la línea solo se renderiza si `error_count != null` (para rol cliente el payload no la incluye → no aparece)

## 4. Contador global "Errores hoy"

- [x] 4.1 En el header de la tabla (junto a "X habilitado(s) de Y", línea ~194), agregar contador reactivo "Errores hoy: N" que sume `error_count` de los snapshots ya cargados por fila (Alpine store/propiedad compartida, color rojo si N > 0, neutral en 0)
- [x] 4.2 Verificar que el contador solo suma storages con `transcription_enabled = true` y de la página visible

## 5. Verificación

- [x] 5.1 Verificar contra BD real: tomar un storage con errores del día (`SELECT ... FROM transcription_storage_snapshots ORDER BY captured_at DESC LIMIT 5`) y confirmar que la celda muestra el conteo y el contador lo suma
- [x] 5.2 Confirmar que el header ya no tiene el form de retry-batch y que la página carga sin errores de consola (x-data, Alpine)
- [x] 5.3 `php -l` sobre el Blade no aplica (plantilla); verificar render con `php artisan view:clear && curl -s ... /ia/api-transcriptor | grep -c "Errores hoy"` como smoke test admin
- [x] 5.4 Reload PHP-FPM (`systemctl reload php84-php-fpm`) post-deploy y sanity visual del módulo