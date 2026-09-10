## Context

Hoy hay dos módulos con jobs en background que comparten un mismo problema de UX:
- `/ia/avisos-inteligentes`: el `checkActiveScanRun()` en `init()` de Alpine fuerza `activeTab = 'escaneo'` y abre el modal `scanModal` automáticamente al detectar un `avisos_scan_bg:active` en cache.
- `/ia/api-transcriptor`: el botón "Escanear storages" solo abre el modal tras click explícito (bien), pero si el operador cierra accidentalmente el modal durante un batch, no hay forma de recuperarlo sin recargar y volverlo a abrir.

El endpoint actual `/ia/avisos-inteligentes/scan/run-bg/active` solo expone el job de avisos. No hay forma de consultar todos los jobs activos desde un punto único. El layout `app/resources/views/layouts/app.blade.php` no tiene infraestructura para mostrar estado de procesos en background. Hoy los jobs viven en cache de Redis bajo claves específicas por módulo (`avisos_scan_bg:active`, `transcription_batch:{runId}`), con TTL de 2h. Los workers (`tcloud-transcription-batch-*`, `tcloud-corrections-apply-*`) corren bajo supervisord separados del servidor web.

## Goals / Non-Goals

**Goals:**
- Un único endpoint agrega todos los jobs activos de todos los módulos. El layout consulta una vez cada 5s.
- El widget se monta UNA vez en el layout; no se replica en cada módulo.
- Los módulos dejan de auto-abrir sus modales al cargar la página. Siguen attachando polling local si ya están en la página cuando hay un job activo, pero NO fuerzan nada si el operador recarga o navega.
- El indicador global es la única superficie "always visible" del estado de jobs.
- Backward-compatible: módulos que aún no estén registrados en el `BgJobRegistry` siguen funcionando como hoy (no se rompen).

**Non-Goals:**
- No se rediseña el modal de detalle de cada módulo (sigue siendo el existente).
- No se implementa WebSockets ni SSE — el polling cada 5s es suficiente.
- No se migra el storage de jobs de Redis a PostgreSQL ni se introduce una tabla nueva.
- No se agrega la capacidad de iniciar jobs desde el widget.
- No se cambian los TTL de las cache keys existentes (cada módulo sigue siendo dueño del suyo).
- No se rediseñan los layouts responsivos — el widget es solo desktop-first.

## Decisions

### 1. Registry con discover() en lugar de un controller que conoce todos los módulos

**Decisión**: `BgJobRegistry` es un servicio con un método `discover(): array`. Internamente tiene un array estático de "scanners" (callables), uno por módulo conocido. Cada scanner recibe el cache facade y devuelve 0 o más jobs normalizados. Agregar un nuevo módulo es agregar una línea al array.

```php
class BgJobRegistry {
    private array $scanners = [
        'avisos-scan' => [AvisosScanJobScanner::class, 'scan'],
        'transcriptor-batch' => [TranscriptorBatchJobScanner::class, 'scan'],
    ];
    public function discover(): array {
        $jobs = [];
        foreach ($this->scanners as $kind => [$cls, $method]) {
            foreach ($cls::$method() as $job) {
                $jobs[] = $job;
            }
        }
        return $jobs;
    }
}
```

**Rationale**: Open/Closed — agregar un módulo nuevo es agregar una clase `XxxJobScanner` con un método estático `scan(): array` y una línea en el array. Sin tocar el registry ni el controller.

**Alternativa descartada**: que cada controller se auto-registre en boot vía service provider. Descartado porque introduce orden de carga y conflictos con service discovery. Más simple tener una lista explícita.

**Alternativa descartada**: que `BgJobsController` consulte cada cache key directamente. Descartado porque acopla el controller a las claves internas de cada módulo.

### 2. Widget flotante con Alpine store global, no en cada módulo

**Decisión**: El widget vive en `app/resources/views/components/bg-job-indicator.blade.php` y se incluye una sola vez en `layouts/app.blade.php`. Usa `Alpine.store('bgJobs')` para exponer el estado a cualquier módulo que quiera leerlo (ej: para attachar su propio polling si el operador navega a la página del módulo).

