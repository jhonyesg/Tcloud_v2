# segment-keyword-hits-partitioning Specification

## Purpose
Permite la retención O(1) del histórico de menciones mediante particionamiento mensual de la tabla `segment_keyword_hits`, dejando la estructura preparada para activarse cuando el volumen lo justifique sin rediseñar la tabla actual.

## Requirements

### Requirement: Activación del particionamiento RANGE mensual

El sistema SHALL permitir migrar `segment_keyword_hits` de una tabla plana a una tabla particionada por `matched_at` en intervalos mensuales. La activación SHALL ocurrir mediante una migración SQL que: (1) recrea la tabla como `PARTITION BY RANGE (matched_at)`, (2) crea la partición del mes actual y una partición default, (3) conserva el `UNIQUE (transcription_id, segment_id, keyword_id, matched_at)`, (4) recrea los índices (`segment_keyword_hits_keyword_id_matched_at_index`, `segment_keyword_hits_transcription_id_index`). La activación SHALL ser opcional en este change — se entrega scaffolding (comando `avisos:ensure-month-partition` + migración preparada) pero NO se activa automáticamente porque el volumen actual (12k filas / 4.4 MB) no lo justifica.

#### Scenario: Tabla particionada recibe inserts en la partición del mes
- **WHEN** se inserta una fila en la tabla padre con `matched_at='2026-09-15'`
- **THEN** PostgreSQL dirige el INSERT a `segment_keyword_hits_2026_09` automáticamente

#### Scenario: Partición default atrapa meses futuros
- **WHEN** se inserta una fila con `matched_at='2027-01-15'` y no existe partición `segment_keyword_hits_2027_01`
- **THEN** la fila cae en la partición default (con WARNING para que el operador cree la partición)

#### Scenario: DROP PARTITION para retención es O(1)
- **WHEN** se ejecuta `DROP TABLE segment_keyword_hits_2026_06;`
- **THEN** todas las filas de junio 2026 desaparecen instantáneamente (verificado con `EXPLAIN`) sin afectar las demás particiones

#### Scenario: Comando create-partition es idempotente
- **WHEN** se ejecuta `php artisan avisos:ensure-month-partition --month=2026-10` y la partición ya existe
- **THEN** el comando termina sin error y sin DROP

### Requirement: Comando CLI para crear particiones mensuales

El sistema SHALL ofrecer `avisos:ensure-month-partition` que cree la partición de un mes dado (default: mes actual). El comando SHALL ser idempotente, SHALL aceptar `--month=YYYY-MM` (cualquier mes, pasado o futuro) y SHALL validar que la conexión a PostgreSQL esté disponible antes de tocar el catálogo.

#### Scenario: Crear partición del mes actual
- **WHEN** se ejecuta `php artisan avisos:ensure-month-partition`
- **THEN** existe `segment_keyword_hits_YYYY_MM` para el mes actual (verificado con `to_regclass`)

#### Scenario: Crear partición de un mes futuro
- **WHEN** se ejecuta `php artisan avisos:ensure-month-partition --month=2027-01`
- **THEN** la partición `segment_keyword_hits_2027_01` queda creada sin afectar otras particiones

#### Scenario: Validar conexión antes de operar
- **WHEN** el comando se ejecuta sin acceso a PostgreSQL (BD caída)
- **THEN** termina con código no-cero y mensaje claro sin dejar conexiones colgadas
