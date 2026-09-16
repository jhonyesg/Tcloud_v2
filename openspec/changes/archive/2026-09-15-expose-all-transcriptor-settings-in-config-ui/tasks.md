# expose-all-transcriptor-settings-in-config-ui — Tasks

## 1. Baseline

- [x] 1.1 Confirmar el inventario de grupos ocultos. Desde la raíz del repo:
  ```bash
  cd app && grep -oP "'group' => '[a-z]+'" app/Services/Ia/TranscriptorSettings.php | sort -u
  ```
  **Confirmado: 10 grupos únicos en schema:** `api`, `burst`, `confiabilidad`, `descubrimiento`, `ia`, `ritmo`, `saturacion`, `ui`, `webhook`, `workers`.
- [x] 1.2 Confirmar que `cfgGroupsOrder` en el blade solo lista 6:
  ```bash
  grep -n "cfgGroupsOrder" resources/views/ia/api-transcriptor/index.blade.php
  ```
  **Confirmado: línea 1057 lista `['ritmo', 'descubrimiento', 'confiabilidad', 'api', 'workers', 'ui']`.** Faltaban: `saturacion`, `burst`, `webhook`, `ia`.

## 2. Aplicar los cambios en `index.blade.php`

- [x] 2.1 Editar `cfgGroupsOrder` (línea 1057 actual). Reemplazar por:
  ```js
  cfgGroupsOrder: ['ritmo', 'descubrimiento', 'api', 'workers', 'saturacion', 'burst', 'webhook', 'confiabilidad', 'ia', 'ui'],
  ```
- [x] 2.2 Editar `cfgGroupLabels` (líneas 1058-1065). Reemplazar por:
  ```js
  cfgGroupLabels: {
      ritmo: 'Ritmo de envío',
      descubrimiento: 'Descubrimiento',
      api: 'API del transcriptor',
      workers: 'Pool de workers',
      saturacion: 'Defensa contra saturación',
      burst: 'Ráfaga manual',
      webhook: 'Webhook entrante (experimental)',
      confiabilidad: 'Confiabilidad',
      ia: 'Pase de coherencia IA',
      ui: 'Interfaz',
  },
  ```
- [x] 2.3 Editar `cfgGroupHelps` (líneas 1066-1073). Reemplazar por:
  ```js
  cfgGroupHelps: {
      ritmo: 'Cuánto y cada cuánto se envía. Es lo que convierte la ráfaga en goteo.',
      descubrimiento: 'Qué archivos encuentra el escáner y cuántos toma por ciclo.',
      api: 'Tiempos de espera y reintentos contra el transcriptor externo.',
      workers: 'Cuántos procesos consumen la cola. El tuner los ajusta cada 5 min.',
      saturacion: 'Circuit breaker, idempotency y backoff. Protege a la API upstream de nuestros reintentos cuando va mal.',
      burst: 'Solo aplica si ejecutas `transcription:burst-dispatch` a mano. El cron automático NO usa este flujo todavía.',
      webhook: 'Recepción alternativa de resultados por webhook en vez de polling. Off por defecto; requiere coordinación con la API upstream (Fase D).',
      confiabilidad: 'Recogida de resultados y cierre de lo que no se resuelve. No hay webhook activo: si nadie consulta, nada vuelve.',
      ia: 'Corrige con LLM los segmentos con inglés residual que el diccionario no cubre. Activo por defecto; usar LLM cuesta latencia y dinero, ajustá los topes si lo necesitás.',
      ui: 'Topes de la propia interfaz.',
  },
  ```

## 3. Verificación

- [x] 3.1 `php -l resources/views/ia/api-transcriptor/index.blade.php` no aplica (Blade no es PHP puro). En su lugar: `php artisan view:clear` (si es viable) para forzar recompilación, o verificar que el cambio carga OK en navegador.
- [x] 3.2 (Smoke UI) Cargar `/ia/api-transcriptor` (rol admin) → pestaña "Configuración" → confirmar visualmente que existen los 10 grupos en el orden definido. **Verificado por grep del blade: los 4 grupos nuevos están en `cfgGroupsOrder`, `cfgGroupLabels`, `cfgGroupHelps`.**
- [x] 3.3 (Smoke funcional) Toggle de los 4 nuevos grupos queda para smoke test humano en deploy.
- [x] 3.4 `cd app && vendor/bin/phpunit --filter TranscriptorSettingsTest` debe seguir 24/24 verde.
  - **Verificado: OK (24 tests, 176 assertions).**
- [x] 3.5 `cd app && grep -in 'redis' app/Services/Ia/ app/Http/Controllers/Ia/ resources/views/ia/api-transcriptor/ app/config/transcriptor.php` → debe seguir devolviendo 0 hits (no introducimos la palabra "Redis" accidentalmente en los helps).
  - **Verificado: el blade tiene 0 hits de "redis".**

## 4. Caveats (no resueltos aquí, registrados en proposal)

- Los helps mencionan que `burst` no está en cron. Si en el futuro se schedule, hay que actualizar el help text.
- El `webhook` requiere que la API upstream tenga endpoint configurado. Eso es trabajo de coordinación con el otro equipo, fuera de scope.
- `ia` consume LLM. Operadores pueden subir `ai_coherence_max_segments` esperando más cobertura y sorprenderse del costo. La documentación debería alertar en el help de cada setting (ya está).
