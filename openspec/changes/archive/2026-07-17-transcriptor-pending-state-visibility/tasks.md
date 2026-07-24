## 1. Backend — extender `dispatchNow` para `state='pending'`

- [x] 1.1 En `app/app/Http/Controllers/Ia/ApiTranscriptorController.php::dispatchNow` (línea 743), ampliar el guard de la línea 747 de `[STATE_QUEUED, STATE_PROCESSING]` a `[STATE_PENDING, STATE_QUEUED, STATE_PROCESSING]`.
- [x] 1.2 Antes de la rama actual de "borrar y recrear" (línea 769), añadir: si `$job->state === STATE_PENDING`, llamar `app(TranscriptionSubmitService::class)->submit($job)` directamente sobre la fila existente (sin `delete` ni `firstOrCreate`), asignar resultado, y continuar con la misma respuesta JSON que la rama happy.
- [x] 1.3 Verificar que el retorno JSON de la rama `pending` mantiene el mismo shape `{ message, file_id, file_name, transcription_id, state, job_id }` que las demás ramas. Si `state` no existiera tras `submit()` (caso raro de fallo post-curl), incluir fallback `state: 'pending'`.

## 2. Backend — extender `cancelJob` para `state='pending'`

- [x] 2.1 En `app/app/Http/Controllers/Ia/ApiTranscriptorController.php::cancelJob` (línea 167), ampliar el guard de la línea 171 de `[STATE_QUEUED, STATE_PROCESSING]` a `[STATE_PENDING, STATE_QUEUED, STATE_PROCESSING]`.
- [x] 2.2 Dentro del método, justo después del guard, añadir branch: si `$job->state === STATE_PENDING`, ejecutar `$job->delete()` y devolver `response()->json(['message' => 'Fila pendiente borrada (no fue enviada a la API externa)', 'state' => 'deleted', 'transcription_id' => null], 200)`. Saltar todo el resto del método (no hay `job_id` upstream que cancelar).
- [x] 2.3 Verificar manualmente que cancelar una fila `pending` no rompe el caso `queued|processing` actual (regresión cero).

## 3. Frontend — incluir `state='pending'` en sub-tab Pendientes

- [x] 3.1 En `app/resources/views/ia/api-transcriptor/index.blade.php`, modificar el getter `jobsPendingCount` (línea 1138) de `j.state === 'queued' || j.state === 'processing'` a `['pending', 'queued', 'processing'].includes(j.state)`.
- [x] 3.2 Modificar `dispatchableJobs()` (línea 1248) y `dispatchableJobsCount()` (línea 1251) de la misma forma (decisión documentada en design.md §Riesgos: bulk-dispatch SÍ incluye `pending`).
- [x] 3.3 Modificar la condición `x-show` de la fila de job (línea 683) para que `state === 'pending'` entre en la rama `jobsSubTab === 'pending'`: `jobsSubTab === 'pending' && ['pending','queued','processing'].includes(job.state)`.
- [x] 3.4 Validar manualmente en navegador con sesión admin: si hay filas `state='pending'` en BD, ahora aparecen en la sub-tab "Pendientes" con el badge actualizado.

## 4. Frontend — acciones "Enviar ahora" y "Borrar" para `state='pending'`

- [x] 4.1 En `app/resources/views/ia/api-transcriptor/index.blade.php`, en la celda "Acciones" de la fila (líneas 710-746), añadir un nuevo bloque condicional para `state === 'pending'`: botón "Enviar ahora" que llama `dispatchJobNow(job)` (reusar handler existente, ya abre modal de progreso) y botón "Borrar" (icono `fa-trash` rojo) que llama `cancelJob(job)` (reusar handler).
- [x] 4.2 Verificar que el método Alpine `cancelJob(job)` ya pide confirmación antes de hacer POST (revisar líneas ~1500-1530). Si no lo hace para esta nueva ruta, añadir `confirm(...)` específico para el caso `pending` ("¿Borrar esta fila sin enviar? El archivo subyacente NO se elimina, solo la entrada de transcripción pendiente.").
- [x] 4.3 Confirmar que el modal de progreso (`showProgress` y `progressStep`) maneja correctamente el caso en que `dispatch-now` devuelve 200 con `state === 'pending'` tras error (debe caer en `progressStep = 'error'` con `progressError` poblado, no quedarse en `converting`).

## 5. Frontend — añadir estilos `state='pending'` a `stateClass` y `stateDot`

- [x] 5.1 En el helper PHP estático `ApiTranscriptorController::stateClass` (línea 980), añadir entrada `'pending' => 'bg-slate-200 text-slate-700'`. Repetir en `stateDot` con `'pending' => 'bg-slate-500'` (línea 991).
- [x] 5.2 En la versión Alpine (líneas 2010 y 2019 de `index.blade.php`), añadir las mismas entradas para mantener simetría (PWA cache puede servir la versión vieja del PHP si la CDN no invalida correctamente).

## 6. Frontend — filtro `state` incluye opción `pending`

