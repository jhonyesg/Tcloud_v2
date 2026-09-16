## REMOVED Requirements

### Requirement: Endpoint `POST /ia/api-transcriptor/settings/run-tick`

**Reason**: El endpoint existía para que el operador disparara la tarea programada a demanda desde la pestaña Configuración ("Ejecutar ahora") o la ejecutara en seco ("Simular"). Ambos casos quedaron sin uso:

- El tick automático cubre el descubrimiento de "hoy" de forma continua; forzar una corrida paralela no acelera nada y no comparte candado con el scheduler (`withoutOverlapping(150)` solo protege al cron contra sí mismo).
- El modo simulacro nunca fue tal: el `--dry-run` no se propagaba a la Fase 1 de descubrimiento, así que creaba archivos y filas igual que la corrida real.
- El resultado de ambas rutas se escribía en `storage/logs/transcription-tick-manual.log`, que ningún componente de UI leía.

El trabajo manual por alcance (hoy / rango / histórico) queda cubierto por `POST /ia/api-transcriptor/scan/estimate` (solo lectura), `POST /ia/api-transcriptor/scan/run` y `GET /ia/api-transcriptor/scan/status/{runId}`, que sí tienen estimación previa, candado de concurrencia y progreso visible.

**Migration**: Ninguna acción de datos. El comando `transcription:tick` conserva su flag `--dry-run` para uso por CLI (`cd app && php artisan transcription:tick --dry-run`), que es la vía recomendada para inspeccionar el ciclo sin escribir en BD. Las corridas manuales por alcance se hacen desde el modal "Procesar históricos" de `/ia/api-transcriptor`. No hay consumidores externos del endpoint: solo lo llamaba el método Alpine `runTick()` de la propia vista, que se elimina en el mismo cambio.

#### Scenario: Intento de invocar el endpoint eliminado

- **WHEN** un cliente llama `POST /ia/api-transcriptor/settings/run-tick`
- **THEN** el sistema responde 404 (ruta inexistente)
- **AND** no se lanza ningún proceso `transcription:tick`

#### Scenario: Trabajo manual de hoy sigue disponible

- **WHEN** el admin necesita procesar grabaciones de hoy sin esperar al tick automático
- **THEN** abre el modal "Procesar históricos" con alcance "Hoy"
- **AND** obtiene una estimación de solo lectura antes de confirmar
- **AND** el lanzamiento queda serializado por `transcriptor:scan_run:lock` y reporta progreso por polling

#### Scenario: La tarjeta "Tarea programada" conserva su estado en vivo

- **WHEN** el admin abre la pestaña Configuración
- **THEN** la tarjeta "Tarea programada" sigue mostrando `tick_interval_minutes`, `tick_last_run`, la profundidad de cola, los workers PG y los conteos por estado
- **AND** el control de lanzamiento manual de esa tarjeta es únicamente "Procesar históricos"
