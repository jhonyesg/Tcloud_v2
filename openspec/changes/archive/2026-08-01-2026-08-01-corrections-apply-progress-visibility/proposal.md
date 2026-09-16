# Change: Visibilidad real de progreso en Re-aplicar correcciones

## Why

El botón **Re-aplicar** de `/ia/correcciones` lanza una corrida retroactiva que puede tardar de minutos a horas, pero el admin queda completamente ciego durante toda la operación. Reporte real del admin (2026-08-01): *"llevo esperando 27 minutos y no veo que avance o inicie o haga algo"*.

La investigación en vivo sobre el servidor confirmó que la corrida **sí procesa** (proceso `corrections:apply-run` activo, campo `progress` avanzando en Redis), pero la UI muestra **0% y "0 / 214,396 segmentos" durante toda la corrida**. Cadena de causas raíz:

1. **La barra lee el campo equivocado.** El JS calcula `pct = updated/total`, pero `CorrectionsApplyRunCommand` solo escribe `updated` **al final** de la corrida. Durante la corrida solo actualiza `progress` y `total`.
2. **El campo `progress` no es un conteo.** Guarda el `last()->id` del chunkById (un ID de ~11.8M en una tabla con gaps), incomparable contra `total=214,396`. No existe ningún contador de "segmentos procesados".
3. **Recargar la página deja ciego al admin.** El proceso sobrevive (setsid, independiente de PHP-FPM) pero el `runId` vive solo en memoria de Alpine. No hay re-attach ni listado de corrida activa. Consecuencia: el admin relanza y genera **corridas duplicadas en paralelo** (evidencia: dos corridas el 2026-08-01, 06:35 y 06:52) — no existe lock anti-duplicados.
4. **Una corrida muerta nunca se detecta.** Hay una corrida en Redis que quedó en `queued` para siempre (el proceso nunca arrancó o murió al instante). No hay heartbeat; el estado queda `running`/`queued` hasta que expira el TTL de 4h. El `runStuckTimer` del JS está declarado pero jamás se instancia (código muerto).
5. **Rendimiento innecesariamente lento (agravante).** `CorrectionService::applyText()` reconstruye y re-ordena (`array_map` + `usort`) el array de pares de correcciones **por cada segmento** — el comentario del código afirma "convertimos UNA vez al inicio" pero se ejecuta por segmento. Con 214k segmentos × N correcciones, esto es gran parte de los ~30 min.

Adicional: el log destinado a diagnóstico (`/tmp/kilo_artisan_apply.log`) queda siempre vacío (0 bytes) porque el wrapper `execBackground` agrega un segundo redirect (`> /tmp/kilo_artisan_bg.log`) que pisa al primero — diagnóstico confuso.

## What Changes

### Backend: progreso real por chunk (`CorrectionsApplyRunCommand` + `CorrectionService`)

- `CorrectionService::applyRetroactively()` pasa al callback un **contador acumulado de procesados** (`fn($processed, $total, $updatedSoFar)`) en vez del lastId crudo. Mantiene el lastId solo como dato diagnóstico.
- El comando escribe en cache **por cada chunk**: `processed` (conteo real), `updated` (parcial acumulado, no solo al final), `total`, `last_progress_at` (heartbeat ISO8601).
- El estado final `done`/`error` se sigue escribiendo igual (contrato existente con la UI).

### Anti-duplicados + re-attach (`CorreccionesController`)

- Nueva cache key fija `corrections_apply:active` que apunta al `runId` vigente (TTL = TTL del run).
- `applyRetroactive()` responde **409 Conflict** con el `runId` vigente si ya existe una corrida en `queued`/`running`, en vez de lanzar una paralela.
- Nuevo endpoint `GET /ia/correcciones/apply-retroactive-active` que retorna la corrida activa (o 204 si no hay) para que la UI se re-adjunte al cargar la página.
- Fix menor: quitar el redirect duplicado a `/tmp/kilo_artisan_apply.log` en el comando lanzado (el wrapper ya redirige a `kilo_artisan_bg.log`), para que el log de diagnóstico tenga contenido real.