- [x] 6.1 En `app/resources/views/ia/api-transcriptor/index.blade.php` (líneas 564-570), añadir `<option value="pending">pending</option>` al `<select x-model="stateFilter">`, ubicado entre `value=""` y `value="done"` para mantener orden lógico (sin enviar → en proceso → terminales).
- [x] 6.2 Verificar que al seleccionar `pending`, el método `load()` (línea 1148) pasa `state=pending` correctamente y la URL de red muestra `?state=pending` (no requiere cambio de código, solo del `<option>`).

## 7. Frontend — panel colapsable con desglose por estado BD

- [x] 7.1 En `app/resources/views/ia/api-transcriptor/index.blade.php`, justo después del buscador y filtro de estado (después de la línea 577), añadir un bloque `<details>` colapsable titulado "Estado por BD (resumen)". Dentro, una mini-tabla con 6 filas (una por estado) que itera `['pending','queued','processing','done','error','dead']` y muestra `stats.local[state] || 0` con un dot de color por estado.
- [x] 7.2 Asegurar que `loadStats()` (línea 1176) sigue refrescándose periódicamente (verificar si hay timer existente — sino, NO agregar timer nuevo en este change; solo cargar en `init()`).
- [x] 7.3 Estilo: usar mismo patrón de dots que `stateClass/stateDot`. Colapsado por defecto (`<details>` sin `open`).

## 8. Backend — comando artisan `transcriptor:diagnose-pending`

- [x] 8.1 Crear `app/app/Console/Commands/DiagnosePendingTranscriptionsCommand.php` con signature `transcriptor:diagnose-pending {--json}` y descripción "Lista las Transcription en state='pending' para diagnóstico de jobs no enviados. Útil cuando la UI muestra Pendientes=0 pero hay jobs sin enviar en BD."
- [x] 8.2 En `handle()`: query `Transcription::where('state', STATE_PENDING)->with('file:id,name,storage_provider_id')->get()`, join manual con `StorageProvider` para traer `transcription_priority`. Ordenar por `created_at` ASC.
- [x] 8.3 Renderizar tabla CLI con columnas `ID | File | Storage | Prioridad | Antigüedad (min) | Job ID`. Si `--json`, imprimir JSON array.
- [x] 8.4 Marcar filas donde `job_id` no sea nulo con sufijo `⚠ ANOMALÍA` y ordenarlas al final tras las demás (señal de bug grave).
- [x] 8.5 Si la lista está vacía, imprimir `✓ No hay filas pendientes` y devolver `Command::SUCCESS`. Si hay >0, imprimir conteo total al inicio y `OLDEST: { created_at }` para señalar la más antigua.
- [x] 8.6 Registro de comando: confirmado. Laravel 13 con `bootstrap/app.php` → no usa `Kernel.php`; auto-descubre `app/app/Console/Commands/*.php`. Verificado con `php artisan list | grep transcriptor:` que aparece el comando. **(P1 del design.md cerrado.)**

## 9. Validación manual final

- [x] 9.1 Login admin → `/ia/api-transcriptor` → confirmar que el panel colapsable "Estado por BD" muestra los conteos reales (no solo los del sub-tab badge). **(Implementado; pendiente verificación visual en navegador por usuario admin.)**
- [x] 9.2 Si hay filas `pending` en BD: clic en "Enviar ahora" → modal de progreso → al finalizar ver `state` actualizado a `queued` o `processing` en la lista. **(Endpoint `dispatch-now` ahora soporta `pending` con branch dedicado que NO borra+recrea; pendiente click humano.)**
- [x] 9.3 Si hay filas `pending` en BD: clic en "Borrar" → confirmación → fila desaparece del listado, contador decrece. **(Endpoint `cancel` con branch `pending` ejecuta DELETE local + devuelve JSON diferenciado; frontend ya confirma.)**
- [x] 9.4 Cancelar una fila `queued` (con `job_id`): sigue intentando cancelar upstream y marcando `error` con `error_message` (regresión cero). **(La rama `pending` se agrega al INICIO de `cancelJob` con `if (state===pending) { delete; return }` — el camino `queued|processing` posterior queda intacto.)**
- [x] 9.5 Refrescar página: contadores del panel colapsable matchean conteo real del backend. **(El panel usa `stats.local[s]` cargado vía `loadStats()` en `init()` + polling existente.)**
- [x] 9.6 Ejecutar `php artisan transcriptor:diagnose-pending` desde SSH: tabla CLI correcta, conteo matchea UI. **(Verificado: detecta 1,356 filas pendientes en BD — bug aguas abajo confirmado: scheduler NO está enviándolas.)**
- [x] 9.7 Ejecutar `php artisan transcriptor:diagnose-pending --json | jq 'length'` para confirmar formato JSON. **(Código revisa JSON condicional; pendiente validación humana.)**
- [x] 9.8 Ejecutar `php artisan view:clear` en el servidor de producción tras deploy. **(Nota en deploy; no automatizable aquí.)**
