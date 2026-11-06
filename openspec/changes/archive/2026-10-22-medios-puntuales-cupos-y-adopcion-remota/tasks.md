## 1. Baseline y reproducible

- [x] 1.1 Reproducir el guard roto con un script de lectura: cargar `User::find(11)`, `grabadores()->where('grabador_id',1)->first()` y demostrar que `->limite_canales` es `null` mientras `->pivot->limite_canales` es `5`. Guardar la salida como evidencia en el PR.
- [x] 1.2 Confirmar el estado de datos de partida: `grabador_usuario` con `(1,11)=5`, `(1,9)=10`, `(3,11)=10`; `canales` con 4/10/10 filas; remoto con `id=202 Solicitudes_05` sin fila local.
- [x] 1.3 Verificar que ninguna vista de `resources/views/grabaciones_puntuales/` renderiza `session('error'|'success'|'warning')` (grep documentado), para justificar el partial nuevo.

## 2. Servicio de cupo (backend)

- [x] 2.1 Crear `app/app/Services/GrabacionesPuntuales/CupoCanalesService.php` con `limite(int $grabadorId, int $userId): ?int`, `uso(int $grabadorId, int $userId): int`, `resumen(int $grabadorId, int $userId): ?array` y `puedeCrear(int $grabadorId, int $userId, bool $isAdmin): bool`. Una sola query de pivote + una de conteo.
- [x] 2.2 Añadir `lockForUpdate` opcional vía `resumenBloqueado()` para uso dentro de transacción.
- [x] 2.3 Escribir `app/tests/Unit/CupoCanalesServiceTest.php` con casos: pivote existente, pivote inexistente (`null`), admin bypass, `limite=0` (tratado como sin asignación, no ilimitado).

## 3. Guard de creación (backend)

- [x] 3.1 Refactorizar `CanalController::store` (líneas 81-135) para envolver el chequeo y el `INSERT` en `DB::transaction()` con `lockForUpdate` sobre la fila `grabador_usuario`.
- [x] 3.2 Sustituir `$acceso->limite_canales` por `CupoCanalesService::resumen()`; cuando retorne `null`, responder con `error` "No tienes acceso a este grabador" (nunca comparar contra nulo).
- [x] 3.3 Mantener el mensaje de rechazo existente pero enriquecerlo con `usados` y `limite` ("Has alcanzado tu límite de 5 canales (5 en uso)").
- [x] 3.4 Preservar `ruta_destino` derivada del pivote (comportamiento de la spec `grabaciones-puntuales`), reutilizando `ruta_base` del `resumen()` en lugar de una consulta extra.
- [x] 3.5 Refactorizar `CanalController::create` (líneas 48-79) para usar `CupoCanalesService::resumen()` por grabador, eliminando el cálculo inline de `disponibles`.

## 4. Feedback visible (frontend)

- [x] 4.1 Crear `app/resources/views/partials/flash-toast.blade.php`: renderiza `session('success'|'warning'|'error')` como toast con auto-dismiss (4 s), paleta verde/ámbar/rojo, sin dependencia de Alpine.
- [x] 4.2 Incluir el partial en `grabaciones_puntuales/canales/index.blade.php` (reemplaza el toast manual solo para flash; el toast JS de AJAX se conserva).
- [x] 4.3 Incluir el partial en `grabaciones_puntuales/canales/create.blade.php` y `edit.blade.php`; añadir `value="{{ old('slot_nombre') }}"` y `selected` por `old('grabador_id')`.
- [x] 4.4 Incluir el partial en `grabaciones_puntuales/grabadores/index.blade.php` para el modal AJAX de asignación.
- [x] 4.5 Verificar que el toast no tapa el header sticky ni se duplica con `mostrarToast` (ids distintos).

## 5. Asignación y baja de límite (backend)

- [x] 5.1 En `GrabadorController::asignarUsuario` (líneas 139-215): validar `limite_canales` (integer 1..100, default 10) y usarlo en el `insert` del pivote y en el bucle de provisión de slots.
- [x] 5.2 Añadir `withPivot(['limite_canales', 'ruta_base'])` en `User::grabadores()` (línea 132) y `Grabador::usuarios()` (línea 31).
- [x] 5.3 Refactorizar `GrabadorController::actualizarAsignacion` (líneas 224-276): escribir el pivote sin borrar; devolver `warning` con `usados`, `limite` y la lista de `slot_nombre` excedentes.
- [x] 5.4 Implementar la rama `prune=true` con `DB::transaction()`: por cada excedente, `eliminarCanal` remoto y solo si responde `success` borrar la fila local; abortar la transacción completa ante el primer fallo remoto.
- [x] 5.5 Actualizar `grabadores/index.blade.php`: el panel de edición muestra el exceso (`5 canales, límite 4`) y un botón "Eliminar excedentes" que confirma enumerando los `slot_nombre`; enviar `prune=true` solo desde ese botón.
- [x] 5.6 Corregir `getRutaBase()` (línea 239) para leer `u.pivot.ruta_base` en lugar de derivarla de `canales`.

## 6. Detección y adopción remota (backend)

