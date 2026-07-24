## 1. Migración y backfill

- [x] 1.1 Crear migración `add_ruta_base_to_grabador_usuario_table` que agregue `ruta_base VARCHAR(449) NULL` a `grabador_usuario` después de `limite_canales`
- [x] 1.2 En la misma migración, ejecutar backfill: para cada par `(grabador_id, user_id)` con al menos un `canales.ruta_destino` no nulo, derivar `ruta_base` quitando el último segmento (slot_nombre) del primer canal encontrado y escribirlo en el pivote (solo donde `ruta_base IS NULL`)
- [x] 1.3 Verificar el backfill: `SELECT grabador_id, user_id, ruta_base FROM grabador_usuario WHERE ruta_base IS NOT NULL` devuelve al menos el par de `siglohallon`

## 2. GrabadorController — persistir ruta_base en el pivote

- [x] 2.1 En `GrabadorController::asignarUsuario` (en el `DB::transaction`), agregar `'ruta_base' => $rutaBase` al `DB::table('grabador_usuario')->insert([...])` (línea ~185)
- [x] 2.2 En `GrabadorController::actualizarAsignacion`, agregar `ruta_base` al array `$pivotUpdate` cuando el request trae `ruta_base` filled (línea ~251)
- [x] 2.3 Verificar manualmente: asignar un usuario nuevo con `ruta_base`, leer `SELECT ruta_base FROM grabador_usuario WHERE ...`

## 3. CanalController — destruir no toca ruta_destino

- [x] 3.1 En `CanalController::destroy` (línea ~268), eliminar la línea `'ruta_destino' => null` del array de update. Dejar `api_canal_id`, `link_origen`, `detalle` con NULL
- [x] 3.2 Verificar: tras hacer clic en Limpiar, `canales.ruta_destino` mantiene su valor anterior

## 4. CanalController::update — restaurar ruta_destino antes de crearCanal

- [x] 4.1 En `CanalController::update`, antes del bloque `if ($canal->link_origen && !$canal->api_canal_id)`, agregar helper que consulta `DB::table('grabador_usuario')->where('grabador_id',...)->where('user_id',...)->value('ruta_base')`
- [x] 4.2 Si la consulta devuelve ruta_base y `$canal->ruta_destino` está vacío, setear `$canal->ruta_destino = rtrim($rutaBase,'/').'/'.$canal->slot_nombre` y `$canal->save()`
- [x] 4.3 Verificar: editar un canal limpiado, agregar `link_origen`, leer `canales.ruta_destino` tiene valor correcto

## 5. CanalController::store — derivar ruta_destino del pivote

- [x] 5.1 En `CanalController::store` (línea ~116), antes de `Canal::create`, consultar `DB::table('grabador_usuario')` para obtener `ruta_base` del par `(grabador_id, usuario_id)`
- [x] 5.2 Incluir `ruta_destino` en el array de `Canal::create([...])` cuando `ruta_base` exista, calculando `<ruta_base>/<slot_nombre>`
- [x] 5.3 Verificar: crear un canal individual desde el formulario, leer `canales.ruta_destino` tiene la ruta esperada

## 6. TcloudApiService::crearCanal — defensa en profundidad

- [x] 6.1 En `TcloudApiService::crearCanal` (línea ~11), antes de construir el payload, evaluar `$canal->ruta_destino`; si está vacío, consultar `DB::table('grabador_usuario')->...->value('ruta_base')`
- [x] 6.2 Si pivote devuelve ruta_base, usar `<ruta_base>/<slot_nombre>` en `ruta_descarga`
- [x] 6.3 Solo si pivote también devuelve NULL, caer al `generarRutaDescarga()` existente y emitir `\Log::warning(...)` con `['canal_id' => $canal->id, 'motivo' => 'sin_ruta_local_ni_pivote']`
- [x] 6.4 Verificar: con un canal sin `ruta_destino` y con pivote poblado, `POST /canales` recibe `ruta_descarga = pivote + slot_nombre`

## 7. Script de remediación para los 4 canales rotos

- [x] 7.1 Crear `app/app/Console/Commands/RemediarCanalesSinRuta.php` con signature `canales:remediar-ruta {--dry-run}`
- [x] 7.2 El comando selecciona canales con `ruta_destino IS NULL` y `api_canal_id IS NOT NULL`, agrupados por `(grabador_id, usuario_id)`
- [x] 7.3 Para cada par: si el pivote tiene `ruta_base`, setear `local.ruta_destino = ruta_base/<slot_nombre>` y luego `PUT /canales/{api_canal_id}` con `{ruta_descarga: ruta_base/<slot_nombre>}` usando el `Http` facade
- [x] 7.4 Reportar conteo de canales remediados vs fallidos; salir con código 0 si todos OK, 1 si hubo fallos
- [x] 7.5 Dry-run sobre los 4 canales de `siglohallon` y verificar preview antes de ejecutar real
- [x] 7.6 Ejecución real contra `siglohallon` → verificar que tras correr, los 4 canales tienen `ruta_destino` poblado y el remoto tiene `ruta_descarga` correcto en `/www/.../Multimedia/<Puntual_0X>`

## 8. Verificación end-to-end

- [x] 8.1 Limpiar un canal con `ruta_destino` poblado, luego re-agregar `link_origen` desde el formulario de edición. Confirmar que `ruta_destino` local se preserva y que el remoto recibe el valor correcto (curl `GET /canales/{id}`)
- [x] 8.2 Crear un canal individual desde el formulario `Crear Canal` con un usuario que tenga pivote poblado. Confirmar que el nuevo canal hereda la ruta_base
- [x] 8.3 Asignar un usuario nuevo con `ruta_base`. Confirmar que (a) los 10 canales se crean con la ruta correcta y (b) el pivote guarda `ruta_base`
- [x] 8.4 Re-asignar el mismo usuario cambiando `ruta_base`. Confirmar que pivote se actualiza y los 10 canales se reescriben
- [x] 8.5 Inspeccionar logs: ningún `warning` de `TcloudApiService::crearCanal` durante el flujo normal con pivote poblado
