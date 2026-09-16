# Tasks — Modo catch-up

## 1. Servicio y comando

- [x] 1.1 AvisosScanService: soporte `noWindow` en selectCandidates/estimate (omite filtro de ventana); run() con origin=cron ignora noWindow con log avisos.scan.catchup_rejected
- [x] 1.2 ScanMentionsCommand: flag `--no-window` (solo tiene efecto con --ignore-schedule)
- [x] 1.3 Prueba aislada: candidatos con noWindow abarcan transcripciones fuera de la ventana normal; cron rechaza noWindow

## 2. Sub-ventana

- [x] 2.1 Checkbox "Incluir histórico completo (sin límite de fechas)" en "Escanear ahora"; al activarlo, estimate sin límite y aviso de duración/detener-retomar en el modal
- [x] 2.2 El bucle del modal pasa noWindow cuando el checkbox está activo

## 3. Validación

- [x] 3.1 E2E: catch-up arrancado desde lo más antiguo (verificar orden ASC), detener, retomar sin duplicados
- [x] 3.2 openspec validate --strict; archivar con el usuario