- [x] 6.1 Crear `app/app/Services/GrabacionesPuntuales/AdopcionRemotaService.php` con `detectar(int $grabadorId): array` que devuelve `vinculados`, `ya_ok`, `sin_match`, `remotos_detectados` y `fuera_de_alcance`.
- [x] 6.2 Implementar la normalización de identidad: `codigo`/`nombre`/`slot_nombre` comparados con `trim` + `mb_strtolower`.
- [x] 6.3 Implementar `clasificar(array $remoto, array $pares): string` con la regla estricta (`hijo directo de ruta_base` + `prefijo` compartido) y el fallback `sugerido`/`fuera_de_alcance`.
- [x] 6.4 Implementar `adoptar(int $grabadorId, int $userId, int $apiCanalId): array` con re-verificación `GET /canales/{id}`, `INSERT` espejo e idempotencia por las UNIQUE existentes.
- [x] 6.5 Resolver `formato_salida`/`ffmpeg_args_*` nulos desde `grabadores.tipo` y loguear `canales.adopcion` con el diff aplicado.
- [x] 6.6 Refactorizar `CanalController::sincronizar` (líneas 400-460) para consumir `AdopcionRemotaService::detectar()` y devolver los cinco cubos en el JSON, sin perder los campos actuales (`success`, `actualizados`, `sin_match`, `message`).
- [x] 6.7 Añadir `POST /grabaciones-puntuales/canales/adoptar` (body: `grabador_id`, `user_id`, `api_canal_ids[]`), admin-only, que devuelva adoptados y omitidos.
- [x] 6.8 Garantizar que la adopción NO consulta `CupoCanalesService` como guard: nunca se rechaza por cupo. Devolver por cada par tocado `usados`, `limite` y `excedido` para que la UI lo pinte en rojo.

## 7. Detección y adopción remota (frontend)

- [x] 7.1 En `canales/index.blade.php`, tras sincronizar, mostrar en el toast el conteo de `remotos_detectados` y abrir el modal de adopción si es > 0.
- [x] 7.2 Crear el modal de adopción: tabla con `id` remoto, `codigo`, par propuesto, `confianza` y checkbox; los `sugerido` en sección aparte con aviso; sin "seleccionar todo".
- [x] 7.3 Enviar `POST /canales/adoptar` con los seleccionados, refrescar el listado y mostrar el resumen (adoptados / omitidos / errores).
- [x] 7.4 Añadir en `grabadores/index.blade.php` el panel de cupos por usuario: `usados/limite`, barra de ocupación, contador de detectados sin registrar y botón "Adoptar detectados" que reutiliza el modal.

## 8. Comando CLI

- [x] 8.1 Crear `app/app/Console/Commands/CanalesDetectarRemotosCommand.php` con signature `canales:detectar-remotos {--grabador=} {--user=} {--json} {--adoptar}` (dry-run por defecto).
- [x] 8.2 Implementar la tabla de salida (id, codigo, ruta, par propuesto, confianza) y la salida `--json` con los cinco cubos.
- [x] 8.3 Bloquear la adopción de `sugerido` en modo `--adoptar` (solo `adoptable`), registrando cuántos quedaron pendientes.

## 9. Limpieza colateral

- [x] 9.1 Eliminar la tarjeta duplicada "Medios Puntuales" en `dashboard/user.blade.php` (líneas 74-98), conservando una sola.

## 10. Verificación

- [x] 10.1 Crear `app/tests/harness_canales_cupo.php` (tag `hmcc_<8hex>`, cleanup en `finally`): (a) `limite=5, usados=4` → crea; (b) `limite=5, usados=5` → rechaza con mensaje; (c) `limite=10, usados=0` → crea; (d) pivote inexistente → rechazo explícito; (e) admin → crea con `usados >= limite`; (f) `resumen()` devuelve `disponibles` coherente con el guard de `store()`.
- [x] 10.2 Crear `app/tests/harness_canales_adopcion.php` (tag `hmad_<8hex>`): (a) detección de un remoto simulado sin fila local; (b) clasificación `adoptable` para `Solicitudes_05`; (c) clasificación `sugerido` para `Telemedellin` y para ruta anidada `RD1/...`; (d) adopción crea una sola fila; (e) adopción repetida → `ya_ok`; (f) adopción no emite escrituras al remoto (mock de `Http::fake`); (g) `formato_salida` nulo se resuelve por tipo.
- [ ] 10.3 (operador, prod) Prueba manual end-to-end: subir el límite de 4 a 5 en Grabadores, entrar como el usuario, crear el 5.º canal y ver el toast verde; verificarlo en `/canales`.
- [ ] 10.4 (operador, prod) Prueba manual de adopción real: sincronizar, adoptar `Solicitudes_05` (id 202), confirmar que aparece en el listado del admin y del usuario, y que el par `(1,11)` pasa a `5/5`.
- [x] 10.5 Verificar que los 11 remotos de la regla laxa (`Telemedellin`, `Trece`, `CaracolInternacional`, `Congreso`, etc.) NO se adoptan automáticamente y siguen apareciendo como `sugerido`.
- [x] 10.6 `php artisan canales:detectar-remotos --json` debe reportar 129 remotos, 12 hijos directos y exactamente 1 `adoptable`.
- [x] 10.7 Regresión: correr `php artisan canales:detectar-remotos` (dry-run) dos veces y confirmar que el conteo de `canales` no cambia.
- [x] 10.8 Confirmar que `php tests/harness_dashboard_partials.php` sigue en verde tras tocar `dashboard/user.blade.php` (o documentar que no aplica por no ser partial).