```html
<div x-data x-init="Alpine.store('bgJobs').startPolling()" class="fixed bottom-4 right-4 z-40 ...">
  <template x-for="job in $store.bgJobs.jobs" :key="job.runId">
    <div class="bg-white rounded-2xl shadow-xl p-3 mb-2 w-80">
      <div class="flex items-center justify-between">
        <span class="text-xs font-semibold" x-text="job.module"></span>
        <button @click="$store.bgJobs.dismiss(job.runId)">×</button>
      </div>
      <p class="text-sm" x-text="job.label"></p>
      <div class="h-1 bg-slate-200 rounded mt-2 overflow-hidden">
        <div class="h-full bg-brand-500" :style="`width: ${$store.bgJobs.progressPct(job)}%`"></div>
      </div>
      <a :href="job.url" class="text-xs text-brand-600 hover:underline mt-2 block">Ver detalles →</a>
    </div>
  </template>
</div>
```

**Rationale**: Single source of truth. Si el operador navega a la página del módulo, el módulo lee `$store.bgJobs.jobs` para saber si hay un job activo (en vez de pegarle al endpoint de nuevo). El polling se pausa si la pestaña no está visible (`document.visibilityState !== 'visible'`).

**Alternativa descartada**: hacer que cada módulo renderice su propia card del widget en su blade. Descartado porque entonces la card desaparece al navegar a otro módulo, lo cual es exactamente lo que el usuario quiere evitar.

### 3. `?focus=bg-{kind}-{runId}` como convención de deep-link al detalle

**Decisión**: Cuando el operador hace click en "Ver detalles" del widget, navega a `{module.url}?focus=bg-{kind}-{runId}`. El módulo, en su `init()`, lee `URLSearchParams` y si encuentra ese focus, abre su modal con `phase: 'running'` apuntando al runId correspondiente. Sin focus → el módulo NO abre el modal aunque haya un job activo.

**Rationale**: Mantiene el módulo en control de su propio modal (sabe cómo abrirlo, qué parámetros pasar, etc.). El widget solo conoce la URL, no la lógica interna. La convención `bg-{kind}-{runId}` es fácil de parsear y de generar.

**Alternativa descartada**: que el widget envíe un mensaje postMessage o dispare un evento global que el módulo escuche. Descartado porque la página del módulo puede no estar cargada todavía cuando el usuario hace click (es una navegación). Una URL es stateless y correcta.

**Alternativa descartada**: que el widget incluya el modal completo en sí mismo (sin navegar al módulo). Descartado porque los modales de detalle son específicos de cada módulo y tienen state distinto (botón "Detener", "Cancelar", etc.) — duplicarlos en el widget es propenso a drift.

### 4. Polling adaptativo: 5s cuando hay tabs visibles con jobs, 0s cuando no hay

**Decisión**: El store `Alpine.store('bgJobs')` mantiene un `setInterval` con id `pollTimer`. Cada 5s ejecuta `fetch /bg-jobs/active`. Si la respuesta trae `{jobs: []}` dos veces seguidas, el timer se pausa. Se reanuda en `visibilitychange` (cuando la pestaña vuelve a tener foco) o en cualquier interacción (`click`, `keydown`).

**Rationale**: Polling infinito cuando no hay nada que mostrar es desperdicio de bandwidth y CPU del PHP-FPM. Pero pausar agresivamente (ej: cuando hay 0 jobs) puede hacer que el operador que lanza un scan desde el módulo no vea la card inmediatamente — el `visibilitychange` lo resuelve cuando la pestaña vuelve a foco.

**Alternativa descartada**: long-polling con `fetch` que cuelga hasta que hay cambios. Descartado porque PHP-FPM no maneja long-polling bien (los workers se agotan con conexiones colgadas). 5s de polling es trivial.