### UI: barra honesta + re-attach + detección de estancado (`index.blade.php`)

- La barra y el texto usan `processed/total` (campo correcto): `"48,500 / 214,396 segmentos (22%)"`.
- Al cargar la página, consultar la corrida activa y **re-adjuntar polling automáticamente** mostrando la barra (banner persistente en el módulo, no solo dentro del modal).
- Textos de estado en español (`En cola…`, `Procesando…`, `Terminada`, `Falló`) en vez del status crudo en inglés.
- **Stuck detection real**: si `last_progress_at` lleva > 3 min sin moverse con status `running`, mostrar aviso "la corrida parece detenida" con la hora del último avance (reemplaza el `runStuckTimer` muerto).
- Si el POST responde 409, re-adjuntar a la corrida vigente en vez de mostrar error.

### Rendimiento: hoist de pares de correcciones

- `applyRetroactively()` convierte la colección de correcciones a pares primitivos ordenados **una sola vez** antes del loop de chunks y los pasa a `applyText()` (nueva firma interna o variante que acepta pares pre-procesados). Comportamiento idéntico, menos CPU por segmento.

## Non-goals

- **No se migra a Laravel Queue**: el mecanismo setsid + cache polling ya funciona y es el patrón usado por los demás procesos async del módulo (mining, ai-suggest). Migrar a jobs/colas es un cambio mayor fuera de scope.
- **No hay cancelación de corridas**: el admin no puede matar una corrida desde la UI (requeriría IPC/signals; posible follow-up).
- **No se cambia la semántica de aplicación**: qué correcciones aplican, orden por longitud, transacciones por chunk e incremento de `applies_count` quedan idénticos.
- **No se toca el flujo de correcciones nuevas** (aprobar/rechazar/bulk/undo): solo la corrida retroactiva.
- **No se persiste historial de corridas en BD**: el estado vive en cache con TTL de 4h como hoy; un log histórico permanente sería otro change.

## Impact

- **Specs affected**: `transcription-corrections` (1 MODIFIED requirement + 3 ADDED).
- **Code affected (modificados)**:
  - `app/app/Services/Ia/CorrectionService.php` (`applyRetroactively()` callback con conteo acumulado; hoist de pares en el loop)
  - `app/app/Console/Commands/CorrectionsApplyRunCommand.php` (escritura de `processed`/`updated` parcial/`last_progress_at` por chunk; puntero `corrections_apply:active`; limpieza al terminar)
  - `app/app/Http/Controllers/Ia/CorreccionesController.php` (409 anti-duplicado; endpoint de corrida activa; fix del redirect)
  - `app/resources/views/ia/correcciones/index.blade.php` (barra con `processed`, re-attach, banner de corrida activa, textos ES, stuck detection)
  - `app/routes/web.php` (`+GET /correcciones/apply-retroactive-active`)
- **Migrations**: ninguna.
- **Riesgos**: bajo. El contrato cache actual (`status`, `total`, `updated`, `progress`) se extiende con campos nuevos, no se rompe; una UI vieja contra backend nuevo degradaría a mostrar 0% pero no falla (y se despliegan juntos). El 409 cambia el comportamiento de doble-click: antes lanzaba duplicado silencioso, ahora re-adjunta.

## Open questions (resueltas)

- **¿Cola vs setsid?** Setsid se queda: probado en producción hoy (la corrida inspeccionada está viva y procesando). El problema nunca fue el mecanismo de fondo sino el reporte de progreso.
- **¿Umbral de stuck?** 3 minutos sin cambio de `last_progress_at`: un chunk tarda segundos; 3 min es holgado sin ser eterno.
- **¿Re-attach dónde?** Banner en el módulo (visible aunque el modal esté cerrado) + re-apertura del estado dentro del modal si el admin lo abre.
- **¿Perf fix en este change?** Sí: es de 5 líneas (hoist) y reduce directamente el tiempo que el admin mira la barra.
