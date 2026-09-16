# Design — Modo catch-up

## Context

- El modal de progreso ya encadena tandas de 50 acumulando (avisos-scan-configuration). El `selectCandidates` ya omite la ventana cuando hay `from` explícito (design del change anterior, D2) — pero "sin from y sin ventana" no está soportado (el filtro de ventana es incondicional cuando no hay from/to).
- Pendientes: 333,692 (verificado hoy). Ritmo medido: ~50 transcripciones / 4s por tanda.

## Decisions

### D1 — Flag `noWindow` en la costura del escaneo

`selectCandidates()`: el filtro de ventana (`finished_at >= now() - windowHours`) se aplica SOLO si NO existe `opts['noWindow']`. Con `noWindow=true` el drenaje abarca todo el histórico. Guardia: `run()` con `origin='cron'` NUNCA acepta `noWindow` (se ignora + log `avisos.scan.catchup_rejected`) — el catch-up es exclusivamente manual.

### D2 — UI: checkbox + estimación honesta

En "Escanear ahora": checkbox "Incluir histórico completo (sin límite de fechas)". Al activarlo, el modal muestra la estimación SIN límite (333k) con el aviso de duración (~18 días continuos al ritmo actual; puede detener y retomar cuando quiera). El bucle del modal ya acumula tandas — el catch-up solo cambia los params de cada corrida (`noWindow: true`).

El estado "cuánto falta": el modal muestra escaneadas de esta sesión; el total restante global se recalcula al abrir la sub-ventana (estimate sin límite) — no se actualiza en vivo durante el bucle (costo de COUNT por tanda evitado; el admin puede cerrar/reabrir para ver el total restante).

### D3 — CLI documentado

`avisos:scan --no-window --origin=manual --ignore-schedule` para corridas por SSH. Flag nuevo en el comando.

## Risks / Trade-offs

- [Carga sostenida] → cada tanda es 4-5s de query acotada + matching en memoria; sin impacto sobre otros servicios (mismo servidor ya corre transcripciones en paralelo). El admin controla cuándo y cuánto.
- [Catch-up activado por accidente] → requiere checkbox explícito + estimación mostrada antes de iniciar; el cron nunca lo hereda.
- [Retención de resultados] → cada tanda registra su corrida; 333k/50 = ~6,700 corridas → la tabla avisos_scan_runs crece aceptablemente y es purgable.

## Migration Plan

Sin migraciones. Implementación: servicio (noWindow + guardia cron) → comando (--no-window) → UI (checkbox + estimación total). Despliegue único.