**Alternativa descartada**: WebSockets con Laravel Reverb o Pusher. Descartado por scope (sería un change nuevo, no este). El polling es suficiente para nuestro caso de uso.

### 5. Módulos siguen attachando su propio polling local cuando el operador ya está en su página

**Decisión**: El módulo de avisos, en su `init()`, sigue llamando `checkActiveScanRun()` para attachar el polling local del modal (cuando el operador hace click explícito en "Ver detalles" y entra a la página). PERO ese polling local NO abre el modal ni cambia la pestaña — solo actualiza `scanModal.scanned`, `scanModal.hitsNew`, etc. cuando el modal ya está abierto en `phase: 'running'`.

Lo mismo aplica al transcriptor: si el operador entra a `/ia/api-transcriptor?focus=bg-transcriptor-batch-{runId}`, el módulo lee el focus, abre el modal y attacha el polling local del batch.

**Rationale**: El polling local del módulo sigue siendo útil para update inmediato de la UI cuando el modal está abierto. El polling global del widget es para saber que hay un job activo SIN abrir el modal. Son complementarios.

**Alternativa descartada**: eliminar el polling local de cada módulo y que solo el widget global pollee. Descartado porque cuando el modal está abierto, queremos updates instantáneos (no esperar 5s para ver un hit nuevo), y el polling local puede ser cada 2s.

## Risks / Trade-offs

- **[El widget compite visualmente con otros elementos bottom-right]** → Mitigación: usar `z-40` (no `z-50` que es para modales). Si hay conflicto con toasts o banners, se ajustan en una iteración visual posterior.
- **[Si hay 5+ jobs activos el widget puede saturar la esquina]** → Mitigación: el widget muestra los primeros 3 con un "+N más" colapsable. Listado completo al expandir.
- **[Dos pollers activos simultáneamente cuando el operador está en la página del módulo]** → Mitigación: aceptable. El polling local del módulo es cada 2s y solo se ejecuta cuando el modal está abierto. El global es cada 5s. Total: ~1 req/1.7s en el peor caso. Trivial.
- **[El cambio de comportamiento en avisos puede romper flujos que dependían del auto-open]** → Mitigación: el modal sigue siendo accesible vía "Escanear ahora" (que ahora abre en `confirm`, no en `running`) y vía `?focus=`. Documentar en AGENTS.md el cambio de comportamiento.
- **[El endpoint `/bg-jobs/active` lee múltiples cache keys, latencia podría ser notable]** → Mitigación: usar `Cache::many([...keys])` para batch en una sola round-trip a Redis. Probar perf con 10 jobs activos (peor caso realista).
- **[El polling global podría quedar colgado si el endpoint se cuelga]** → Mitigación: `fetch` con `AbortController` y timeout de 8s. Si falla, se loguea en consola pero el polling sigue.
- **[Si un módulo nuevo no se registra, el widget no muestra sus jobs]** → Mitigación: documentar en AGENTS.md cómo agregar un scanner nuevo al `BgJobRegistry`. Tests unitarios verifican que los scanners existentes están registrados.

## Migration Plan

1. Merge del feature branch.
2. Reload de PHP-FPM (`nginx -s reload && systemctl reload php84-php-fpm`). NO requiere reinicio de workers supervisord.
3. NO requiere migración de BD.
4. Verificación post-deploy:
   - Sin jobs activos → widget no se renderiza (verificar con DevTools que `<div x-data ... bgJobs>` no aparece en el DOM)
   - Lanzar scan de avisos desde el módulo → widget aparece en bottom-right, actualiza cada 5s
   - Click "Ver detalles" del widget → navega al módulo y abre el modal en `phase: 'running'`
   - Cerrar el modal manualmente → widget sigue visible
   - Recargar `/dashboard` (no es la página del módulo) con un scan activo → widget aparece en `/dashboard`
5. Rollback: `git revert` + reload de PHP-FPM. Riesgo bajo: el nuevo endpoint y widget no afectan los flujos existentes.

## Open Questions

Ninguna. La elección entre polling local + global simultáneos se resolvió en Decisions §5.
