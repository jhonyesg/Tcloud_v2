# avisos-scan-coverage-audit Specification

## Purpose
Persiste en una tabla append-only toda acción administrativa o automática que cambie la cobertura de escaneo del módulo de avisos, permitiendo responder "¿quién/quándo/sobre qué?" con una sola query indexada.

## Requirements

### Requirement: Cada acción de mutación sobre watermarks queda registrada con actor

El sistema SHALL escribir en `watermark_audit_log` una fila por cada acción que modifique `keyword_scan_watermarks` desde un endpoint administrativo o desde un hook automático. Cada fila SHALL incluir `actor_user_id` (extraído de `session('user_id')` para acciones de admin, NULL para automáticas), `action` (valor enumerado: `rewind_pair`, `full_scan`, `hook_auto`, `reconcile`), `keyword_id`, `storage_id`, `before_value` y `after_value` (timestamps), `metadata` (JSONB con contexto: request payload, motivo del hook), `created_at`. La tabla SHALL ser append-only: la API no expone ni permite UPDATE ni DELETE. Filas con `created_at` mayor a la política de retención vigente SHALL poderse mover (no borrar) a `watermark_audit_log_archive` mediante `avisos:archive-audit-log --days=N` para mantener acotado el tamaño del log activo.

#### Scenario: Rewind manual registra actor
- **WHEN** el admin `jsuarez` (user_id=5) ejecuta `POST /scan/rewind` con `keyword_id=42, storage_id=7`
- **THEN** existe una fila en `watermark_audit_log` con `actor_user_id=5`, `action='rewind_pair'`, `keyword_id=42`, `storage_id=7`, `before_value=<timestamp actual>`, `after_value=NULL`, `metadata={"reason":"admin","ip":"...","ua":"..."}`

#### Scenario: Hook automático registra actor NULL
- **WHEN** el hook `UserAlertsInteligente::saved` crea un watermark
- **THEN** existe una fila en `watermark_audit_log` con `actor_user_id=NULL`, `action='hook_auto'`, `metadata={"trigger":"UserAlertsInteligente.saved","user_id":X}`

#### Scenario: Append-only estructural
- **WHEN** un auditor (rol admin) ejecuta `DELETE FROM watermark_audit_log WHERE id=?`
- **THEN** la operación falla porque no hay rol "auditor" con DELETE sobre la tabla — el modelo de acceso por convención del proyecto no expone UPDATE/DELETE (verificado)

#### Scenario: Archivado mueve filas sin perder auditoría
- **WHEN** el admin ejecuta `avisos:archive-audit-log --days=90` y hay 5000 filas con `created_at` anterior al umbral
- **THEN** las 5000 filas se mueven a `watermark_audit_log_archive` con `archived_at = now()`, el log activo queda con las filas recientes, y ninguna fila se borra realmente (preservación para compliance)

### Requirement: Índices para consultas típicas de auditoría

La tabla `watermark_audit_log` SHALL tener un índice compuesto `(keyword_id, storage_id, created_at DESC)` para responder "¿quién movió este par?" y otro `(actor_user_id, created_at DESC)` para "¿qué acciones hizo este usuario?". La tabla `watermark_audit_log_archive` SHALL tener los mismos índices para responder sobre histórico.

#### Scenario: Consulta "¿quién movió este par a NULL?"
- **WHEN** se ejecuta `SELECT * FROM watermark_audit_log WHERE keyword_id=? AND storage_id=? AND after_value IS NULL ORDER BY created_at DESC LIMIT 10`
- **THEN** el plan usa `wal_ks_time_idx` (verificado con EXPLAIN), tiempo < 10 ms sobre el dataset actual

#### Scenario: Consulta "¿qué acciones hizo el admin X en el último mes?"
- **WHEN** se ejecuta `SELECT * FROM watermark_audit_log WHERE actor_user_id=? AND created_at >= now() - interval '30 days' ORDER BY created_at DESC`
- **THEN** el plan usa `wal_actor_time_idx`, retorna todas las acciones del admin ordenadas cronológicamente

#### Scenario: Consulta sobre histórico archivado
- **WHEN** se ejecuta `SELECT * FROM watermark_audit_log_archive WHERE actor_user_id=? AND created_at < '2026-06-01'`
- **THEN** el plan usa `wal_archive_actor_time_idx` y retorna las filas archivadas meses atrás
