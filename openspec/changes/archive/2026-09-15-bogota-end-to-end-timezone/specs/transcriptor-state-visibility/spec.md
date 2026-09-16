# Delta: transcriptor-state-visibility

## ADDED Requirements

### Requirement: Contador "Pendientes (live)" cita zona horaria Bogota

El sistema SHALL indicar, en el header de la columna "Pendientes (live)" de la tabla de Storages del módulo API Transcriptor, que la métrica está calculada en zona horaria `America/Bogota`. La indicación puede ser un sufijo en el título de la columna (ej. `Pendientes (live) · Bogota`), un `title` attribute en el `<th>`, o un tooltip adyacente.

#### Scenario: Operador inspecciona el header
- **WHEN** el operador pasa el mouse sobre el header `Pendientes (live)` de la tabla de Storages
- **THEN** un tooltip muestra "Conteo de archivos con transcripción no-done, calculados en zona America/Bogota"

#### Scenario: Tooltip es estable entre cargas
- **WHEN** el operador navega entre páginas de la tabla
- **THEN** el tooltip se mantiene igual (no fluctúa con cambios de cache TTL)
